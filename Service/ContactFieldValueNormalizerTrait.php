<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

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
}
