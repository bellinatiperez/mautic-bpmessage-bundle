<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Doctrine\ORM\EntityManager;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLotRepository;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueueRepository;
use MauticPlugin\MauticBpMessageBundle\Http\BpMessageClient;
use Psr\Log\LoggerInterface;

/**
 * Service to manage BpMessage lots (creation, queuing, sending, finishing)
 */
class LotManager
{
    private EntityManager $entityManager;
    private BpMessageClient $client;
    private LoggerInterface $logger;

    public function __construct(
        EntityManager $entityManager,
        BpMessageClient $client,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Get or create an active lot for a campaign
     *
     * @param Campaign $campaign
     * @param array $config Action configuration
     * @return BpMessageLot
     * @throws \RuntimeException if lot creation fails
     */
    public function getOrCreateActiveLot(Campaign $campaign, array $config): BpMessageLot
    {
        // Check if there's an open lot for this campaign
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.campaignId = :campaignId')
            ->andWhere('l.status = :status')
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('status', 'OPEN')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1);

        $lot = $qb->getQuery()->getOneOrNullResult();

        if (null !== $lot && !$lot->shouldCloseByCount() && !$lot->shouldCloseByTime()) {
            $this->logger->info('BpMessage: Using existing lot', [
                'lot_id' => $lot->getId(),
                'external_lot_id' => $lot->getExternalLotId(),
                'campaign_id' => $campaign->getId(),
            ]);

            return $lot;
        }

        // Create new lot
        $this->logger->info('BpMessage: Creating new lot', [
            'campaign_id' => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
        ]);

        return $this->createLot($campaign, $config);
    }

    /**
     * Create a new lot
     *
     * @param Campaign $campaign
     * @param array $config
     * @return BpMessageLot
     * @throws \RuntimeException if lot creation fails
     */
    private function createLot(Campaign $campaign, array $config): BpMessageLot
    {
        // Create lot entity
        $lot = new BpMessageLot();
        $lot->setName($config['lot_name'] ?? "Campaign {$campaign->getName()}");
        $lot->setStartDate(new \DateTime('now'));
        $lot->setEndDate(new \DateTime('now')); // Same day to avoid API error
        $lot->setUserCpf('system'); // Fixed value for all lots
        $lot->setIdQuotaSettings((int) $config['id_quota_settings']);
        $lot->setIdServiceSettings((int) $config['id_service_settings']);
        $lot->setCampaignId($campaign->getId());
        $lot->setApiBaseUrl($config['api_base_url']);
        $lot->setBatchSize((int) ($config['batch_size'] ?? $config['default_batch_size'] ?? 1000));
        $lot->setTimeWindow((int) ($config['time_window'] ?? $config['default_time_window'] ?? 300));
        $lot->setStatus('CREATING');

        if (!empty($config['image_url'])) {
            $lot->setImageUrl($config['image_url']);
        }

        if (!empty($config['image_name'])) {
            $lot->setImageName($config['image_name']);
        }

        // Save to database first
        $this->entityManager->persist($lot);
        $this->entityManager->flush();

        // Call BpMessage API to create lot
        $this->client->setBaseUrl($config['api_base_url']);

        $lotData = [
            'name' => $lot->getName(),
            'startDate' => $lot->getStartDate()->format('c'),
            'endDate' => $lot->getEndDate()->format('c'),
            'user' => 'system', // Fixed value
            'idQuotaSettings' => $lot->getIdQuotaSettings(),
            'idServiceSettings' => $lot->getIdServiceSettings(),
        ];

        if (null !== $lot->getImageUrl()) {
            $lotData['imageUrl'] = $lot->getImageUrl();
        }

        if (null !== $lot->getImageName()) {
            $lotData['imageName'] = $lot->getImageName();
        }

        // Merge lot_data from config if provided
        if (!empty($config['lot_data']) && is_array($config['lot_data'])) {
            $lotData = array_merge($lotData, $config['lot_data']);
        }

        $result = $this->client->createLot($lotData);

        if (!$result['success']) {
            $lot->setStatus('FAILED');
            $lot->setErrorMessage($result['error']);
            $this->entityManager->flush();

            throw new \RuntimeException("Failed to create lot in BpMessage: {$result['error']}");
        }

        // Update lot with external ID
        $lot->setExternalLotId((string) $result['idLot']);
        $lot->setStatus('OPEN');
        $this->entityManager->flush();

        $this->logger->info('BpMessage: Lot created successfully', [
            'lot_id' => $lot->getId(),
            'external_lot_id' => $lot->getExternalLotId(),
        ]);

        return $lot;
    }

