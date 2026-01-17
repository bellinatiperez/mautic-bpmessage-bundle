<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * HTTP Client for CRM API (external phone lookup service).
 *
 * This client fetches phone numbers from an external API based on CPF/CNPJ and contract number.
 * The phones are returned sorted by score (pontuacao) in descending order.
 */
class CRMClient
{
    private Client $client;
    private LoggerInterface $logger;
    private string $baseUrl;
    private string $apiKey;

    public function __construct(LoggerInterface $logger, string $baseUrl = '', string $apiKey = '')
    {
        $this->logger  = $logger;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey  = $apiKey;

        $this->client = new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'http_errors'     => false,
        ]);
    }

    /**
     * Set base URL.
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');

        return $this;
    }

    /**
     * Set API key.
     */
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Fetch phones from CRM API based on CPF/CNPJ.
     *
     * POST /api/v1/busca-user-telefone
     *
     * @param string $cpfCnpj CPF or CNPJ (only digits)
     *
     * @return array ['success' => bool, 'phones' => array, 'error' => string|null]
     *               phones: [['numeroTelefone' => string, 'pontuacao' => int, 'crm' => string], ...]
     */
    public function fetchPhones(string $cpfCnpj): array
    {
        if (empty($this->baseUrl)) {
            $this->logger->warning('CRMClient: Base URL not configured');

            return [
                'success' => false,
                'phones'  => [],
                'error'   => 'CRM API URL not configured',
            ];
        }

        if (empty($this->apiKey)) {
            $this->logger->warning('CRMClient: API key not configured');

            return [
                'success' => false,
                'phones'  => [],
                'error'   => 'CRM API key not configured',
            ];
        }

        $endpoint = '/api/v1/busca-user-telefone';

        $payload = [
            'cpfCnpj'  => $cpfCnpj,
            'contrato' => '',
        ];

        $this->logger->info('CRMClient: Fetching phones', [
            'endpoint' => $endpoint,
            'cpfCnpj'  => substr($cpfCnpj, 0, 3).'***'.substr($cpfCnpj, -2), // Masked for privacy
        ]);

        $this->logger->debug('CRMClient HTTP Request', [
            'method'  => 'POST',
            'url'     => $this->baseUrl.$endpoint,
            'payload' => $payload,
        ]);

        try {
            $response = $this->client->post($this->baseUrl.$endpoint, [
                'headers' => $this->getHeaders(),
                'json'    => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $body       = (string) $response->getBody();

            $this->logger->info('CRMClient: Response received', [
                'status_code' => $statusCode,
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                $result = json_decode($body, true);

                // Check for success field in response
                if (is_array($result) && isset($result['sucesso']) && true === $result['sucesso']) {
                    $phones = $result['telefones'] ?? [];

                    // Remove duplicates based on phone number
                    $uniquePhones = [];
                    $seenNumbers  = [];
                    foreach ($phones as $phone) {
                        $number = $phone['numeroTelefone'] ?? '';
                        if (!empty($number) && !isset($seenNumbers[$number])) {
                            $uniquePhones[]       = $phone;
                            $seenNumbers[$number] = true;
                        }
                    }

                    // Sort by pontuacao descending (highest score first)
                    usort($uniquePhones, function ($a, $b) {
                        return ($b['pontuacao'] ?? 0) - ($a['pontuacao'] ?? 0);
                    });

                    $this->logger->info('CRMClient: Phones fetched successfully', [
                        'total_count'  => count($phones),
                        'unique_count' => count($uniquePhones),
                    ]);

                    return [
                        'success' => true,
                        'phones'  => $uniquePhones,
                        'error'   => null,
                    ];
                }

                // API returned success=false or unexpected format
                $errorMessage = $result['mensagem'] ?? 'Unknown error';

                $this->logger->warning('CRMClient: API returned error', [
                    'message' => $errorMessage,
                ]);

                return [
                    'success' => false,
                    'phones'  => [],
                    'error'   => $errorMessage,
                ];
            }

            return [
                'success' => false,
                'phones'  => [],
                'error'   => "HTTP {$statusCode}: {$body}",
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('CRMClient: Request failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'phones'  => [],
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch phones in batch for multiple CPF/CNPJ values.
     *
     * This method optimizes API calls by batching requests when possible.
     * However, the current API doesn't support batch requests, so this method
     * makes individual requests for each CPF/CNPJ.
     *
     * @param array $cpfCnpjList Array of CPF/CNPJ strings
     *
     * @return array Map of cpfCnpj => ['success' => bool, 'phones' => array, 'error' => string|null]
     */
    public function fetchPhonesBatch(array $cpfCnpjList): array
    {
        $results = [];

        foreach ($cpfCnpjList as $cpfCnpj) {
            if (empty($cpfCnpj)) {
                $results[$cpfCnpj] = [
                    'success' => false,
                    'phones'  => [],
                    'error'   => 'CPF/CNPJ is empty',
                ];
                continue;
            }

            $results[$cpfCnpj] = $this->fetchPhones($cpfCnpj);
        }

        return $results;
    }

    /**
     * Test connection to CRM API.
     *
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function testConnection(): array
    {
        if (empty($this->baseUrl)) {
            return [
                'success' => false,
                'error'   => 'CRM API URL not configured',
            ];
        }

        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error'   => 'CRM API key not configured',
            ];
        }

        try {
            // Try to make a request with a test CPF (won't find results, but should return 200)
            $response = $this->client->post($this->baseUrl.'/api/v1/busca-user-telefone', [
                'headers' => $this->getHeaders(),
                'json'    => [
                    'cpfCnpj'  => '00000000000',
                    'contrato' => '',
                ],
                'timeout' => 5,
            ]);

            $statusCode = $response->getStatusCode();

            // Accept 200-499 as "connected" (even 4xx means API is reachable)
            if ($statusCode >= 200 && $statusCode < 500) {
                return [
                    'success' => true,
                    'error'   => null,
                ];
            }

            return [
                'success' => false,
                'error'   => "HTTP {$statusCode}",
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Get default headers for API requests.
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'X-API-Key'    => $this->apiKey,
        ];
    }
}
