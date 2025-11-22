<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Exception;

/**
 * Exception thrown when a lot creation fails in the BpMessage API
 * This exception indicates a configuration or API error, not a lead-specific error.
 * Leads should NOT be marked as failed when this exception occurs.
 */
class LotCreationException extends \RuntimeException
{
    private ?int $lotId = null;

    public function __construct(string $message, ?int $lotId = null, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->lotId = $lotId;
    }

    /**
     * Get the local lot ID (if available).
     */
    public function getLotId(): ?int
    {
        return $this->lotId;
    }

    /**
     * Check if this is a configuration error (vs a transient API error).
     */
    public function isConfigurationError(): bool
    {
        // Configuration errors usually contain these keywords
        $configKeywords = [
            'rota padrão',
            'rota não configurada',
            'configuração',
            'not configured',
            'configuration',
        ];

        foreach ($configKeywords as $keyword) {
            if (false !== stripos($this->getMessage(), $keyword)) {
                return true;
            }
        }

        return false;
    }
}
