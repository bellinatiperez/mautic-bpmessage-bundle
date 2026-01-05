<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use Psr\Log\LoggerInterface;

/**
 * Service to map Mautic Email Template to BpMessage email format.
 */
class EmailTemplateMessageMapper
{
    private LoggerInterface $logger;
    private EntityManagerInterface $em;
    private MailHelper $mailHelper;
    private CoreParametersHelper $coreParametersHelper;

    public function __construct(
        LoggerInterface $logger,
        EntityManagerInterface $entityManager,
        MailHelper $mailHelper,
        CoreParametersHelper $coreParametersHelper,
    ) {
        $this->logger               = $logger;
        $this->em                   = $entityManager;
        $this->mailHelper           = $mailHelper;
        $this->coreParametersHelper = $coreParametersHelper;
    }

    /**
     * Map a Lead to BpMessage email format using a template.
     *
     * @param array             $config Action configuration
     * @param BpMessageLot|null $lot    Optional lot to get book_business_foreign_id from
     *
     * @return array BpMessage email data
     *
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
        $rawBody    = $emailTemplate->getCustomHtml();

        // If no custom HTML, try to get from template content
        if (empty($rawBody)) {
            $rawBody = $emailTemplate->getContent();
        }

        // Apply token replacement manually using TokenHelper
        $subject = rawurldecode(TokenHelper::findLeadTokens($rawSubject, $contactValues, true));
        $body    = rawurldecode(TokenHelper::findLeadTokens($rawBody, $contactValues, true));

        // Build base email message
        $email = [
            'control' => $config['control'] ?? true,
            'from'    => $this->getFromAddress($emailTemplate, $config, $contactValues),
            'to'      => $this->getToAddress($lead, $config, $contactValues),
            'subject' => $subject,
            'body'    => $body,
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
     * Get from address (from template or override).
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
        return $this->coreParametersHelper->get('mailer_from_email') ?? 'noreply@mautic.local';
    }

    /**
     * Get to address (from lead or override).
     *
     * Returns empty string if no email is available (caller should handle this case).
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

        // Use lead email (return empty string if not available)
        return $lead->getEmail() ?? '';
    }

    /**
     * Process additional_data field with contact token replacement.
     *
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
                $key            = $item['label'];
                $value          = $item['value'];
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
     * Returns array in format: [{"key": "string", "value": {}}].
     *
     * @return array Array of key-value objects
     */
    private function processEmailVariables(Lead $lead, array $config): array
    {
        if (empty($config['email_variables'])) {
            return [];
        }

        $contactValues = $this->getContactValues($lead);
        $variables     = [];

        $data = $config['email_variables'];
        if (isset($data['list'])) {
            // Old format compatibility
            foreach ($data['list'] as $item) {
                if (!isset($item['label']) || !isset($item['value'])) {
                    continue;
                }
                $variables[] = [
                    'key'   => $item['label'],
                    'value' => rawurldecode(TokenHelper::findLeadTokens($item['value'], $contactValues, true)),
                ];
            }
        } else {
            // New format: direct key => value
            foreach ($data as $key => $value) {
                $variables[] = [
                    'key'   => $key,
                    'value' => rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true)),
                ];
            }
        }

        return $variables;
    }

    /**
     * Get contact values for token replacement.
     */
    private function getContactValues(Lead $lead): array
    {
        // Try to get fields from Lead (for when loaded via LeadModel)
        $fields        = $lead->getFields(true);
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
                $getter = 'get'.ucfirst($propertyName);
                if (method_exists($lead, $getter)) {
                    try {
                        // Only call getter if it doesn't require parameters
                        $method = new \ReflectionMethod($lead, $getter);
                        if (0 === $method->getNumberOfRequiredParameters()) {
                            $value = $lead->$getter();
                            if (null !== $value && !is_object($value) && !is_array($value)) {
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
     * Process lot_data field with contact token replacement.
     *
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
                $key            = $item['label'];
                $value          = $item['value'];
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
     * Extract email addresses from a selected contact field.
     *
     * If the field is a collection (JSON array), returns multiple email addresses.
     * If the field is a simple string, returns a single email address.
     * When email_limit > 0 is configured, limits the number of emails returned for collection fields.
     *
     * @return array Array of email addresses (strings)
     */
    public function extractEmailsFromField(Lead $lead, array $config): array
    {
        $fieldAlias = $config['email_field'] ?? null;
        if (empty($fieldAlias)) {
            // Fallback to default email field
            $email = $lead->getEmail();
            if (!empty($email)) {
                return [$email];
            }
            $this->logger->debug('BpMessage Email Template: No email_field configured and lead has no email', [
                'lead_id' => $lead->getId(),
            ]);

            return [];
        }

        // If the field is the standard 'email' field, use getEmail()
        if ('email' === $fieldAlias) {
            $email = $lead->getEmail();
            if (!empty($email)) {
                return [$email];
            }

            return [];
        }

        $fieldValue = $lead->getFieldValue($fieldAlias);
        if (empty($fieldValue)) {
            $this->logger->debug('BpMessage Email Template: Email field is empty', [
                'lead_id'     => $lead->getId(),
                'field_alias' => $fieldAlias,
            ]);

            return [];
        }

        // Get email limit from config (0 = no limit)
        $emailLimit = (int) ($config['email_limit'] ?? 0);

        $this->logger->debug('BpMessage Email Template: Extracting emails from field', [
            'lead_id'     => $lead->getId(),
            'field_alias' => $fieldAlias,
            'field_value' => $fieldValue,
            'email_limit' => $emailLimit,
        ]);

        // Check if it's a JSON array (collection field)
        if (is_string($fieldValue) && str_starts_with(trim($fieldValue), '[')) {
            $decoded = json_decode($fieldValue, true);
            if (is_array($decoded) && !empty($decoded)) {
                $emails = [];
                foreach ($decoded as $email) {
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $emails[] = trim((string) $email);
                    }
                }

                // Apply email limit if configured (only for collection fields)
                if ($emailLimit > 0 && count($emails) > $emailLimit) {
                    $this->logger->debug('BpMessage Email Template: Applying email limit to collection', [
                        'lead_id'        => $lead->getId(),
                        'original_count' => count($emails),
                        'limit'          => $emailLimit,
                    ]);
                    $emails = array_slice($emails, 0, $emailLimit);
                }

                $this->logger->debug('BpMessage Email Template: Extracted emails from collection', [
                    'lead_id'     => $lead->getId(),
                    'email_count' => count($emails),
                ]);

                return $emails;
            }
        }

        // Single value - email_limit does not apply
        if (filter_var($fieldValue, FILTER_VALIDATE_EMAIL)) {
            return [trim((string) $fieldValue)];
        }

        return [];
    }

    /**
     * Map a Lead to BpMessage email format with a specific email address using a template.
     *
     * @param string            $emailTo Specific email address to use
     * @param array             $config  Action configuration
     * @param BpMessageLot|null $lot     Optional lot to get book_business_foreign_id from
     *
     * @return array BpMessage email data
     *
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function mapLeadToEmailWithAddress(Lead $lead, string $emailTo, array $config, Campaign $campaign, ?BpMessageLot $lot = null): array
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
        $rawBody    = $emailTemplate->getCustomHtml();

        // If no custom HTML, try to get from template content
        if (empty($rawBody)) {
            $rawBody = $emailTemplate->getContent();
        }

        // Apply token replacement manually using TokenHelper
        $subject = rawurldecode(TokenHelper::findLeadTokens($rawSubject, $contactValues, true));
        $body    = rawurldecode(TokenHelper::findLeadTokens($rawBody, $contactValues, true));

        // Build base email message
        $email = [
            'control' => $config['control'] ?? true,
            'from'    => $this->getFromAddress($emailTemplate, $config, $contactValues),
            'to'      => $emailTo,
            'subject' => $subject,
            'body'    => $body,
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
     * Validate that a lead has all required fields for BpMessage email template.
     *
     * Note: Email validation is NOT done here. Contacts without email are registered
     * in the lot queue with FAILED status (handled by BpMessageEmailTemplateModel).
     *
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateLead(Lead $lead, array $config): array
    {
        $errors = [];

        // Check if template is selected
        if (empty($config['email_template'])) {
            $errors[] = 'Email template is required';
        }

        // Note: Email validation removed - contacts without email are now registered
        // in the lot queue with FAILED status instead of failing validation

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }
}
