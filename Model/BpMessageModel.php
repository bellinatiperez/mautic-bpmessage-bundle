<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Model;

use Doctrine\ORM\EntityManager;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLotRepository;
use MauticPlugin\MauticBpMessageBundle\Integration\BpMessageIntegration;
use MauticPlugin\MauticBpMessageBundle\Service\LotManager;
use MauticPlugin\MauticBpMessageBundle\Service\MessageMapper;
use Psr\Log\LoggerInterface;

/**
 * Model for BpMessage operations
 */
class BpMessageModel
{
    private LotManager $lotManager;
    private MessageMapper $messageMapper;
    private EntityManager $em;
    private LoggerInterface $logger;
    private IntegrationHelper $integrationHelper;

    public function __construct(
        LotManager $lotManager,
        MessageMapper $messageMapper,
        EntityManager $entityManager,
        LoggerInterface $logger,
        IntegrationHelper $integrationHelper
    ) {
        $this->lotManager = $lotManager;
        $this->messageMapper = $messageMapper;
        $this->em = $entityManager;
        $this->logger = $logger;
        $this->integrationHelper = $integrationHelper;
    }

    /**
     * Send a message for a lead (called from campaign action)
     *
     * @param Lead $lead
     * @param array $config Campaign action configuration
     * @param Campaign $campaign
     * @return array ['success' => bool, 'message' => string]
     */
    public function sendMessage(Lead $lead, array $config, Campaign $campaign): array
    {
        try {
            // Get settings from integration
            $apiBaseUrl = $this->getApiBaseUrl();
            if (!$apiBaseUrl) {
                return [
                    'success' => false,
                    'message' => 'API Base URL not configured. Please configure the BpMessage plugin in Settings > Plugins.',
                ];
            }

            // Add integration settings to config
            $config['api_base_url'] = $apiBaseUrl;
            $config['default_batch_size'] = $this->getDefaultBatchSize();
            $config['default_time_window'] = $this->getDefaultTimeWindow();

            // Validate configuration
            $this->validateConfig($config);

            // Validate lead
            $validation = $this->messageMapper->validateLead($lead, $config);
            if (!$validation['valid']) {
                $errorMsg = implode('; ', $validation['errors']);
                $this->logger->warning('BpMessage: Lead validation failed', [
                    'lead_id' => $lead->getId(),
                    'errors' => $errorMsg,
                ]);

                return [
                    'success' => false,
                    'message' => "Lead validation failed: {$errorMsg}",
                ];
            }

            // Process lot_data with token replacement (using first lead as reference)
            if (!empty($config['lot_data'])) {
                $config['lot_data'] = $this->messageMapper->processLotData($lead, $config);
            }

            // Get or create active lot
            $lot = $this->lotManager->getOrCreateActiveLot($campaign, $config);

            // Map lead to message format
            $messageData = $this->messageMapper->mapLeadToMessage($lead, $config, $campaign);

            // Queue message
            $this->lotManager->queueMessage($lot, $lead, $messageData);

            $this->logger->info('BpMessage: Message queued successfully', [
                'lead_id' => $lead->getId(),
                'lot_id' => $lot->getId(),
                'campaign_id' => $campaign->getId(),
            ]);

            return [
                'success' => true,
                'message' => 'Message queued successfully',
            ];
        } catch (\Exception $e) {
            $this->logger->error('BpMessage: Failed to send message', [
                'lead_id' => $lead->getId(),
                'campaign_id' => $campaign->getId(),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process open lots that should be closed
     *
     * @return array ['processed' => int, 'succeeded' => int, 'failed' => int]
     */
    public function processOpenLots(bool $forceClose = false): array
    {
        // Find all open message lots (SMS/WhatsApp/RCS) - those with id_quota_settings > 0
        $qb = $this->em->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.status = :status')
            ->andWhere('l.idQuotaSettings > 0')
            ->setParameter('status', 'OPEN')
            ->orderBy('l.createdAt', 'ASC');

        $lots = $qb->getQuery()->getResult();

        // Filter lots that should be closed
        if ($forceClose) {
            // Force close ALL open lots
            $lotsToClose = $lots;
        } else {
            // Only close lots that meet time/count criteria
            $lotsToClose = array_filter($lots, function (BpMessageLot $lot) {
                return $lot->shouldCloseByTime() || $lot->shouldCloseByCount();
            });
        }

        $stats = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
        ];

        foreach ($lotsToClose as $lot) {
            ++$stats['processed'];

            $this->logger->info('BpMessage: Processing lot', [
                'lot_id' => $lot->getId(),
                'external_lot_id' => $lot->getExternalLotId(),
                'messages_count' => $lot->getMessagesCount(),
            ]);

            try {
                $success = $this->lotManager->processLot($lot);

                if ($success) {
                    ++$stats['succeeded'];
                } else {
                    ++$stats['failed'];
                }
            } catch (\Exception $e) {
                ++$stats['failed'];

                $this->logger->error('BpMessage: Failed to process lot', [
                    'lot_id' => $lot->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Retry failed messages
     *
     * @param int $maxRetries
     * @param int|null $limit
     * @return int Number of messages retried
     */
    public function retryFailedMessages(int $maxRetries = 3, ?int $limit = null): int
    {
        return $this->lotManager->retryFailedMessages($maxRetries, $limit);
    }

    /**
     * Clean up old lots and messages
     *
     * @param int $days
     * @return array ['lots_deleted' => int, 'messages_deleted' => int]
     */
    public function cleanup(int $days = 30): array
    {
        $threshold = new \DateTime();
        $threshold->modify("-{$days} days");

        $qb = $this->em->createQueryBuilder();
        $lotsDeleted = $qb->delete(BpMessageLot::class, 'l')
            ->where('l.status = :status')
            ->andWhere('l.finishedAt < :threshold')
            ->setParameter('status', 'FINISHED')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();

        // Messages will be deleted via CASCADE

        $this->logger->info('BpMessage: Cleanup completed', [
            'lots_deleted' => $lotsDeleted,
            'days' => $days,
        ]);

        return [
            'lots_deleted' => $lotsDeleted,
        ];
    }

    /**
     * Get statistics for a campaign
     *
     * @param int $campaignId
     * @return array
     */
    public function getCampaignStats(int $campaignId): array
    {
        /** @var BpMessageLotRepository $repository */
        $repository = $this->em->getRepository(BpMessageLot::class);

        return $repository->getCampaignStats($campaignId);
    }

    /**
     * Get API Base URL from integration
     *
     * @return string|null
     */
    private function getApiBaseUrl(): ?string
    {
        /** @var BpMessageIntegration|null $integration */
        $integration = $this->integrationHelper->getIntegrationObject('BpMessage');

        if (!$integration) {
            $this->logger->warning('BpMessage: Integration not found');
            return 'https://api.bpmessage.com.br'; // Fallback
        }

        $settings = $integration->getIntegrationSettings();

        if (!$settings || !$settings->getIsPublished()) {
            $this->logger->warning('BpMessage: Integration not published');
            return 'https://api.bpmessage.com.br'; // Fallback
        }

        $apiUrl = $integration->getApiBaseUrl();

        if (!$apiUrl) {
            $this->logger->warning('BpMessage: API URL not configured, using default');
            return 'https://api.bpmessage.com.br'; // Fallback
        }

        $this->logger->info('BpMessage: Using API URL from integration', [
            'api_url' => $apiUrl,
        ]);

        return $apiUrl;
    }

    /**
     * Get default batch size from integration
     *
     * @return int
     */
    private function getDefaultBatchSize(): int
    {
        /** @var BpMessageIntegration|null $integration */
        $integration = $this->integrationHelper->getIntegrationObject('BpMessage');

        if (!$integration) {
            return 1000; // Default fallback
        }

        $settings = $integration->getIntegrationSettings();

        if (!$settings || !$settings->getIsPublished()) {
            return 1000; // Default fallback
        }

        return $integration->getDefaultBatchSize();
    }

    /**
     * Get default time window from integration
     *
     * @return int
     */
    private function getDefaultTimeWindow(): int
    {
        /** @var BpMessageIntegration|null $integration */
        $integration = $this->integrationHelper->getIntegrationObject('BpMessage');

        if (!$integration) {
            return 300; // Default fallback
        }

        $settings = $integration->getIntegrationSettings();

        if (!$settings || !$settings->getIsPublished()) {
            return 300; // Default fallback
        }

        return $integration->getDefaultTimeWindow();
    }

    /**
     * Validate configuration
     *
     * @param array $config
     * @throws \InvalidArgumentException
     */
    private function validateConfig(array $config): void
    {
        // API Base URL is now in plugin settings, not required in config
        $required = [
            'id_quota_settings',
            'id_service_settings',
        ];

        foreach ($required as $field) {
            // Check if field exists and is not null
            if (!isset($config[$field]) && !array_key_exists($field, $config)) {
                throw new \InvalidArgumentException("Configuration field '{$field}' is required");
            }

            // ID fields must be greater than 0 (BpMessage API requirement)
            if (isset($config[$field]) && (int) $config[$field] <= 0) {
                throw new \InvalidArgumentException("Configuration field '{$field}' must be greater than 0");
            }
        }

        // Validate service type
        $serviceType = (int) ($config['service_type'] ?? 2);
        if (!in_array($serviceType, [1, 2, 3])) {
            throw new \InvalidArgumentException('Invalid service type');
        }

        // Validate service type specific fields
        if (in_array($serviceType, [1, 2]) && empty($config['message_text'])) {
            throw new \InvalidArgumentException('Message text is required for SMS/WhatsApp');
        }

        if (3 === $serviceType && empty($config['id_template'])) {
            throw new \InvalidArgumentException('Template ID is required for RCS');
        }
    }

    /**
     * Force close a specific lot
     *
     * @param int $lotId
     * @return bool
     */
    public function forceCloseLot(int $lotId): bool
    {
        /** @var BpMessageLotRepository $repository */
        $repository = $this->em->getRepository(BpMessageLot::class);

        $lot = $repository->find($lotId);

        if (null === $lot) {
            $this->logger->error('BpMessage: Lot not found', ['lot_id' => $lotId]);
            return false;
        }

        if (!$lot->isOpen()) {
            $this->logger->warning('BpMessage: Lot is not open', [
                'lot_id' => $lotId,
                'status' => $lot->getStatus(),
            ]);
            return false;
        }

        try {
            return $this->lotManager->processLot($lot);
        } catch (\Exception $e) {
            $this->logger->error('BpMessage: Failed to force close lot', [
                'lot_id' => $lotId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Process pending lots (OPEN lots that are ready to be closed)
     *
     * @param int $limit Maximum number of lots to process
     * @return array ['processed' => int, 'succeeded' => int, 'failed' => int]
     */
    public function processPendingLots(int $limit = 10): array
    {
        // Find OPEN lots that should be closed (by time or count)
        $qb = $this->em->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.status = :status')
            ->setParameter('status', 'OPEN')
            ->setMaxResults($limit);

        $lots = $qb->getQuery()->getResult();

        $stats = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
        ];

        foreach ($lots as $lot) {
            // Check if lot should be closed
            if (!$lot->shouldCloseByTime() && !$lot->shouldCloseByCount()) {
                continue;
            }

            ++$stats['processed'];

            try {
                $result = $this->lotManager->processLot($lot);

                if ($result) {
                    ++$stats['succeeded'];
                    $this->logger->info('BpMessage: Lot processed successfully', [
                        'lot_id' => $lot->getId(),
                    ]);
                } else {
                    ++$stats['failed'];
                    $this->logger->error('BpMessage: Failed to process lot', [
                        'lot_id' => $lot->getId(),
                    ]);
                }
            } catch (\Exception $e) {
                ++$stats['failed'];
                $this->logger->error('BpMessage: Exception while processing lot', [
                    'lot_id' => $lot->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }
}
