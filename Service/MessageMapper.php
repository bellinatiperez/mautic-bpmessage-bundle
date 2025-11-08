<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CoreBundle\Helper\AbstractFormFieldHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use Psr\Log\LoggerInterface;

/**
 * Service to map Mautic Lead data to BpMessage message format
 */
class MessageMapper
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Map a Lead to BpMessage message format
     *
     * @param Lead $lead
     * @param array $config Action configuration
     * @param Campaign $campaign
     * @return array BpMessage message data
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function mapLeadToMessage(Lead $lead, array $config, Campaign $campaign): array
    {
        // Build base message with minimal required fields
        $message = [
            'control' => $config['control'] ?? true,
            'metaData' => $this->buildMetaData($lead, $campaign),
            'idForeignBookBusiness' => "MAUTIC-{$lead->getId()}",
            'contactName' => trim(($lead->getFirstname() ?? '') . ' ' . ($lead->getLastname() ?? '')),
        ];

        // Add service type
        $serviceType = (int) ($config['service_type'] ?? 2); // Default: WhatsApp
        $message['idServiceType'] = $serviceType;

        // Add message text for SMS/WhatsApp
        if (in_array($serviceType, [1, 2])) { // SMS or WhatsApp
            $text = $config['message_text'] ?? '';
            $message['text'] = $this->processTokens($text, $lead);
        }

        // Add template for RCS
        if (3 === $serviceType) { // RCS
            if (empty($config['id_template'])) {
                throw new \InvalidArgumentException('idTemplate is required for RCS messages');
            }

            $message['idTemplate'] = $config['id_template'];
        }

        // Process additional_data and merge into message (includes contract, cpf, phone, etc)
        $additionalData = $this->processAdditionalData($lead, $config);
        if (!empty($additionalData)) {
            $message = array_merge($message, $additionalData);
        }

        // Process message_variables and add to message
        $messageVariables = $this->processMessageVariables($lead, $config);
        if (!empty($messageVariables)) {
            $message['variables'] = $messageVariables;
        }

        return $message;
    }

    /**
     * Extract phone number and area code from lead
     *
     * @param Lead $lead
     * @param array $config
     * @return array ['areaCode' => string, 'phone' => string]
     * @throws \InvalidArgumentException if phone is invalid
     */
    private function extractPhoneData(Lead $lead, array $config): array
    {
        $phoneField = $config['phone_field'] ?? 'mobile';
        $phone = $lead->getFieldValue($phoneField);

        if (empty($phone)) {
            throw new \InvalidArgumentException("Phone field '{$phoneField}' is empty for lead {$lead->getId()}");
        }

        // Remove non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Extract area code and phone
        // Brazilian format: 11987654321 (DDD + number)
        if (strlen($phone) >= 10) {
            $areaCode = substr($phone, 0, 2);
            $phoneNumber = substr($phone, 2);

            return [
                'areaCode' => $areaCode,
                'phone' => $phoneNumber,
            ];
        }

        throw new \InvalidArgumentException("Invalid phone format for lead {$lead->getId()}: {$phone}");
    }

    /**
     * Get field value from lead
     *
     * @param Lead $lead
     * @param array $config
     * @param string $configKey
     * @param bool $required
     * @return string|null
     * @throws \InvalidArgumentException if required field is missing
     */
    private function getFieldValue(Lead $lead, array $config, string $configKey, bool $required = false): ?string
    {
        if (empty($config[$configKey])) {
            if ($required) {
                throw new \InvalidArgumentException("Configuration key '{$configKey}' is required but not set");
            }
            return null;
        }

        $fieldName = $config[$configKey];
        $value = $lead->getFieldValue($fieldName);

        if ($required && empty($value)) {
            throw new \InvalidArgumentException("Required field '{$fieldName}' is empty for lead {$lead->getId()}");
        }

        return $value;
    }

    /**
     * Process tokens in text (replace {contactfield=*} with actual values)
     *
     * @param string $text
     * @param Lead $lead
     * @return string
     */
    private function processTokens(string $text, Lead $lead): string
    {
        // Replace contact field tokens: {contactfield=firstname}
        $text = preg_replace_callback(
            '/\{contactfield=([a-zA-Z0-9_]+)\}/',
            function ($matches) use ($lead) {
                $fieldName = $matches[1];
                $value = $lead->getFieldValue($fieldName);
                return $value ?? '';
            },
            $text
        );

        // Add timestamp token
        $text = str_replace('{timestamp}', (string) time(), $text);

        // Add date token
        $text = str_replace('{date_now}', date('Y-m-d H:i:s'), $text);

        return $text;
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

        // Get contact values for token replacement
        $contactValues = $this->getContactValues($lead);

        // Process from key-value format (label => value)
        $processedData = [];

        // Handle both old format (list array) and new format (associative array)
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
     * Get contact values for token replacement (similar to Webhook CampaignHelper)
     *
     * @param Lead $lead
     * @return array
     */
    private function getContactValues(Lead $lead): array
    {
        return $lead->getProfileFields();
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

        // Get contact values for token replacement
        $contactValues = $this->getContactValues($lead);

        // Process from key-value format (label => value)
        $processedData = [];

        // Handle both old format (list array) and new format (associative array)
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
     * Process message_variables field with contact token replacement
     * Returns array in format: [{"key": "string", "value": "string"}]
     *
     * @param Lead $lead
     * @param array $config
     * @return array Array of key-value objects
     */
    private function processMessageVariables(Lead $lead, array $config): array
    {
        if (empty($config['message_variables'])) {
            return [];
        }

        // Get contact values for token replacement
        $contactValues = $this->getContactValues($lead);

        // Process from key-value format (label => value)
        $variables = [];

        // Handle both old format (list array) and new format (associative array)
        $data = $config['message_variables'];
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
     * Build metadata JSON string
     *
     * @param Lead $lead
     * @param Campaign $campaign
     * @return string
     */
    private function buildMetaData(Lead $lead, Campaign $campaign): string
    {
        $metadata = [
            'source' => 'mautic',
            'campaign_id' => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
            'lead_id' => $lead->getId(),
            'lead_email' => $lead->getEmail(),
            'timestamp' => time(),
        ];

        return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Map variables for RCS templates
     *
     * @param Lead $lead
     * @param array $config
     * @return array
     */
    private function mapVariablesForRCS(Lead $lead, array $config): array
    {
        $variables = [];

        if (!empty($config['rcs_variables']) && is_array($config['rcs_variables'])) {
            $variableValues = [];

            foreach ($config['rcs_variables'] as $variable) {
                $key = $variable['key'] ?? null;
                $fieldOrValue = $variable['value'] ?? '';

                if (empty($key)) {
                    continue;
                }

                // Check if value is a token
                if (preg_match('/\{contactfield=([a-zA-Z0-9_]+)\}/', $fieldOrValue, $matches)) {
                    $fieldName = $matches[1];
                    $value = $lead->getFieldValue($fieldName);
                } else {
                    // Use as literal value
                    $value = $fieldOrValue;
                }

                $variableValues[$key] = $value ?? '';
            }

            // RCS expects format: [{"key": "variaveis_template", "value": {"Nome": "João"}}]
            if (!empty($variableValues)) {
                $variables[] = [
                    'key' => 'variaveis_template',
                    'value' => $variableValues,
                ];
            }
        }

        return $variables;
    }

    /**
     * Validate that a lead has all required fields for BpMessage
     *
     * @param Lead $lead
     * @param array $config
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateLead(Lead $lead, array $config): array
    {
        $errors = [];

        // Check service type specific requirements
        $serviceType = (int) ($config['service_type'] ?? 2);

        if (in_array($serviceType, [1, 2]) && empty($config['message_text'])) {
            $errors[] = 'Message text is required for SMS/WhatsApp';
        }

        if (3 === $serviceType && empty($config['id_template'])) {
            $errors[] = 'Template ID is required for RCS';
        }

        // Validation for required fields in additional_data will be done by API
        // This allows for more flexibility in field configuration

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
