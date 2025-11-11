<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use Psr\Log\LoggerInterface;

/**
 * Service to map Mautic Email Template to BpMessage email format
 */
class EmailTemplateMessageMapper
{
    private LoggerInterface $logger;
    private EntityManagerInterface $em;
    private MailHelper $mailHelper;

    public function __construct(
        LoggerInterface $logger,
        EntityManagerInterface $entityManager,
        MailHelper $mailHelper
    ) {
        $this->logger = $logger;
        $this->em = $entityManager;
        $this->mailHelper = $mailHelper;
    }

    /**
     * Map a Lead to BpMessage email format using a template
     *
     * @param Lead $lead
     * @param array $config Action configuration
     * @param Campaign $campaign
     * @param BpMessageLot|null $lot Optional lot to get book_business_foreign_id from
     * @return array BpMessage email data
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function mapLeadToEmail(Lead $lead, array $config, Campaign $campaign, ?BpMessageLot $lot = null): array
    {
        // Get email template
        $templateId = is_array($config['email_template']) ? reset($config['email_template']) : $config['email_template'];

        if (empty($templateId)) {
            throw new \InvalidArgumentException('Email template is required');
        }

        /** @var Email|null $emailTemplate */
        $emailTemplate = $this->em->getRepository(Email::class)->find($templateId);

        if (!$emailTemplate) {
            throw new \InvalidArgumentException("Email template #{$templateId} not found");
        }

        // Get contact values for token replacement
        $contactValues = $this->getContactValues($lead);

        // Get raw subject and body from template
        $rawSubject = $emailTemplate->getSubject();
        $rawBody = $emailTemplate->getCustomHtml();

        // If no custom HTML, try to get from template content
        if (empty($rawBody)) {
            $rawBody = $emailTemplate->getContent();
        }

        // Apply token replacement manually using TokenHelper
        $subject = rawurldecode(TokenHelper::findLeadTokens($rawSubject, $contactValues, true));
        $body = rawurldecode(TokenHelper::findLeadTokens($rawBody, $contactValues, true));

        // Build base email message
        $email = [
            'control' => $config['control'] ?? true,
            'from' => $this->getFromAddress($emailTemplate, $config, $contactValues),
            'to' => $this->getToAddress($lead, $config, $contactValues),
            'subject' => $subject,
            'body' => $body,
        ];

        // Get book_business_foreign_id from lot (if available) or config
        $bookBusinessForeignId = null;
        if ($lot && $lot->getBookBusinessForeignId()) {
            $bookBusinessForeignId = $lot->getBookBusinessForeignId();
        } elseif (!empty($config['book_business_foreign_id'])) {
            $bookBusinessForeignId = $config['book_business_foreign_id'];
        }

        // Only add idForeignBookBusiness if it's provided and not empty
        if (!empty($bookBusinessForeignId)) {
            $email['idForeignBookBusiness'] = $bookBusinessForeignId;
        }

        // Process additional_data and merge into email (contract, cpfCnpjReceiver, etc)
        $additionalData = $this->processAdditionalData($lead, $config);
        if (!empty($additionalData)) {
            $email = array_merge($email, $additionalData);
        }

        // Process email_variables
        $emailVariables = $this->processEmailVariables($lead, $config);
        if (!empty($emailVariables)) {
            $email['variables'] = $emailVariables;
        }