    /**
     * Queue a message for a lot
     *
     * @param BpMessageLot $lot
     * @param Lead $lead
     * @param array $messageData
     * @return BpMessageQueue
     */
    public function queueMessage(BpMessageLot $lot, Lead $lead, array $messageData): BpMessageQueue
    {
        // Check if lead is already queued
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(q.id)')
            ->from(BpMessageQueue::class, 'q')
            ->where('q.lot = :lot')
            ->andWhere('q.lead = :lead')
            ->setParameter('lot', $lot)
            ->setParameter('lead', $lead);

        $count = (int) $qb->getQuery()->getSingleScalarResult();

        if ($count > 0) {
            $this->logger->warning('BpMessage: Lead already queued', [
                'lot_id' => $lot->getId(),
                'lead_id' => $lead->getId(),
            ]);

            throw new \RuntimeException("Lead {$lead->getId()} is already queued for lot {$lot->getId()}");
        }

        $queue = new BpMessageQueue();
        $queue->setLot($lot);
        $queue->setLead($lead);
        $queue->setPayloadArray($messageData);
        $queue->setStatus('PENDING');

        $this->entityManager->persist($queue);

        // Increment lot message count
        $lot->incrementMessagesCount();

        $this->entityManager->flush();

        $this->logger->info('BpMessage: Message queued', [
            'lot_id' => $lot->getId(),
            'lead_id' => $lead->getId(),
            'queue_id' => $queue->getId(),
        ]);

        return $queue;
    }

    /**
     * Check if a lot should be closed
     *
     * @param BpMessageLot $lot
     * @return bool
     */
    public function shouldCloseLot(BpMessageLot $lot): bool
    {
        return $lot->shouldCloseByTime() || $lot->shouldCloseByCount();
    }

    /**
     * Send all pending messages for a lot
     *
     * @param BpMessageLot $lot
     * @return bool
     */
    public function sendLotMessages(BpMessageLot $lot): bool
    {
        if (!$lot->isOpen()) {
            $this->logger->warning('BpMessage: Cannot send messages, lot is not open', [
                'lot_id' => $lot->getId(),
                'status' => $lot->getStatus(),
            ]);

            return false;
        }

        $lot->setStatus('SENDING');
        $this->entityManager->flush();

        $this->logger->info('BpMessage: Starting to send lot messages', [
            'lot_id' => $lot->getId(),
            'external_lot_id' => $lot->getExternalLotId(),
        ]);

        // Get all pending messages
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('q')
            ->from(BpMessageQueue::class, 'q')
            ->where('q.lot = :lot')
            ->andWhere('q.status = :status')
            ->setParameter('lot', $lot)
            ->setParameter('status', 'PENDING')
            ->orderBy('q.createdAt', 'ASC');

        $pendingMessages = $qb->getQuery()->getResult();

        if (empty($pendingMessages)) {
            $this->logger->warning('BpMessage: No pending messages to send', [
                'lot_id' => $lot->getId(),
            ]);

            return true;
        }

        $this->logger->info('BpMessage: Found pending messages', [
            'lot_id' => $lot->getId(),
            'count' => count($pendingMessages),
        ]);

        // Send in batches of 5000 (BpMessage limit)
        $batches = array_chunk($pendingMessages, 5000);
        $success = true;

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->info('BpMessage: Sending batch', [
                'lot_id' => $lot->getId(),
                'batch_index' => $batchIndex,
                'batch_size' => count($batch),
            ]);

            $messages = array_map(function (BpMessageQueue $queue) {
                return $queue->getPayloadArray();
            }, $batch);

            $this->client->setBaseUrl($lot->getApiBaseUrl());
            $result = $this->client->addMessagesToLot((int) $lot->getExternalLotId(), $messages);

            if ($result['success']) {
                // Mark messages as sent
                $ids = array_map(function (BpMessageQueue $queue) {
                    return $queue->getId();
                }, $batch);

                $qb = $this->entityManager->createQueryBuilder();
                $qb->update(BpMessageQueue::class, 'q')
                    ->set('q.status', ':status')
                    ->set('q.sentAt', ':sentAt')
                    ->where($qb->expr()->in('q.id', ':ids'))
                    ->setParameter('status', 'SENT')
                    ->setParameter('sentAt', new \DateTime())
                    ->setParameter('ids', $ids)
                    ->getQuery()
                    ->execute();

                $this->logger->info('BpMessage: Batch sent successfully', [
                    'lot_id' => $lot->getId(),
                    'batch_index' => $batchIndex,
                ]);
            } else {
                $success = false;

                // Mark messages as failed
                foreach ($batch as $queue) {
                    $queue->markAsFailed($result['error']);
                }

                $this->entityManager->flush();

                $this->logger->error('BpMessage: Batch failed', [
                    'lot_id' => $lot->getId(),
                    'batch_index' => $batchIndex,
                    'error' => $result['error'],
                ]);

                break; // Stop processing on first failure
            }
        }

