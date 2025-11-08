<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use Psr\Log\LoggerInterface;

/**
 * Service to map Mautic Lead data to BpMessage email format
 */
class EmailMessageMapper
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Map a Lead to BpMessage email format
     *
     * @param Lead $lead
     * @param array $config Action configuration
     * @param Campaign $campaign
     * @return array BpMessage email data
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function mapLeadToEmail(Lead $lead, array $config, Campaign $campaign): array
    {
        // Get contact values for token replacement
        $contactValues = $this->getContactValues($lead);

        // Build base email message
        $email = [
            'control' => $config['control'] ?? true,
            'from' => $this->processTokens($config['email_from'] ?? '', $contactValues),
            'to' => $this->processTokens($config['email_to'] ?? $lead->getEmail(), $contactValues),
            'subject' => $this->processTokens($config['email_subject'] ?? '', $contactValues),
            'body' => $this->processTokens($config['email_body'] ?? '', $contactValues),
            'idForeignBookBusiness' => "MAUTIC-{$lead->getId()}",
        ];

        // Add optional CC
        if (!empty($config['email_cc'])) {
            $email['cc'] = $this->processTokens($config['email_cc'], $contactValues);
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

        // Process attachments
        $attachments = $this->processAttachments($lead, $config);
        if (!empty($attachments)) {
            $email['attachments'] = $attachments;
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
                $processedData[$key] = rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true));
            }
        } else {
            // New format: direct key => value
            foreach ($data as $key => $value) {
                $processedData[$key] = rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true));
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
     * Process attachments field with contact token replacement
     * Returns array in format: [{"fileURL": "string", "fileName": "string", "mimeType": "string", "password": "string"}]
     *
     * @param Lead $lead
     * @param array $config
     * @return array Array of attachment objects
     */
    private function processAttachments(Lead $lead, array $config): array
    {
        if (empty($config['email_attachments'])) {
            return [];
        }

        $contactValues = $this->getContactValues($lead);
        $attachments = [];

        $data = $config['email_attachments'];
        if (isset($data['list'])) {
            // Old format compatibility
            foreach ($data['list'] as $item) {
                $attachment = [];

                if (isset($item['fileURL'])) {
                    $attachment['fileURL'] = rawurldecode(TokenHelper::findLeadTokens($item['fileURL'], $contactValues, true));
                }

                if (isset($item['fileName'])) {
                    $attachment['fileName'] = rawurldecode(TokenHelper::findLeadTokens($item['fileName'], $contactValues, true));
                }

                if (isset($item['mimeType'])) {
                    $attachment['mimeType'] = $item['mimeType'];
                }

                if (isset($item['password'])) {
                    $attachment['password'] = rawurldecode(TokenHelper::findLeadTokens($item['password'], $contactValues, true));
                }

                if (!empty($attachment)) {
                    $attachments[] = $attachment;
                }
            }
        } else {
            // New format: array of attachment objects
            foreach ($data as $attachment) {
                if (isset($attachment['fileURL'])) {
                    $attachment['fileURL'] = rawurldecode(TokenHelper::findLeadTokens($attachment['fileURL'], $contactValues, true));
                }
                if (isset($attachment['fileName'])) {
                    $attachment['fileName'] = rawurldecode(TokenHelper::findLeadTokens($attachment['fileName'], $contactValues, true));
                }
                if (isset($attachment['password'])) {
                    $attachment['password'] = rawurldecode(TokenHelper::findLeadTokens($attachment['password'], $contactValues, true));
                }
                $attachments[] = $attachment;
            }
        }

        return $attachments;
    }

    /**
     * Get contact values for token replacement
     *
     * @param Lead $lead
     * @return array
     */
    private function getContactValues(Lead $lead): array
    {
        // Get all lead fields in the correct format for TokenHelper
        $fields = $lead->getProfileFields();

        // Ensure basic fields are present
        if (!isset($fields['id'])) {
            $fields['id'] = $lead->getId();
        }
        if (!isset($fields['email']) && $lead->getEmail()) {
            $fields['email'] = $lead->getEmail();
        }
        if (!isset($fields['firstname']) && $lead->getFirstname()) {
            $fields['firstname'] = $lead->getFirstname();
        }
        if (!isset($fields['lastname']) && $lead->getLastname()) {
            $fields['lastname'] = $lead->getLastname();
        }

        return $fields;
    }

    /**
     * Process tokens in text (replace {contactfield=*} with actual values)
     *
     * @param string $text
     * @param array $contactValues
     * @return string
     */
    private function processTokens(string $text, array $contactValues): string
    {
        // Use TokenHelper for comprehensive token replacement
        return rawurldecode(TokenHelper::findLeadTokens($text, $contactValues, true));
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
                $processedData[$key] = rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true));
            }
        } else {
            // New format: direct key => value
            foreach ($data as $key => $value) {
                $processedData[$key] = rawurldecode(TokenHelper::findLeadTokens($value, $contactValues, true));
            }
        }

        return $processedData;
    }

    /**
     * Validate that a lead has all required fields for BpMessage email
     *
     * @param Lead $lead
     * @param array $config
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateLead(Lead $lead, array $config): array
    {
        $errors = [];

        // Check required fields
        if (empty($config['email_from'])) {
            $errors[] = 'Email from address is required';
        }

        if (empty($config['email_subject'])) {
            $errors[] = 'Email subject is required';
        }

        if (empty($config['email_body'])) {
            $errors[] = 'Email body is required';
        }

        // Check if lead has email
        $to = $config['email_to'] ?? $lead->getEmail();
        if (empty($to)) {
            $errors[] = 'Lead email address (to) is required';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