        return $email;
    }

    /**
     * Get from address (from template or override)
     *
     * @param Email $emailTemplate
     * @param array $config
     * @param array $contactValues
     * @return string
     */
    private function getFromAddress(Email $emailTemplate, array $config, array $contactValues): string
    {
        // Use override if provided
        if (!empty($config['email_from'])) {
            return rawurldecode(TokenHelper::findLeadTokens($config['email_from'], $contactValues, true));
        }

        // Use template default
        $from = $emailTemplate->getFromAddress();
        if (!empty($from)) {
            return $from;
        }

        // Fallback to system default
        return $this->mailHelper->getSystemFrom() ?? 'noreply@mautic.local';
    }

    /**
     * Get to address (from lead or override)
     *
     * @param Lead $lead
     * @param array $config
     * @param array $contactValues
     * @return string
     */
    private function getToAddress(Lead $lead, array $config, array $contactValues): string
    {
        // Use override if provided
        if (!empty($config['email_to'])) {
            $to = rawurldecode(TokenHelper::findLeadTokens($config['email_to'], $contactValues, true));
            if (!empty($to)) {
                return $to;
            }
        }

        // Use lead email
        $email = $lead->getEmail();
        if (empty($email)) {
            throw new \InvalidArgumentException("Lead #{$lead->getId()} has no email address");
        }

        return $email;
    }

    /**
     * Process additional_data field with contact token replacement
     *
     * @param Lead $lead
     * @param array $config
     * @return array Processed key-value pairs
     */
    private function processAdditionalData(Lead $lead, array $config): array
    {
        if (empty($config['additional_data'])) {
            return [];
        }

        $contactValues = $this->getContactValues($lead);
        $processedData = [];

        $data = $config['additional_data'];
        if (isset($data['list'])) {
            // Old format compatibility
            foreach ($data['list'] as $item) {
                if (!isset($item['label']) || !isset($item['value'])) {
                    continue;
                }
                $key = $item['label'];
                $value = $item['value'];
                $processedValue = rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true));

                // Only add if value is not empty
                if (!empty($processedValue)) {
                    $processedData[$key] = $processedValue;
                }
            }
        } else {
            // New format: direct key => value
            foreach ($data as $key => $value) {
                $processedValue = rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true));

                // Only add if value is not empty
                if (!empty($processedValue)) {
                    $processedData[$key] = $processedValue;
                }
            }
        }

        return $processedData;
    }

    /**
     * Process email_variables field with contact token replacement
     * Returns array in format: [{"key": "string", "value": {}}]
     *
     * @param Lead $lead
     * @param array $config
     * @return array Array of key-value objects
     */
    private function processEmailVariables(Lead $lead, array $config): array
    {
        if (empty($config['email_variables'])) {
            return [];
        }

        $contactValues = $this->getContactValues($lead);
        $variables = [];

        $data = $config['email_variables'];
        if (isset($data['list'])) {
            // Old format compatibility
            foreach ($data['list'] as $item) {
                if (!isset($item['label']) || !isset($item['value'])) {
                    continue;
                }
                $variables[] = [
                    'key' => $item['label'],
                    'value' => rawurldecode(TokenHelper::findLeadTokens($item['value'], $contactValues, true)),
                ];
            }
        } else {
            // New format: direct key => value
            foreach ($data as $key => $value) {
                $variables[] = [
                    'key' => $key,
                    'value' => rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true)),
                ];
            }
        }

        return $variables;
    }

    /**
     * Get contact values for token replacement
     *
     * @param Lead $lead
     * @return array
     */
    private function getContactValues(Lead $lead): array
    {
        // Try to get fields from Lead (for when loaded via LeadModel)
        $fields = $lead->getFields(true);
        $contactValues = [];

        // Extract field values from getFields() if available
        foreach ($fields as $fieldAlias => $fieldData) {
            if (isset($fieldData['value'])) {
                $contactValues[$fieldAlias] = $fieldData['value'];
            }
        }

        // If getFields() returned empty, try to get values directly from entity
        // This handles cases where Lead is loaded directly from repository
        if (empty($contactValues)) {
            // Use reflection to get all properties
            $reflection = new \ReflectionClass($lead);
            $properties = $reflection->getProperties();

            foreach ($properties as $property) {
                $propertyName = $property->getName();

                // Skip internal/protected fields
                if (in_array($propertyName, ['id', 'changes', 'dateAdded', 'dateModified', 'createdBy', 'modifiedBy', 'checkedOut', 'checkedOutBy', 'fields', 'updatedFields', 'eventData'])) {
                    continue;
                }

                // Try to get value via getter method
                $getter = 'get' . ucfirst($propertyName);
                if (method_exists($lead, $getter)) {
                    try {
                        // Only call getter if it doesn't require parameters
                        $method = new \ReflectionMethod($lead, $getter);
                        if ($method->getNumberOfRequiredParameters() === 0) {
                            $value = $lead->$getter();
                            if ($value !== null && !is_object($value) && !is_array($value)) {
                                $contactValues[$propertyName] = $value;
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip if there's an error
                        continue;
                    }
                }
            }
        }

        // Ensure basic fields are always present
        $contactValues['id'] = $lead->getId();

        if ($lead->getEmail()) {
            $contactValues['email'] = $lead->getEmail();
        }
        if ($lead->getFirstname()) {
            $contactValues['firstname'] = $lead->getFirstname();
        }
        if ($lead->getLastname()) {
            $contactValues['lastname'] = $lead->getLastname();
        }

        return $contactValues;
    }

    /**
     * Process lot_data field with contact token replacement
     *
     * @param Lead $lead
     * @param array $config
     * @return array Processed key-value pairs
     */
    public function processLotData(Lead $lead, array $config): array
    {
        if (empty($config['lot_data'])) {
            return [];
        }

        $contactValues = $this->getContactValues($lead);
        $processedData = [];

        $data = $config['lot_data'];
        if (isset($data['list'])) {
            // Old format compatibility
            foreach ($data['list'] as $item) {
                if (!isset($item['label']) || !isset($item['value'])) {
                    continue;
                }
                $key = $item['label'];
                $value = $item['value'];
                $processedValue = rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true));

                // Only add if value is not empty
                if (!empty($processedValue)) {
                    $processedData[$key] = $processedValue;
                }
            }
        } else {
            // New format: direct key => value
            foreach ($data as $key => $value) {
                $processedValue = rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true));

                // Only add if value is not empty
                if (!empty($processedValue)) {
                    $processedData[$key] = $processedValue;
                }
            }
        }

        return $processedData;
    }

    /**
     * Validate that a lead has all required fields for BpMessage email template
     *
     * @param Lead $lead
     * @param array $config
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateLead(Lead $lead, array $config): array
    {
        $errors = [];

        // Check if template is selected
        if (empty($config['email_template'])) {
            $errors[] = 'Email template is required';
        }

        // Check if lead has email (unless overridden)
        if (empty($config['email_to'])) {
            $email = $lead->getEmail();
            if (empty($email)) {
                $errors[] = 'Lead email address is required';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
