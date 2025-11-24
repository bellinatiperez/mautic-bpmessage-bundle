<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Doctrine\ORM\EntityManager;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use MauticPlugin\MauticBpMessageBundle\Http\BpMessageClient;
use Psr\Log\LoggerInterface;

/**
 * Service to manage BpMessage lots (creation, queuing, sending, finishing).
 */
class LotManager
{
    private EntityManager $entityManager;
    private BpMessageClient $client;
    private LoggerInterface $logger;

    public function __construct(
        EntityManager $entityManager,
        BpMessageClient $client,
        LoggerInterface $logger,
    ) {
        $this->entityManager = $entityManager;
        $this->client        = $client;
        $this->logger        = $logger;
    }

    /**
     * Get or create an active lot for a campaign.
     *
     * @param array $config Action configuration
     *
     * @throws \RuntimeException if lot creation fails
     */
    public function getOrCreateActiveLot(Campaign $campaign, array $config): BpMessageLot
    {
        // Check if there's an open lot for this campaign with matching configuration
        // Lots are unique by: campaignId + idQuotaSettings + idServiceSettings + serviceType
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.campaignId = :campaignId')
            ->andWhere('l.status = :status')
            ->andWhere('l.idQuotaSettings = :idQuotaSettings')
            ->andWhere('l.idServiceSettings = :idServiceSettings')
            ->andWhere('l.serviceType = :serviceType')
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('status', 'OPEN')
            ->setParameter('idQuotaSettings', (int) $config['id_quota_settings'])
            ->setParameter('idServiceSettings', (int) $config['id_service_settings'])
            ->setParameter('serviceType', (int) ($config['service_type'] ?? 2))
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1);

        $lot = $qb->getQuery()->getOneOrNullResult();

        // Check if lot can be reused - endDate expiration has PRIORITY
        if (null !== $lot && !$lot->isExpiredByEndDate() && !$lot->shouldCloseByCount() && !$lot->shouldCloseByTime()) {
            $this->logger->info('BpMessage: Using existing OPEN lot', [
                'lot_id'          => $lot->getId(),
                'external_lot_id' => $lot->getExternalLotId(),
                'campaign_id'     => $campaign->getId(),
            ]);

            return $lot;
        }

        // Log if lot was found but cannot be reused
        if (null !== $lot) {
            $reasons = [];
            if ($lot->isExpiredByEndDate()) {
                $reasons[] = 'endDate expired';
            }
            if ($lot->shouldCloseByCount()) {
                $reasons[] = 'batch size reached';
            }
            if ($lot->shouldCloseByTime()) {
                $reasons[] = 'time window expired';
            }

            $this->logger->info('BpMessage: Cannot reuse lot, creating new one', [
                'lot_id'  => $lot->getId(),
                'reasons' => implode(', ', $reasons),
            ]);
        }