        return $success;
    }

    /**
     * Finish a lot (close it in BpMessage)
     *
     * @param BpMessageLot $lot
     * @return bool
     */
    public function finishLot(BpMessageLot $lot): bool
    {
        $this->logger->info('BpMessage: Finishing lot', [
            'lot_id' => $lot->getId(),
            'external_lot_id' => $lot->getExternalLotId(),
        ]);

        $this->client->setBaseUrl($lot->getApiBaseUrl());
        $result = $this->client->finishLot((int) $lot->getExternalLotId());

        if ($result['success']) {
            $lot->setStatus('FINISHED');
            $lot->setFinishedAt(new \DateTime());
            $this->entityManager->flush();

            $this->logger->info('BpMessage: Lot finished successfully', [
                'lot_id' => $lot->getId(),
            ]);

            return true;
        }

        // Log warning but don't fail - messages were sent
        $this->logger->warning('BpMessage: Failed to finish lot, but messages were sent', [
            'lot_id' => $lot->getId(),
            'error' => $result['error'],
        ]);

        $lot->setStatus('FINISHED');
        $lot->setFinishedAt(new \DateTime());
        $lot->setErrorMessage("Finish API call failed: {$result['error']}");
        $this->entityManager->flush();

        return true; // Consider it success since messages were sent
    }

    /**
     * Process a lot (send messages and finish)
     *
     * @param BpMessageLot $lot
     * @return bool
     */
    public function processLot(BpMessageLot $lot): bool
    {
        $success = $this->sendLotMessages($lot);

        if (!$success) {
            $lot->setStatus('FAILED');
            $lot->setErrorMessage('Failed to send messages');
            $this->entityManager->flush();

            return false;
        }

        return $this->finishLot($lot);
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
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('q')
            ->from(BpMessageQueue::class, 'q')
            ->where('q.status = :status')
            ->andWhere('q.retryCount < :maxRetries')
            ->setParameter('status', 'FAILED')
            ->setParameter('maxRetries', $maxRetries)
            ->orderBy('q.createdAt', 'ASC');

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        $failedMessages = $qb->getQuery()->getResult();

        if (empty($failedMessages)) {
            return 0;
        }

        $this->logger->info('BpMessage: Retrying failed messages', [
            'count' => count($failedMessages),
        ]);

        // Group by lot
        $messagesByLot = [];
        foreach ($failedMessages as $message) {
            $lotId = $message->getLot()->getId();
            if (!isset($messagesByLot[$lotId])) {
                $messagesByLot[$lotId] = [];
            }
            $messagesByLot[$lotId][] = $message;
        }

        $retried = 0;

        foreach ($messagesByLot as $lotId => $messages) {
            $lot = $this->entityManager->find(BpMessageLot::class, $lotId);

            if (null === $lot || !$lot->isOpen()) {
                continue;
            }

            // Reset to pending
            $ids = array_map(function (BpMessageQueue $queue) {
                return $queue->getId();
            }, $messages);

            $qb = $this->entityManager->createQueryBuilder();
            $qb->update(BpMessageQueue::class, 'q')
                ->set('q.status', ':status')
                ->set('q.retryCount', 'q.retryCount + 1')
                ->where($qb->expr()->in('q.id', ':ids'))
                ->setParameter('status', 'PENDING')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->execute();

            $retried += count($ids);
        }

        return $retried;
    }

    /**
     * Parse datetime value from form to DateTime object
     *
     * @param mixed $value Value from form (can be DateTime, string, or null)
     * @param string $default Default value if null
     * @return \DateTime
     */
    private function parseDateTime($value, string $default = 'now'): \DateTime
    {
        // If already a DateTime object, return it
        if ($value instanceof \DateTime) {
            return $value;
        }

        // If null or empty string, use default
        if (null === $value || '' === $value) {
            return new \DateTime($default);
        }

        // If string, try to parse it
        if (is_string($value)) {
            try {
                return new \DateTime($value);
            } catch (\Exception $e) {
                $this->logger->warning('BpMessage: Failed to parse datetime', [
                    'value' => $value,
                    'error' => $e->getMessage(),
                ]);
                return new \DateTime($default);
            }
        }

        // Fallback to default
        return new \DateTime($default);
    }
}
