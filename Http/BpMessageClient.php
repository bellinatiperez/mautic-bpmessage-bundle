<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * HTTP Client for BpMessage API
 */
class BpMessageClient
{
    private Client $client;
    private LoggerInterface $logger;
    private string $baseUrl;

    public function __construct(LoggerInterface $logger, string $baseUrl = '')
    {
        $this->logger = $logger;
        $this->baseUrl = rtrim($baseUrl, '/');

        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
    }

    /**
     * Set base URL
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    /**
     * Create a new lot in BpMessage
     *
     * POST /api/Lot/CreateLot
     *
     * @param array $data Lot configuration
     * @return array ['success' => bool, 'idLot' => int|null, 'error' => string|null]
     */
    public function createLot(array $data): array
    {
        $endpoint = '/api/Lot/CreateLot';

        $this->logger->info('BpMessage: Creating lot', [
            'endpoint' => $endpoint,
            'name' => $data['name'] ?? 'unknown',
        ]);

        $this->logger->debug('BpMessage HTTP Request', [
            'method' => 'POST',
            'url' => $this->baseUrl . $endpoint,
            'headers' => $this->getHeaders(),
            'payload' => $data,
        ]);

        try {
            $response = $this->client->post($this->baseUrl . $endpoint, [
                'headers' => $this->getHeaders(),
                'json' => $data,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $this->logger->info('BpMessage: CreateLot response', [
                'status_code' => $statusCode,
                'body' => $body,
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                // BpMessage returns the lot ID directly or in a response object
                $result = json_decode($body, true);

                // Handle different response formats
                $idLot = null;
                if (is_numeric($result)) {
                    $idLot = (int) $result;
                } elseif (is_array($result) && isset($result['id'])) {
                    $idLot = (int) $result['id'];
                } elseif (is_array($result) && isset($result['idLot'])) {
                    $idLot = (int) $result['idLot'];
                }

                if (null !== $idLot) {
                    return [
                        'success' => true,
                        'idLot' => $idLot,
                        'error' => null,
                    ];
                }

                return [
                    'success' => false,
                    'idLot' => null,
                    'error' => 'Invalid response format: ' . $body,
                ];
            }

            return [
                'success' => false,
                'idLot' => null,
                'error' => "HTTP {$statusCode}: {$body}",
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('BpMessage: CreateLot failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'idLot' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Add messages to an existing lot
     *
     * POST /api/Lot/AddMessageToLot/{idLot}
     *
     * @param int $idLot Lot ID
     * @param array $messages Array of messages (up to 5000)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function addMessagesToLot(int $idLot, array $messages): array
    {
        $endpoint = "/api/Lot/AddMessageToLot/{$idLot}";

        $this->logger->info('BpMessage: Adding messages to lot', [
            'endpoint' => $endpoint,
            'idLot' => $idLot,
            'message_count' => count($messages),
        ]);

        $this->logger->debug('BpMessage HTTP Request', [
            'method' => 'POST',
            'url' => $this->baseUrl . $endpoint,
            'headers' => $this->getHeaders(),
            'payload_count' => count($messages),
            'first_message_sample' => !empty($messages) ? $messages[0] : null,
        ]);

        try {
            $response = $this->client->post($this->baseUrl . $endpoint, [
                'headers' => $this->getHeaders(),
                'json' => $messages,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $this->logger->info('BpMessage: AddMessageToLot response', [
                'status_code' => $statusCode,
                'body' => $body,
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($body, true);

                // BpMessage returns boolean or success indicator
                $success = false;
                if (is_bool($result)) {
                    $success = $result;
                } elseif (is_array($result) && isset($result['success'])) {
                    $success = (bool) $result['success'];
                } elseif ($statusCode === 200 || $statusCode === 201) {
                    $success = true;
                }

                return [
                    'success' => $success,
                    'error' => $success ? null : 'API returned false',
                ];
            }

            return [
                'success' => false,
                'error' => "HTTP {$statusCode}: {$body}",
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('BpMessage: AddMessageToLot failed', [
                'error' => $e->getMessage(),
                'idLot' => $idLot,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Finish a lot (no more messages will be added)
     *
     * POST /api/Lot/FinishLot/{idLot}
     *
     * @param int $idLot Lot ID
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function finishLot(int $idLot): array
    {
        $endpoint = "/api/Lot/FinishLot/{$idLot}";

        $this->logger->info('BpMessage: Finishing lot', [
            'endpoint' => $endpoint,
            'idLot' => $idLot,
        ]);

        try {
            $response = $this->client->post($this->baseUrl . $endpoint, [
                'headers' => $this->getHeaders(),
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $this->logger->info('BpMessage: FinishLot response', [
                'status_code' => $statusCode,
                'body' => $body,
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($body, true);

                // BpMessage returns boolean
                $success = false;
                if (is_bool($result)) {
                    $success = $result;
                } elseif (is_array($result) && isset($result['success'])) {
                    $success = (bool) $result['success'];
                } elseif ($statusCode === 200 || $statusCode === 201) {
                    $success = true;
                }

                return [
                    'success' => $success,
                    'error' => $success ? null : 'API returned false',
                ];
            }

            return [
                'success' => false,
                'error' => "HTTP {$statusCode}: {$body}",
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('BpMessage: FinishLot failed', [
                'error' => $e->getMessage(),
                'idLot' => $idLot,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get default headers for API requests
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Mautic-Source' => 'MauticBpMessageBundle',
        ];
    }

    /**
     * Create an email lot in BpMessage
     *
     * POST /api/Email/CreateLot
     *
     * @param array $data Email lot configuration
     * @return array ['success' => bool, 'idLotEmail' => int|null, 'error' => string|null]
     */
    public function createEmailLot(array $data): array
    {
        $endpoint = '/api/Email/CreateLot';

        $this->logger->info('BpMessage: Creating email lot', [
            'endpoint' => $endpoint,
            'name' => $data['name'] ?? 'unknown',
        ]);

        $this->logger->debug('BpMessage HTTP Request (Email)', [
            'method' => 'POST',
            'url' => $this->baseUrl . $endpoint,
            'headers' => $this->getHeaders(),
            'payload' => $data,
        ]);

        try {
            $response = $this->client->post($this->baseUrl . $endpoint, [
                'headers' => $this->getHeaders(),
                'json' => $data,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $this->logger->info('BpMessage: CreateEmailLot response', [
                'status_code' => $statusCode,
                'body' => $body,
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($body, true);

                // Handle different response formats
                $idLotEmail = null;
                if (is_numeric($result)) {
                    $idLotEmail = (int) $result;
                } elseif (is_array($result) && isset($result['id'])) {
                    $idLotEmail = (int) $result['id'];
                } elseif (is_array($result) && isset($result['idLotEmail'])) {
                    $idLotEmail = (int) $result['idLotEmail'];
                }

                if (null !== $idLotEmail) {
                    return [
                        'success' => true,
                        'idLotEmail' => $idLotEmail,
                        'error' => null,
                    ];
                }

                return [
                    'success' => false,
                    'idLotEmail' => null,
                    'error' => 'Invalid response format: ' . $body,
                ];
            }

            return [
                'success' => false,
                'idLotEmail' => null,
                'error' => "HTTP {$statusCode}: {$body}",
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('BpMessage: CreateEmailLot failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'idLotEmail' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Add emails to an existing email lot
     *
     * POST /api/Email/AddEmailToLot/{idLotEmail}
     *
     * @param int $idLotEmail Email lot ID
     * @param array $emails Array of emails (up to 5000)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function addEmailsToLot(int $idLotEmail, array $emails): array
    {
        $endpoint = "/api/Email/AddEmailToLot/{$idLotEmail}";

        $this->logger->info('BpMessage: Adding emails to lot', [
            'endpoint' => $endpoint,
            'idLotEmail' => $idLotEmail,
            'email_count' => count($emails),
        ]);

        $this->logger->debug('BpMessage HTTP Request (Email)', [
            'method' => 'POST',
            'url' => $this->baseUrl . $endpoint,
            'headers' => $this->getHeaders(),
            'payload_count' => count($emails),
            'first_email_sample' => !empty($emails) ? $emails[0] : null,
        ]);

        try {
            $response = $this->client->post($this->baseUrl . $endpoint, [
                'headers' => $this->getHeaders(),
                'json' => $emails,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $this->logger->info('BpMessage: AddEmailToLot response', [
                'status_code' => $statusCode,
                'body' => $body,
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($body, true);

                $success = false;
                if (is_bool($result)) {
                    $success = $result;
                } elseif (is_array($result) && isset($result['success'])) {
                    $success = (bool) $result['success'];
                } elseif ($statusCode === 200 || $statusCode === 201) {
                    $success = true;
                }

                return [
                    'success' => $success,
                    'error' => $success ? null : 'API returned false',
                ];
            }

            return [
                'success' => false,
                'error' => "HTTP {$statusCode}: {$body}",
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('BpMessage: AddEmailToLot failed', [
                'error' => $e->getMessage(),
                'idLotEmail' => $idLotEmail,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Finish an email lot (no more emails will be added)
     *
     * POST /api/Email/FinishLot/{idLotEmail}
     *
     * @param int $idLotEmail Email lot ID
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function finishEmailLot(int $idLotEmail): array
    {
        $endpoint = "/api/Email/FinishLot/{$idLotEmail}";

        $this->logger->info('BpMessage: Finishing email lot', [
            'endpoint' => $endpoint,
            'idLotEmail' => $idLotEmail,
        ]);

        try {
            $response = $this->client->post($this->baseUrl . $endpoint, [
                'headers' => $this->getHeaders(),
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();

            $this->logger->info('BpMessage: FinishEmailLot response', [
                'status_code' => $statusCode,
                'body' => $body,
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($body, true);

                $success = false;
                if (is_bool($result)) {
                    $success = $result;
                } elseif (is_array($result) && isset($result['success'])) {
                    $success = (bool) $result['success'];
                } elseif ($statusCode === 200 || $statusCode === 201) {
                    $success = true;
                }

                return [
                    'success' => $success,
                    'error' => $success ? null : 'API returned false',
                ];
            }

            return [
                'success' => false,
                'error' => "HTTP {$statusCode}: {$body}",
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('BpMessage: FinishEmailLot failed', [
                'error' => $e->getMessage(),
                'idLotEmail' => $idLotEmail,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test connection to BpMessage API
     *
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function testConnection(): array
    {
        try {
            $response = $this->client->get($this->baseUrl . '/api', [
                'headers' => $this->getHeaders(),
                'timeout' => 5,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 500) {
                return [
                    'success' => true,
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'error' => "HTTP {$statusCode}",
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