        // Check if there's a FAILED_CREATION lot that can be reused
        // These lots failed during API call but can accept contacts for later retry
        // Reuse recent FAILED_CREATION lots (within last 24 hours) to avoid creating multiple failed lots
        $failedThreshold = new \DateTime('-24 hours');
        $qbFailed        = $this->entityManager->createQueryBuilder();
        $qbFailed->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.campaignId = :campaignId')
            ->andWhere('l.status = :status')
            ->andWhere('l.idQuotaSettings = :idQuotaSettings')
            ->andWhere('l.idServiceSettings = :idServiceSettings')
            ->andWhere('l.serviceType = :serviceType')
            ->andWhere('l.createdAt > :threshold')
            ->andWhere('l.messagesCount < :maxCount')
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('status', 'FAILED_CREATION')
            ->setParameter('idQuotaSettings', (int) $config['id_quota_settings'])
            ->setParameter('idServiceSettings', (int) $config['id_service_settings'])
            ->setParameter('serviceType', (int) ($config['service_type'] ?? 2))
            ->setParameter('threshold', $failedThreshold)
            ->setParameter('maxCount', 10000) // Don't reuse if too many messages accumulated
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1);

        $failedLot = $qbFailed->getQuery()->getOneOrNullResult();

        if (null !== $failedLot) {
            $this->logger->info('BpMessage: Reusing FAILED_CREATION lot - contacts will be queued for retry', [
                'lot_id'         => $failedLot->getId(),
                'campaign_id'    => $campaign->getId(),
                'messages_count' => $failedLot->getMessagesCount(),
                'error'          => $failedLot->getErrorMessage(),
            ]);

            return $failedLot;
        }

        // Check if there's a recent CREATING lot (within last 60 seconds) to prevent duplicates
        // This happens when multiple leads are processed in quick succession
        // Must match same configuration: idQuotaSettings + idServiceSettings + serviceType
        $recentThreshold = new \DateTime('-60 seconds');
        $qbCreating      = $this->entityManager->createQueryBuilder();
        $qbCreating->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.campaignId = :campaignId')
            ->andWhere('l.status = :status')
            ->andWhere('l.idQuotaSettings = :idQuotaSettings')
            ->andWhere('l.idServiceSettings = :idServiceSettings')
            ->andWhere('l.serviceType = :serviceType')
            ->andWhere('l.createdAt > :threshold')
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('status', 'CREATING')
            ->setParameter('idQuotaSettings', (int) $config['id_quota_settings'])
            ->setParameter('idServiceSettings', (int) $config['id_service_settings'])
            ->setParameter('serviceType', (int) ($config['service_type'] ?? 2))
            ->setParameter('threshold', $recentThreshold)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1);

        $creatingLot = $qbCreating->getQuery()->getOneOrNullResult();

        if (null !== $creatingLot) {
            $this->logger->info('BpMessage: Reusing recent CREATING lot to prevent duplicates', [
                'lot_id'      => $creatingLot->getId(),
                'campaign_id' => $campaign->getId(),
                'created_at'  => $creatingLot->getCreatedAt()->format('Y-m-d H:i:s'),
            ]);

            return $creatingLot;
        }

        // Create new lot
        $this->logger->info('BpMessage: Creating new lot', [
            'campaign_id'   => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
        ]);

        return $this->createLot($campaign, $config);
    }

    /**
     * Create a new lot.
     *
     * @throws \RuntimeException if lot creation fails
     */
    private function createLot(Campaign $campaign, array $config): BpMessageLot
    {
        // Create lot entity
        $lot = new BpMessageLot();
        $lot->setName($config['lot_name'] ?? "Campaign {$campaign->getName()}");

        // Calculate startDate and endDate in Brazil timezone
        // Using America/Sao_Paulo (Brazil) - hardcoded as this is for Brazilian market
        $timeWindow      = (int) ($config['time_window'] ?? $config['default_time_window'] ?? 300); // seconds
        $localTimezone   = new \DateTimeZone('America/Sao_Paulo');

        // Create dates in local timezone - Doctrine will convert to UTC when saving
        $now = new \DateTime('now', $localTimezone);

        // Check if lot_data has custom startDate/endDate
        $startDate = $now;
        $endDate   = (clone $now)->modify("+{$timeWindow} seconds");

        if (!empty($config['lot_data']) && is_array($config['lot_data'])) {
            // Check for startDate in lot_data
            if (!empty($config['lot_data']['startDate'])) {
                try {
                    $startDate = new \DateTime($config['lot_data']['startDate']);
                } catch (\Exception $e) {
                    $this->logger->warning('BpMessage: Invalid startDate format, using current time', [
                        'startDate' => $config['lot_data']['startDate'],
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            // Check for endDate in lot_data
            if (!empty($config['lot_data']['endDate'])) {
                try {
                    $endDate = new \DateTime($config['lot_data']['endDate']);
                } catch (\Exception $e) {
                    $this->logger->warning('BpMessage: Invalid endDate format, calculating from startDate + timeWindow', [
                        'endDate' => $config['lot_data']['endDate'],
                        'error'   => $e->getMessage(),
                    ]);
                    $endDate = (clone $startDate)->modify("+{$timeWindow} seconds");
                }
            }
        }

        $lot->setStartDate($startDate);
        $lot->setEndDate($endDate);
        $lot->setUserCpf('system'); // Fixed value for all lots
        $lot->setIdQuotaSettings((int) $config['id_quota_settings']);
        $lot->setIdServiceSettings((int) $config['id_service_settings']);
        $lot->setServiceType((int) ($config['service_type'] ?? 2)); // 1=SMS, 2=WhatsApp, 3=RCS
        $lot->setCampaignId($campaign->getId());
        $lot->setApiBaseUrl($config['api_base_url']);
        $lot->setBatchSize((int) ($config['batch_size'] ?? $config['default_batch_size'] ?? 1000));
        $lot->setTimeWindow($timeWindow);
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

        // Send dates in local timezone as ISO 8601 with milliseconds
        // BpMessage API expects local time (not UTC)
        // Force conversion to local timezone (Doctrine stores in UTC)
        // Using America/Sao_Paulo (Brazil) - hardcoded as this is for Brazilian market
        $localTimezone = new \DateTimeZone('America/Sao_Paulo');

        $startDate = clone $lot->getStartDate();
        $startDate->setTimezone($localTimezone);

        $endDate = clone $lot->getEndDate();
        $endDate->setTimezone($localTimezone);

        $startDateFormatted = $startDate->format('Y-m-d\TH:i:s.vP');
        $endDateFormatted = $endDate->format('Y-m-d\TH:i:s.vP');

        $lotData = [
            'name'              => $lot->getName(),
            'startDate'         => $startDateFormatted,
            'endDate'           => $endDateFormatted,
            'user'              => 'system', // Fixed value
            'idQuotaSettings'   => $lot->getIdQuotaSettings(),
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

        // Save the complete payload that will be sent to the API
        // This allows monitoring of values like startDate and endDate
        $lot->setCreateLotPayload($lotData);
        $this->entityManager->flush();

        try {
            $result = $this->client->createLot($lotData);

            if (!$result['success']) {
                // IMPORTANT: Mark as FAILED but DON'T throw exception
                // This allows contacts to be queued for later retry
                $lot->setStatus('FAILED_CREATION');
                $lot->setErrorMessage($result['error']);
                $this->entityManager->flush();

                $this->logger->warning('BpMessage: Failed to create lot in API, contacts will be queued for retry', [
                    'lot_id' => $lot->getId(),
                    'error'  => $result['error'],
                ]);

                // Return lot with FAILED_CREATION status
                // Contacts will be queued and can be retried later
                return $lot;
            }

            // Update lot with external ID
            $lot->setExternalLotId((string) $result['idLot']);
            $lot->setStatus('OPEN');

            // Ensure the entity is managed and flush immediately
            $this->entityManager->persist($lot);
            $this->entityManager->flush();

            // Verify persistence with a direct SQL update as fallback
            // This ensures the lot status is updated even if EntityManager has issues during batch processing
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET status = ?, externalLotId = ? WHERE id = ?',
                ['OPEN', (string) $result['idLot'], $lot->getId()]
            );

            // Force refresh from database to ensure we have the latest data
            $this->entityManager->refresh($lot);

            $this->logger->info('BpMessage: Lot created successfully', [
                'lot_id'          => $lot->getId(),
                'external_lot_id' => $lot->getExternalLotId(),
                'status'          => $lot->getStatus(),
            ]);

            return $lot;
        } catch (\Exception $e) {
            // If any exception occurs during API call, mark lot as FAILED_CREATION
            // This allows contacts to be queued for later retry
            $lot->setStatus('FAILED_CREATION');
            $lot->setErrorMessage('API call exception: '.$e->getMessage());
            $this->entityManager->persist($lot);
            $this->entityManager->flush();

            $this->logger->error('BpMessage: Exception during lot creation, contacts will be queued for retry', [
                'lot_id' => $lot->getId(),
                'error'  => $e->getMessage(),
            ]);

            // Return lot with FAILED_CREATION status instead of throwing
            // This allows contacts to be queued and retried later
            return $lot;
        }
    }

    /**
     * Queue a message for a lot.
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
                'lot_id'  => $lot->getId(),
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

        // Force increment with SQL to ensure persistence during batch processing
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'UPDATE bpmessage_lot SET messages_count = messages_count + 1 WHERE id = ?',
            [$lot->getId()]
        );

        // Refresh lot to get updated count
        $this->entityManager->refresh($lot);

        $this->logger->info('BpMessage: Message queued', [
            'lot_id'         => $lot->getId(),
            'lead_id'        => $lead->getId(),
            'queue_id'       => $queue->getId(),
            'messages_count' => $lot->getMessagesCount(),
        ]);

        return $queue;
    }

    /**
     * Check if a lot should be closed.
     */
    public function shouldCloseLot(BpMessageLot $lot): bool
    {
        return $lot->shouldCloseByTime() || $lot->shouldCloseByCount();
    }

    /**
     * Send all pending messages for a lot.
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
            'lot_id'          => $lot->getId(),
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
            'count'  => count($pendingMessages),
        ]);

        // Send in batches of 5000 (BpMessage limit)
        $batches = array_chunk($pendingMessages, 5000);
        $success = true;

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->info('BpMessage: Sending batch', [
                'lot_id'      => $lot->getId(),
                'batch_index' => $batchIndex,
                'batch_size'  => count($batch),
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
                    'lot_id'      => $lot->getId(),
                    'batch_index' => $batchIndex,
                ]);
            } else {
                $success = false;

                // Save error message to lot for user visibility
                $errorMessage = "Batch {$batchIndex} failed: ".$result['error'];
                $lot->setErrorMessage($errorMessage);
                $lot->setStatus('FAILED');

                // Mark messages as failed
                foreach ($batch as $queue) {
                    $queue->markAsFailed($result['error']);
                }

                $this->entityManager->flush();

                $this->logger->error('BpMessage: Batch failed', [
                    'lot_id'      => $lot->getId(),
                    'batch_index' => $batchIndex,
                    'error'       => $result['error'],
                ]);

                break; // Stop processing on first failure
            }
        }

        return $success;
    }

    /**
     * Finish a lot (close it in BpMessage).
     */
    public function finishLot(BpMessageLot $lot): bool
    {
        $this->logger->info('BpMessage: Finishing lot', [
            'lot_id'          => $lot->getId(),
            'external_lot_id' => $lot->getExternalLotId(),
        ]);

        $this->client->setBaseUrl($lot->getApiBaseUrl());
        $result = $this->client->finishLot((int) $lot->getExternalLotId());

        $now = new \DateTime();

        if ($result['success']) {
            $lot->setStatus('FINISHED');
            $lot->setFinishedAt($now);
            $this->entityManager->flush();

            // Force update with SQL to ensure persistence during batch processing
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET status = ?, finished_at = ? WHERE id = ?',
                ['FINISHED', $now->format('Y-m-d H:i:s'), $lot->getId()]
            );

            // Refresh to get latest data
            $this->entityManager->refresh($lot);

            $this->logger->info('BpMessage: Lot finished successfully', [
                'lot_id' => $lot->getId(),
                'status' => $lot->getStatus(),
            ]);

            return true;
        }

        // API failed to finish, but messages were sent - still mark as FINISHED
        // The lot MUST be closed because BpMessage won't accept more messages
        $this->logger->warning('BpMessage: Failed to finish lot via API, but messages were sent. Marking as FINISHED locally.', [
            'lot_id' => $lot->getId(),
            'error'  => $result['error'],
        ]);

        $lot->setStatus('FINISHED');
        $lot->setFinishedAt($now);
        $lot->setErrorMessage("Finish API call failed: {$result['error']}");
        $this->entityManager->flush();

        // Force update with SQL to ensure the lot is marked as FINISHED
        // This is CRITICAL - the lot cannot remain OPEN
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'UPDATE bpmessage_lot SET status = ?, finished_at = ?, error_message = ? WHERE id = ?',
            ['FINISHED', $now->format('Y-m-d H:i:s'), "Finish API call failed: {$result['error']}", $lot->getId()]
        );

        // Refresh to get latest data
        $this->entityManager->refresh($lot);

        $this->logger->info('BpMessage: Lot marked as FINISHED locally', [
            'lot_id' => $lot->getId(),
            'status' => $lot->getStatus(),
        ]);

        return true; // Consider it success since messages were sent
    }

    /**
     * Process a lot (send messages and finish).
     */
    public function processLot(BpMessageLot $lot): bool
    {
        $success = $this->sendLotMessages($lot);

        if (!$success) {
            $lot->setStatus('FAILED');

            // Only set generic error if no specific error was set during sendLotMessages
            if (!$lot->getErrorMessage()) {
                $lot->setErrorMessage('Failed to send messages - check individual message errors');
            }

            $this->entityManager->flush();

            return false;
        }

        return $this->finishLot($lot);
    }

    /**
     * Retry failed messages.
     *
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
     * Parse datetime value from form to DateTime object.
     *
     * @param mixed  $value   Value from form (can be DateTime, string, or null)
     * @param string $default Default value if null
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
