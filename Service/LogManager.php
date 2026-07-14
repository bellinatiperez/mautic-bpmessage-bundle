<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use MauticPlugin\MauticBpMessageBundle\Entity\PluginDataAnalyticsSettings;
use MauticPlugin\MauticBpMessageBundle\Entity\PluginFornecedorSettings;
use Psr\Log\LoggerInterface;

class LogManager
{
    private EntityManager $entityManager;
    private LoggerInterface $logger;
    private Client $httpClient;

    public function __construct(
        EntityManager $entityManager,
        LoggerInterface $logger,
    ) {
        $this->entityManager = $entityManager;
        $this->logger        = $logger;

        $this->httpClient = new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'http_errors'     => false,
        ]);
    }

    public function getTemplateDescription(int $carteiraId, string $idTemplate): ?string
    {
        $fornecedorSettings = $this->entityManager
            ->getRepository(PluginFornecedorSettings::class)
            ->findOneBy(['idCarteira' => (string) $carteiraId]);

        $token     = $fornecedorSettings?->getTokenAcesso();
        $idEmpresa = $fornecedorSettings?->getIdEmpresa();
        $url       = $fornecedorSettings?->getUrl();

        $url  = $url  + $idEmpresa;


        try {
            $response = $this->httpClient->get($url, [
                'headers' => [
                    'Authorization' => $token,
                ],
            ]);

            $this->logger->info('BpMessage LogManager: getTemplateDescription GET response', [
                'url'         => $url,
                'status_code' => $response->getStatusCode(),
            ]);

            $templates = json_decode((string) $response->getBody(), true);

            if (!is_array($templates)) {
                return null;
            }

            foreach ($templates as $template) {
                if (isset($template['template_id']) && (string) $template['template_id'] === $idTemplate) {
                    return $template['text'] ?? null;
                }
            }

            return null;
        } catch (GuzzleException $e) {
            $this->logger->error('BpMessage LogManager: getTemplateDescription GET failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function sendMessageLogs(Collection $queueItems): void
    {
        $templateDescription = $this->resolveTemplateDescription($queueItems);

        if (null === $templateDescription) {
            $this->logger->warning('BpMessage LogManager: templateDescription not found in queue items', [
                'queue_items_count' => $queueItems->count(),
            ]);
        }

        $splunkSettings = $this->entityManager
            ->getRepository(PluginDataAnalyticsSettings::class)
            ->findOneBy(['nome' => 'splunk']);

        $urlSplunk   = $splunkSettings?->getUrl();
        $tokenSplunk = $splunkSettings?->getToken();

        $this->logger->info('BpMessage LogManager: sendMessageLogs (mock)', [
            'queue_items_count'    => $queueItems->count(),
            'template_description' => $templateDescription,
        ]);

        $eventos = $this->buildEventosPayload($queueItems, $templateDescription);

        if (empty($eventos)) {
            return;
        }

        try {
            $response = $this->httpClient->post($urlSplunk, [
                'headers' => [
                    'Authorization' => "Splunk {$tokenSplunk}",
                ],
                'json' => $eventos,
            ]);

            $this->logger->info('BpMessage LogManager: sendMessageLogs eventos sent', [
                'url'         => $urlSplunk,
                'status_code' => $response->getStatusCode(),
                'eventos'     => $eventos,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('BpMessage LogManager: failed to send eventos', [
                'url'   => $urlSplunk,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildEventosPayload(Collection $queueItems, ?string $templateDescription): array
    {
        $eventos = [];

        foreach ($queueItems as $queueItem) {
            $payload = $queueItem->getPayloadArray();

            if (empty($payload['phone'])) {
                continue;
            }

            $eventos[] = [
                'event' => [
                    'template' => $templateDescription,
                    'numero'   => $payload['phone'],
                ],
            ];
        }

        return $eventos;
    }

    private function resolveTemplateDescription(Collection $queueItems): ?string
    {
        foreach ($queueItems as $queueItem) {
            $payload = $queueItem->getPayloadArray();

            if (!empty($payload['idTemplate']) && !empty($payload['idForeignBookBusiness'])) {
                return $this->getTemplateDescription(
                    (int) $payload['idForeignBookBusiness'],
                    (string) $payload['idTemplate']
                );
            }
        }

        return null;
    }
}
