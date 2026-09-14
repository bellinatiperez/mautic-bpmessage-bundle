<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use GuzzleHttp\Client;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use MauticPlugin\MauticBpMessageBundle\Entity\PluginDataAnalyticsSettings;
use MauticPlugin\MauticBpMessageBundle\Entity\PluginFornecedorSettings;
use Psr\Log\LoggerInterface;

class LogManager
{
    private EntityManager $entityManager;
    private LoggerInterface $logger;
    private Client $httpClient;
    
    private ?array $lastTemplateRequest = null;
    private ?array $lastTemplateResponse = null;
    
    public function __construct(
        EntityManager $entityManager,
        LoggerInterface $logger,
        ) {
            $this->entityManager = $entityManager;
            $this->logger        = $logger;
            
        $this->httpClient = new Client([
            'timeout'         => 5,
            'connect_timeout' => 3,
            'http_errors'     => false,
            ]);
    }
    
    public function sendMessageLogs(Collection $queueItems): void
    {
        try {
            if ($queueItems->isEmpty()) {
                return;
            }
    
            $templateDescription = $this->resolveTemplateDescription($queueItems);
    
            if (null === $templateDescription) {
                $this->logger->warning('BpMessage LogManager: templateDescription not found in queue items', [
                    'queue_items_count' => $queueItems->count(),
                ]);
            }
    
            $splunkSettings = $this->entityManager
                ->getRepository(PluginDataAnalyticsSettings::class)
                ->findOneBy(['nome' => 'splunk']);
    
            if (null === $splunkSettings) {
                $this->logger->warning('BpMessage LogManager: PluginDataAnalyticsSettings (splunk) not configured, skipping log send');
    
                return;
            }
    
            $urlSplunk   = $splunkSettings->getUrl();
            $tokenSplunk = $splunkSettings->getToken();
    
            $eventPayload = $this->buildLogEventoPayload($queueItems, $templateDescription);
    
            $response = $this->httpClient->post($urlSplunk, [
                'headers' => [
                    'Authorization' => "Splunk {$tokenSplunk}",
                ],
                'json' => $eventPayload,
                'verify' => false,
            ]);
    
            $this->logger->info('BpMessage LogManager: sendMessageLogs event sent', [
                'url'         => $urlSplunk,
                'status_code' => $response->getStatusCode(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('BpMessage LogManager: sendMessageLogs failed, skipping without blocking lot creation', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getTemplateDescription(string $carteiraId, string $idTemplate): ?string
    {
        $this->lastTemplateRequest  = null;
        $this->lastTemplateResponse = null;

        $fornecedorSettings = $this->entityManager
            ->getRepository(PluginFornecedorSettings::class)
            ->findOneBy(['idCarteira' => $carteiraId]);

        if (null === $fornecedorSettings) {
            $this->logger->warning('BpMessage LogManager: PluginFornecedorSettings not found for carteira', [
                'carteira_id' => $carteiraId,
            ]);

            return null;
        }

        $token     = $fornecedorSettings->getTokenAcesso();
        $idEmpresa = $fornecedorSettings->getIdEmpresa();
        $url       = $fornecedorSettings->getUrl().'/'.$idEmpresa;

        $this->lastTemplateRequest = [
            'url'     => $url,
            'method'  => 'GET',
            'headers' => ['Authorization' => $token],
        ];

        try {
            $startTime = microtime(true);

            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => $token,
                ],
            ]);

            $elapsedMs = (microtime(true) - $startTime) * 1000;
            $body      = (string) $response->getBody();

            $this->lastTemplateResponse = [
                'statusCode' => $response->getStatusCode(),
                'elapsedMs'  => $elapsedMs,
            ];

            $this->logger->info('BpMessage LogManager: getTemplateDescription GET response', [
                'url'         => $url,
                'status_code' => $response->getStatusCode(),
                'elapsed_ms'  => $elapsedMs,
            ]);

            $templates = json_decode($body, true);

            if (!is_array($templates)) {
                return null;
            }

            foreach ($templates as $template) {
                if (isset($template['code']) && (string) $template['code'] === $idTemplate) {
                    return $template['text'] ?? null;
                }
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('BpMessage LogManager: getTemplateDescription GET failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    

    private function buildLogEventoPayload(Collection $queueItems, ?string $templateDescription): array
    {
        $firstQueueItem = $queueItems->first();
        $lot            = $firstQueueItem->getLot();
        $payload        = $firstQueueItem->getPayloadArray();

        $carteiraId = !empty($payload['idForeignBookBusiness']) ? (string) $payload['idForeignBookBusiness'] : null;

        $maskedRequest = $this->lastTemplateRequest;
        if (null !== $maskedRequest && isset($maskedRequest['headers']['Authorization'])) {
            // Mask the access token before it is embedded in the event body sent to Splunk.
            $token = $maskedRequest['headers']['Authorization'];
            $maskedRequest['headers']['Authorization'] = strlen($token) > 4
                ? str_repeat('*', strlen($token) - 4).substr($token, -4)
                : str_repeat('*', strlen($token));
        }

        $logEvento = [
            'Crm'             => $lot->getCrmId(),
            'CarteiraId'      => $carteiraId,
            'Carteira'        => null !== $carteiraId ? (string) $carteiraId : null,
            'Fase'            => 'envio-mensagem-'.($payload['phone'] ?? ''),
            'Api'             => 'api-mautic',
            'Origem'          => 'Mautic::sendMessageLogs',
            'Servico'         => sprintf('Log de registro de Envio: %s enviado para %s', $templateDescription ?? '', $payload['phone'] ?? ''),
            'Data'            => (new \DateTime())->format('c'),
            'CpfCnpj'         => null,
            'Metodo'          => $this->lastTemplateRequest['method'] ?? null,
            'Url'             => $this->lastTemplateRequest['url'] ?? null,
            'Tempo'           => $this->lastTemplateResponse['elapsedMs'] ?? null,
            'HttpStatus'      => $this->lastTemplateResponse['statusCode'] ?? null,
            'UserJornadaGuid' => null,
            'RequestId'       => null,
            'Canal'           => [
                'Id'        => null,
                'Nome'      => null,
                'TipoCanal' => null,
            ],
            'Request'    => $maskedRequest,
            'Response'   => $this->lastTemplateResponse['statusCode'] ?? null,
            'TrackingId' => $payload['contract'] ?? null,
            'Campanha'   => $lot->getCampaignId(),
            'IpAddress'  => null,
            'HostName'   => gethostname(),
            'Referrer'   => null,
            'UserAgent'  => null,
            'Exceptions' => null,
        ];

        return [
            'index' => 'bporquestrador-mautic',
            'time'  => time(),
            'event' => $logEvento,
        ];
    }

    private function resolveTemplateDescription(Collection $queueItems): ?string
    {
        foreach ($queueItems as $queueItem) {
            $payload = $queueItem->getPayloadArray();

            if (!empty($payload['idTemplate']) && !empty($payload['idForeignBookBusiness'])) {
                return $this->getTemplateDescription(
                    (string) $payload['idForeignBookBusiness'],
                    (string) $payload['idTemplate']
                );
            }
        }

        return null;
    }
}
