<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Mautic\LeadBundle\Entity\Lead;

/**
 * Normalizes a Mautic contact custom-field value into a flat list of scalar values.
 *
 * Collection / multiselect custom fields are stored as a JSON string in the
 * `leads` table. Depending on the Mautic version, Lead::getFieldValue() may
 * return that value either as the raw JSON string (older behavior) or as an
 * already-decoded PHP array (Mautic 6+). Single-value fields return a scalar.
 *
 * Recipient extraction must behave the same regardless of how the field was
 * hydrated, so every code path that turns a contact field into an email/phone
 * goes through this helper.
 */
trait ContactFieldValueNormalizerTrait
{
    /**
     * @param mixed $fieldValue Raw value from Lead::getFieldValue()
     *
     * @return string[] Trimmed, non-empty values (empty array when there is nothing usable)
     */
    private function normalizeContactFieldValues($fieldValue): array
    {
        if (null === $fieldValue || '' === $fieldValue || [] === $fieldValue) {
            return [];
        }

        if (is_array($fieldValue)) {
            // Collection field already decoded by Mautic (e.g. Mautic 6+)
            $rawValues = $fieldValue;
        } elseif (is_string($fieldValue) && str_starts_with(trim($fieldValue), '[')) {
            // Collection field stored/returned as a JSON string (legacy)
            $decoded   = json_decode($fieldValue, true);
            $rawValues = is_array($decoded) ? $decoded : [$fieldValue];
        } else {
            // Single scalar value
            $rawValues = [$fieldValue];
        }

        $values = [];
        foreach ($rawValues as $value) {
            if (is_array($value) || is_object($value)) {
                // Defensive: skip nested/non-scalar entries
                continue;
            }

            $value = trim((string) $value);
            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Resolve the BpMessage receiver identifiers (cpfCnpjReceiver / contract) from the
     * dedicated contact-field configuration.
     *
     * Primary source: the configured "Campo de CPF/CNPJ" (cpf_cnpj_field) and
     * "Campo de Contrato" (contract_field), read from the contact. When a field is not
     * configured or the contact has no value, the key is omitted so the caller keeps the
     * "Dados Adicionais" (additional_data) fallback already present in the payload.
     *
     * Only non-empty values are returned, so it never blanks out an existing value.
     *
     * @return array{cpfCnpjReceiver?: string, contract?: string}
     */
    private function resolveReceiverIdentifiers(Lead $lead, array $config): array
    {
        $result = [];

        if (!empty($config['cpf_cnpj_field'])) {
            $cpf = preg_replace('/\D/', '', (string) $lead->getFieldValue($config['cpf_cnpj_field']));
            if ('' !== (string) $cpf) {
                $result['cpfCnpjReceiver'] = $cpf;
            }
        }

        if (!empty($config['contract_field'])) {
            $contract = trim((string) $lead->getFieldValue($config['contract_field']));
            if ('' !== $contract) {
                $result['contract'] = $contract;
            }
        }

        return $result;
    }
}
