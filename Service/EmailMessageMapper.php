<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Helper\TokenHelper;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use Psr\Log\LoggerInterface;

/**
 * Service to map Mautic Lead data to BpMessage email format.
 */
class EmailMessageMapper
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Map a Lead to BpMessage email format.
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
        // Get contact values for token replacement
        $contactValues = $this->getContactValues($lead);

        // Build base email message
        $email = [
            'control' => $config['control'] ?? true,
            'from'    => $this->processTokens($config['email_from'] ?? '', $contactValues),
            'to'      => $this->processTokens($config['email_to'] ?? $lead->getEmail(), $contactValues),
            'subject' => $this->processTokens($config['email_subject'] ?? '', $contactValues),
            'body'    => $this->processTokens($config['email_body'] ?? '', $contactValues),
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
     * Process attachments field with contact token replacement
     * Returns array in format: [{"fileURL": "string", "fileName": "string", "mimeType": "string", "password": "string"}].
     *
     * @return array Array of attachment objects
     */
    private function processAttachments(Lead $lead, array $config): array
    {
        if (empty($config['email_attachments'])) {
            return [];
        }

        $contactValues = $this->getContactValues($lead);
        $attachments   = [];

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
     * Process tokens in text (replace {contactfield=*} with actual values).
     */
    private function processTokens(string $text, array $contactValues): string
    {
        // Use TokenHelper for comprehensive token replacement
        return rawurldecode(TokenHelper::findLeadTokens($text, $contactValues, true));
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
            $this->logger->debug('BpMessage Email: No email_field configured and lead has no email', [
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
            $this->logger->debug('BpMessage Email: Email field is empty', [
                'lead_id'     => $lead->getId(),
                'field_alias' => $fieldAlias,
            ]);

            return [];
        }

        // Get email limit from config (0 = no limit)
        $emailLimit = (int) ($config['email_limit'] ?? 0);

        $this->logger->debug('BpMessage Email: Extracting emails from field', [
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
                    $this->logger->debug('BpMessage Email: Applying email limit to collection', [
                        'lead_id'        => $lead->getId(),
                        'original_count' => count($emails),
                        'limit'          => $emailLimit,
                    ]);
                    $emails = array_slice($emails, 0, $emailLimit);
                }

                $this->logger->debug('BpMessage Email: Extracted emails from collection', [
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
     * Map a Lead to BpMessage email format with a specific email address.
     *
     * @param string            $emailTo Specific email address to use
     * @param array             $config  Action configuration
     * @param BpMessageLot|null $lot     Optional lot to get book_business_foreign_id from
     *
     * @return array BpMessage email data
     */
    public function mapLeadToEmailWithAddress(Lead $lead, string $emailTo, array $config, Campaign $campaign, ?BpMessageLot $lot = null): array
    {
        // Get contact values for token replacement
        $contactValues = $this->getContactValues($lead);

        // Build base email message
        $email = [
            'control' => $config['control'] ?? true,
            'from'    => $this->processTokens($config['email_from'] ?? '', $contactValues),
            'to'      => $emailTo,
            'subject' => $this->processTokens($config['email_subject'] ?? '', $contactValues),
            'body'    => $this->processTokens($config['email_body'] ?? '', $contactValues),
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
     * Refresh email address from lead at dispatch time.
     * Returns the first valid email from the configured field.
     *
     * @return string|null Email address or null if no valid email
     */
    public function refreshEmailFromLead(Lead $lead, array $config): ?string
    {
        $emails = $this->extractEmailsFromField($lead, $config);

        return !empty($emails) ? $emails[0] : null;
    }

    /**
     * Refresh email data directly from database at dispatch time.
     *
     * This method bypasses Doctrine entity hydration and fetches the email field
     * directly from the leads table to ensure we get the current value.
     *
     * @param \Doctrine\DBAL\Connection $connection Database connection
     * @param int                       $leadId     Lead ID
     * @param array                     $config     Email config with email_field, email_limit
     *
     * @return string|null Email address or null if no valid email
     */
    public function refreshEmailFromDatabase(\Doctrine\DBAL\Connection $connection, int $leadId, array $config): ?string
    {
        $fieldAlias = $config['email_field'] ?? 'email';

        // Fetch the email field value directly from leads table
        try {
            $fieldValue = $connection->fetchOne(
                "SELECT {$fieldAlias} FROM leads WHERE id = ?",
                [$leadId]
            );
        } catch (\Exception $e) {
            $this->logger->error('BpMessage Email: Failed to fetch email from DB', [
                'lead_id'     => $leadId,
                'field_alias' => $fieldAlias,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }

        if (empty($fieldValue)) {
            $this->logger->debug('BpMessage Email: Email field is empty in DB', [
                'lead_id'     => $leadId,
                'field_alias' => $fieldAlias,
            ]);

            return null;
        }

        $this->logger->debug('BpMessage Email: Fetched email from DB', [
            'lead_id'     => $leadId,
            'field_alias' => $fieldAlias,
            'field_value' => $fieldValue,
        ]);

        // Check if it's a JSON array (collection field)
        if (is_string($fieldValue) && str_starts_with(trim($fieldValue), '[')) {
            $decoded = json_decode($fieldValue, true);
            if (is_array($decoded) && !empty($decoded)) {
                foreach ($decoded as $email) {
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->logger->debug('BpMessage Email: Email refreshed from DB (collection)', [
                            'lead_id' => $leadId,
                            'email'   => $email,
                        ]);

                        return trim((string) $email);
                    }
                }

                return null;
            }
        }

        // Single value - validate email format
        if (filter_var($fieldValue, FILTER_VALIDATE_EMAIL)) {
            $this->logger->debug('BpMessage Email: Email refreshed from DB (single)', [
                'lead_id' => $leadId,
                'email'   => $fieldValue,
            ]);

            return trim((string) $fieldValue);
        }

        return null;
    }

    /**
     * Parse an email value (already fetched from DB) and return valid email.
     *
     * This method is used for batch processing where email values are pre-fetched
     * in a single query for performance optimization.
     *
     * @param mixed $emailValue Raw email value from database (string, JSON array, or null)
     *
     * @return string|null Valid email address or null if no valid email
     */
    public function parseEmailValue($emailValue): ?string
    {
        if (empty($emailValue)) {
            return null;
        }

        // Check if it's a JSON array (collection field)
        if (is_string($emailValue) && str_starts_with(trim($emailValue), '[')) {
            $decoded = json_decode($emailValue, true);
            if (is_array($decoded) && !empty($decoded)) {
                foreach ($decoded as $email) {
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return trim((string) $email);
                    }
                }

                return null;
            }
        }

        // Single value - validate email format
        if (filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
            return trim((string) $emailValue);
        }

        return null;
    }

    /**
     * Validate that a lead has all required fields for BpMessage email.
     *
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

        // Note: Email validation removed - contacts without email are now registered
        // in the lot queue with FAILED status instead of failing validation

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }
}
