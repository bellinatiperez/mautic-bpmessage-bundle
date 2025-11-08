<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Custom transformer that preserves label as key and value as value
 * Unlike core SortableListTransformer, this allows duplicate values
 */
class KeyValueListTransformer implements DataTransformerInterface
{
    /**
     * Transform from stored format to form format
     * Converts: ['key1' => 'value1', 'key2' => 'value2']
     * To: ['list' => [['label' => 'key1', 'value' => 'value1'], ...]]
     */
    public function transform(mixed $array): mixed
    {
        if (null === $array || !is_array($array)) {
            return ['list' => []];
        }

        $formattedArray = [];
        foreach ($array as $label => $value) {
            $formattedArray[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return ['list' => $formattedArray];
    }

    /**
     * Transform from form format to stored format
     * Converts: ['list' => [['label' => 'key1', 'value' => 'value1'], ...]]
     * To: ['key1' => 'value1', 'key2' => 'value2']
     */
    public function reverseTransform(mixed $array): mixed
    {
        if (null === $array || !isset($array['list']) || !is_array($array['list'])) {
            return [];
        }

        $pairs = [];
        foreach ($array['list'] as $pair) {
            if (!isset($pair['label'])) {
                continue;
            }

            // Use label as key (not value!)
            // This allows duplicate values but unique labels
            $pairs[$pair['label']] = $pair['value'] ?? '';
        }

        return $pairs;
    }
}
