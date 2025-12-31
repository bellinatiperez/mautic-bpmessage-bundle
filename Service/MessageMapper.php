<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use Psr\Log\LoggerInterface;

/**
 * Service to map Mautic Lead data to BpMessage message format.
 */
class MessageMapper
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Map a Lead to BpMessage message format.
     *
     * @param array $config Action configuration
     *
     * @return array BpMessage message data
     *
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function mapLeadToMessage(Lead $lead, array $config, Campaign $campaign): array
    {
        // Build base message with minimal required fields
        $message = [
            'control' => $config['control'] ?? true,
        ];

        // Add service type (API: 1=WhatsApp, 2=SMS, 3=Email, 4=RCS)
        $serviceType              = (int) ($config['service_type'] ?? 1); // Default: WhatsApp (1)
        $message['idServiceType'] = $serviceType;

        // Add message text for SMS/WhatsApp
        if (in_array($serviceType, [1, 2])) { // SMS or WhatsApp
            $text            = $config['message_text'] ?? '';
            $message['text'] = $this->processTokens($text, $lead);
        }

        // Add template for RCS (4) - RCS uses templates, not free text
        if (4 === $serviceType) { // RCS
            if (empty($config['id_template'])) {
                throw new \InvalidArgumentException('idTemplate is required for RCS messages');
            }

            $message['idTemplate'] = $config['id_template'];
        }

        // Process phone_pattern to extract areaCode and phone
        $phoneData = $this->processPhonePattern($lead, $config);
        if (!empty($phoneData)) {
            $message = array_merge($message, $phoneData);
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
     * Process phone_pattern field to extract areaCode and phone.
     *
     * The pattern should be like: "({contactfield=dddmobile}) {contactfield=mobile}"
     * - What's inside parentheses becomes areaCode (numbers only)
     * - What's outside parentheses becomes phone (numbers only)
     *
     * @return array ['areaCode' => string, 'phone' => string] or empty array if no pattern
     */
    private function processPhonePattern(Lead $lead, array $config): array
    {
        if (empty($config['phone_pattern'])) {
            return [];
        }

        $pattern = $config['phone_pattern'];

        // Get contact values for token replacement
        $contactValues = $this->getContactValues($lead);

        // Replace tokens with actual values
        $processedPattern = rawurldecode(TokenHelper::findLeadTokens($pattern, $contactValues, true));

        $this->logger->debug('BpMessage: Processing phone pattern', [
            'lead_id'  => $lead->getId(),
            'pattern'  => $pattern,
            'resolved' => $processedPattern,
        ]);

        // Extract area code from parentheses: (XX) -> XX
        $areaCode = '';
        if (preg_match('/\(([^)]+)\)/', $processedPattern, $matches)) {
            // Remove everything except numbers
            $areaCode = preg_replace('/[^0-9]/', '', $matches[1]);
        }

        // Extract phone - remove the parentheses part and keep only numbers from the rest
        $phoneWithoutAreaCode = preg_replace('/\([^)]*\)/', '', $processedPattern);
        $phone                = preg_replace('/[^0-9]/', '', $phoneWithoutAreaCode);

        $this->logger->debug('BpMessage: Phone pattern extracted', [
            'lead_id'  => $lead->getId(),
            'areaCode' => $areaCode,
            'phone'    => $phone,
        ]);

        // Only return if we have valid data
        if (!empty($areaCode) || !empty($phone)) {
            return [
                'areaCode' => $areaCode,
                'phone'    => $phone,
            ];
        }

        return [];
    }

    /**
     * Extract phone number and area code from lead.
     *
     * @return array ['areaCode' => string, 'phone' => string]
     *
     * @throws \InvalidArgumentException if phone is invalid
     *
     * @deprecated Use processPhonePattern instead with phone_pattern config field
     */
    private function extractPhoneData(Lead $lead, array $config): array
    {
        $phoneField = $config['phone_field'] ?? 'mobile';
        $phone      = $lead->getFieldValue($phoneField);

        if (empty($phone)) {
            throw new \InvalidArgumentException("Phone field '{$phoneField}' is empty for lead {$lead->getId()}");
        }

        // Remove non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Extract area code and phone
        // Brazilian format: 11987654321 (DDD + number)
        if (strlen($phone) >= 10) {
            $areaCode    = substr($phone, 0, 2);
            $phoneNumber = substr($phone, 2);

            return [
                'areaCode' => $areaCode,
                'phone'    => $phoneNumber,
            ];
        }

        throw new \InvalidArgumentException("Invalid phone format for lead {$lead->getId()}: {$phone}");
    }

    /**
     * Get field value from lead.
     *
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
        $value     = $lead->getFieldValue($fieldName);

        if ($required && empty($value)) {
            throw new \InvalidArgumentException("Required field '{$fieldName}' is empty for lead {$lead->getId()}");
        }

        return $value;
    }

    /**
     * Process tokens in text (replace {contactfield=*} with actual values).
     */
    private function processTokens(string $text, Lead $lead): string
    {
        // Get contact values for token replacement
        $contactValues = $this->getContactValues($lead);

        // Use TokenHelper for comprehensive token replacement (handles all field types correctly)
        $text = rawurldecode(TokenHelper::findLeadTokens($text, $contactValues, true));

        // Add timestamp token
        $text = str_replace('{timestamp}', (string) time(), $text);

        // Add date token
        $text = str_replace('{date_now}', date('Y-m-d H:i:s'), $text);

        return $text;
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
                $key                 = $item['label'];
                $value               = $item['value'];
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
     * Get contact values for token replacement (similar to Webhook CampaignHelper).
     */
    private function getContactValues(Lead $lead): array
    {
        // Get all lead fields in the correct format for TokenHelper
        $fields = $lead->getProfileFields();

        // Always ensure basic fields are present with correct values from getter methods
        // This is important because getProfileFields() might return empty/null values
        $fields['id'] = $lead->getId();

        if ($lead->getEmail()) {
            $fields['email'] = $lead->getEmail();
        }

        if ($lead->getFirstname()) {
            $fields['firstname'] = $lead->getFirstname();
        }

        if ($lead->getLastname()) {
            $fields['lastname'] = $lead->getLastname();
        }

        return $fields;
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
                $key                 = $item['label'];
                $value               = $item['value'];
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
     * Returns array in format: [{"key": "string", "value": "string"}].
     *
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
     * Build metadata JSON string.
     */
    private function buildMetaData(Lead $lead, Campaign $campaign): string
    {
        $metadata = [
            'source'        => 'mautic',
            'campaign_id'   => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
            'lead_id'       => $lead->getId(),
            'lead_email'    => $lead->getEmail(),
            'timestamp'     => time(),
        ];

        return json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Map variables for RCS templates.
     */
    private function mapVariablesForRCS(Lead $lead, array $config): array
    {
        $variables = [];

        if (!empty($config['rcs_variables']) && is_array($config['rcs_variables'])) {
            $variableValues = [];

            foreach ($config['rcs_variables'] as $variable) {
                $key          = $variable['key'] ?? null;
                $fieldOrValue = $variable['value'] ?? '';

                if (empty($key)) {
                    continue;
                }

                // Check if value is a token
                if (preg_match('/\{contactfield=([a-zA-Z0-9_]+)\}/', $fieldOrValue, $matches)) {
                    $fieldName = $matches[1];
                    $value     = $lead->getFieldValue($fieldName);
                } else {
                    // Use as literal value
                    $value = $fieldOrValue;
                }

                $variableValues[$key] = $value ?? '';
            }

            // RCS expects format: [{"key": "variaveis_template", "value": {"Nome": "João"}}]
            if (!empty($variableValues)) {
                $variables[] = [
                    'key'   => 'variaveis_template',
                    'value' => $variableValues,
                ];
            }
        }

        return $variables;
    }

    /**
     * Extract phone numbers from a selected contact field.
     *
     * If the field is a collection (JSON array), returns multiple phone entries.
     * If the field is a simple string, returns a single phone entry.
     * When phone_limit > 0 is configured, limits the number of phones returned for collection fields.
     *
     * @return array Array of ['areaCode' => string, 'phone' => string]
     */
    public function extractPhonesFromField(Lead $lead, array $config): array
    {
        $fieldAlias = $config['phone_field'] ?? null;
        if (empty($fieldAlias)) {
            $this->logger->debug('BpMessage: No phone_field configured', [
                'lead_id' => $lead->getId(),
            ]);

            return [];
        }

        $fieldValue = $lead->getFieldValue($fieldAlias);
        if (empty($fieldValue)) {
            $this->logger->debug('BpMessage: Phone field is empty', [
                'lead_id'     => $lead->getId(),
                'field_alias' => $fieldAlias,
            ]);

            return [];
        }

        // Get phone limit from config (0 = no limit)
        $phoneLimit = (int) ($config['phone_limit'] ?? 0);

        $this->logger->debug('BpMessage: Extracting phones from field', [
            'lead_id'     => $lead->getId(),
            'field_alias' => $fieldAlias,
            'field_value' => $fieldValue,
            'phone_limit' => $phoneLimit,
        ]);

        // Check if it's a JSON array (collection field)
        if (is_string($fieldValue) && str_starts_with(trim($fieldValue), '[')) {
            $decoded = json_decode($fieldValue, true);
            if (is_array($decoded) && !empty($decoded)) {
                $phones = [];
                foreach ($decoded as $phone) {
                    if (!empty($phone)) {
                        $phones[] = $this->normalizePhone((string) $phone);
                    }
                }

                // Apply phone limit if configured (only for collection fields)
                if ($phoneLimit > 0 && count($phones) > $phoneLimit) {
                    $this->logger->debug('BpMessage: Applying phone limit to collection', [
                        'lead_id'       => $lead->getId(),
                        'original_count' => count($phones),
                        'limit'         => $phoneLimit,
                    ]);
                    $phones = array_slice($phones, 0, $phoneLimit);
                }

                $this->logger->debug('BpMessage: Extracted phones from collection', [
                    'lead_id'     => $lead->getId(),
                    'phone_count' => count($phones),
                ]);

                return $phones;
            }
        }

        // Single value - phone_limit does not apply
        return [$this->normalizePhone((string) $fieldValue)];
    }

    /**
     * Normalize a phone number extracting area code and phone.
     *
     * Brazilian format: assumes first 2 digits are area code (DDD).
     *
     * @return array ['areaCode' => string, 'phone' => string]
     */
    private function normalizePhone(string $phone): array
    {
        // Remove all non-numeric characters
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // Brazilian format: 10+ digits = DDD (2) + phone (8-9)
        if (strlen($digits) >= 10) {
            return [
                'areaCode' => substr($digits, 0, 2),
                'phone'    => substr($digits, 2),
            ];
        }

        // Short number - no area code
        return [
            'areaCode' => '',
            'phone'    => $digits,
        ];
    }

    /**
     * Validate that a lead has all required fields for BpMessage.
     *
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public function validateLead(Lead $lead, array $config): array
    {
        $errors = [];

        // Check service type specific requirements (API: 1=WhatsApp, 2=SMS, 3=Email, 4=RCS)
        $serviceType = (int) ($config['service_type'] ?? 1); // Default: WhatsApp (1)

        // WhatsApp (1) and SMS (2) require message_text
        if (in_array($serviceType, [1, 2]) && empty($config['message_text'])) {
            $errors[] = 'Message text is required for SMS/WhatsApp';
        }

        // RCS (4) requires id_template
        if (4 === $serviceType && empty($config['id_template'])) {
            $errors[] = 'Template ID is required for RCS';
        }

        // Validation for required fields in additional_data will be done by API
        // This allows for more flexibility in field configuration

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }
}
