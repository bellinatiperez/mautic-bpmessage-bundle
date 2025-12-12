<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Transforms between JSON string (database) and array (form select).
 *
 * @implements DataTransformerInterface<string|null, array<int, mixed>>
 */
class CollectionToJsonTransformer implements DataTransformerInterface
{
    public function __construct(private string $valueType = 'string')
    {
    }

    /**
     * Transforms JSON string to array (for the form select).
     *
     * @param string|array<int, mixed>|null $value
     *
     * @return array<int, mixed>
     */
    public function transform($value): array
    {
        if (empty($value)) {
            return [];
        }

        // If it's already an array
        if (is_array($value)) {
            return array_values($value);
        }

        // Try to decode JSON
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values($decoded);
            }

            // If not valid JSON, try comma or pipe separated
            if (str_contains($value, '|')) {
                return array_values(array_filter(array_map('trim', explode('|', $value))));
            }
            if (str_contains($value, ',')) {
                return array_values(array_filter(array_map('trim', explode(',', $value))));
            }

            // Single value
            return [$value];
        }

        return [];
    }

    /**
     * Transforms array from form select to JSON string (for the database).
     *
     * @param array<int, mixed>|string|null $value
     */
    public function reverseTransform($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Handle string input (from API or import)
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                // Try comma or pipe separated
                if (str_contains($value, '|')) {
                    $value = explode('|', $value);
                } elseif (str_contains($value, ',')) {
                    $value = explode(',', $value);
                } else {
                    $value = [$value];
                }
            }
        }

        if (!is_array($value)) {
            return null;
        }

        // Filter empty values and reindex
        $value = array_values(array_filter(array_map('trim', array_map('strval', $value)), fn ($v) => '' !== $v));

        if (empty($value)) {
            return null;
        }

        // Type casting based on configuration
        if ('integer' === $this->valueType) {
            $value = array_map('intval', $value);
        } elseif ('float' === $this->valueType) {
            $value = array_map('floatval', $value);
        }

        return json_encode($value);
    }
}
