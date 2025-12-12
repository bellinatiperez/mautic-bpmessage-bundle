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

        // Check if lot can be reused: not closed by count or time
        if (null !== $lot && !$lot->shouldCloseByCount() && !$lot->shouldCloseByTime()) {
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

        // Create new lot (local only - API call happens during processing)
        $this->logger->info('BpMessage: Creating new lot', [
            'campaign_id'   => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
        ]);

        return $this->createLot($campaign, $config);
    }

    /**
     * Create a new lot (local only - API call happens during processing).
     *
     * This method creates a local lot record without calling the BpMessage API.
     * The API call to create the lot happens when the lot is processed (processLot).
     * This prevents creating empty lots in the API when contacts don't have phone numbers.
     *
     * Note: startDate and endDate are calculated when the lot is created in BpMessage API,
     * not here. This ensures the dates are accurate at the time of actual processing.
     */
    private function createLot(Campaign $campaign, array $config): BpMessageLot
    {
        // Create lot entity
        $lot = new BpMessageLot();
        $lot->setName($config['lot_name'] ?? "Campaign {$campaign->getName()}");

        $timeWindow = (int) ($config['time_window'] ?? $config['default_time_window'] ?? 300);

        // Set placeholder dates - actual dates will be calculated when lot is created in API
        $now = new \DateTime();
        $lot->setStartDate($now);
        $lot->setEndDate((clone $now)->modify("+{$timeWindow} seconds"));

        $lot->setUserCpf('system');
        $lot->setIdQuotaSettings((int) $config['id_quota_settings']);
        $lot->setIdServiceSettings((int) $config['id_service_settings']);
        $lot->setServiceType((int) ($config['service_type'] ?? 2));
        $lot->setCampaignId($campaign->getId());
        $lot->setApiBaseUrl($config['api_base_url']);
        $lot->setBatchSize((int) ($config['batch_size'] ?? $config['default_batch_size'] ?? 1000));
        $lot->setTimeWindow($timeWindow);
        $lot->setStatus('OPEN');

        if (!empty($config['image_url'])) {
            $lot->setImageUrl($config['image_url']);
        }

        if (!empty($config['image_name'])) {
            $lot->setImageName($config['image_name']);
        }

        // Save config for API payload creation during processing
        // Dates will be calculated at that time
        $lotConfig = [
            'name'              => $lot->getName(),
            'user'              => 'system',
            'idQuotaSettings'   => $lot->getIdQuotaSettings(),
            'idServiceSettings' => $lot->getIdServiceSettings(),
        ];

        if (!empty($config['image_url'])) {
            $lotConfig['imageUrl'] = $config['image_url'];
        }

        if (!empty($config['image_name'])) {
            $lotConfig['imageName'] = $config['image_name'];
        }

        // Include lot_data for custom fields (but NOT dates - those are calculated during processing)
        if (!empty($config['lot_data']) && is_array($config['lot_data'])) {
            // Remove date fields from lot_data - they will be calculated during processing
            $lotDataWithoutDates = $config['lot_data'];
            unset($lotDataWithoutDates['startDate'], $lotDataWithoutDates['endDate']);
            $lotConfig = array_merge($lotConfig, $lotDataWithoutDates);
        }

        $lot->setCreateLotPayload($lotConfig);

        // Save to database
        $this->entityManager->persist($lot);
        $this->entityManager->flush();

        $this->logger->info('BpMessage: Lot created locally (dates will be calculated during API creation)', [
            'lot_id'      => $lot->getId(),
            'campaign_id' => $campaign->getId(),
            'status'      => $lot->getStatus(),
        ]);

        return $lot;
    }

    /**
     * Queue a message for a lot.
     *
     * @return BpMessageQueue|null Returns null if duplicate (same lead+phone already queued)
     */
    public function queueMessage(BpMessageLot $lot, Lead $lead, array $messageData): ?BpMessageQueue
    {
        return $this->queueMessageWithStatus($lot, $lead, $messageData, 'PENDING');
    }

    /**
     * Queue a message for a lot with a specific status.
     * Use this to register contacts that should be marked as FAILED immediately (e.g., missing phone).
     *
     * @param string      $status       Status to set (PENDING, FAILED)
     * @param string|null $errorMessage Error message if status is FAILED
     */
    public function queueMessageWithStatus(
        BpMessageLot $lot,
        Lead $lead,
        array $messageData,
        string $status = 'PENDING',
        ?string $errorMessage = null,
    ): ?BpMessageQueue {
        // Build unique key for duplicate check: lead_id + areaCode + phone (for collection fields support)
        $areaCode = $messageData['areaCode'] ?? '';
        $phone    = $messageData['phone'] ?? '';

        // Check if this specific lead+areaCode+phone combination is already queued
        $conn  = $this->entityManager->getConnection();
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM bpmessage_queue WHERE lot_id = ? AND lead_id = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.areaCode")) = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, "$.phone")) = ?',
            [$lot->getId(), $lead->getId(), $areaCode, $phone]
        );

        if ($count > 0) {
            $this->logger->debug('BpMessage: Lead+phone already queued, skipping', [
                'lot_id'   => $lot->getId(),
                'lead_id'  => $lead->getId(),
                'areaCode' => $areaCode,
                'phone'    => $phone,
            ]);

            // Return null to indicate duplicate - caller should continue to next phone
            return null;
        }

        $queue = new BpMessageQueue();
        $queue->setLot($lot);
        $queue->setLead($lead);
        $queue->setPayloadArray($messageData);
        $queue->setStatus($status);

        if (null !== $errorMessage) {
            $queue->setErrorMessage($errorMessage);
        }

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
            'status'         => $status,
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
     * Note: This method expects the lot to already have an externalLotId (created via createLotInApi).
     */
    public function sendLotMessages(BpMessageLot $lot): bool
    {
        // Verify lot has external ID (should be created by processLot -> createLotInApi)
        if (empty($lot->getExternalLotId())) {
            $errorMessage = 'Lot has no external ID - lot must be created in API first';
            $lot->setErrorMessage($errorMessage);
            $lot->setStatus('FAILED');
            $this->entityManager->flush();

            $this->logger->error('BpMessage: '.$errorMessage, [
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
                $errorMessage = $result['error'];
                $lot->setErrorMessage($errorMessage);
                $lot->setStatus('FAILED');

                // Mark messages as failed using bulk update for reliability
                $queueIds = array_map(function (BpMessageQueue $queue) {
                    return $queue->getId();
                }, $batch);

                $this->entityManager->flush();

                // Force update with SQL to ensure persistence (EntityManager flush may not work in all scenarios)
                $connection = $this->entityManager->getConnection();

                // Update lot status and error message
                $connection->executeStatement(
                    'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                    ['FAILED', $errorMessage, $lot->getId()]
                );

                // Update queue items status and error message
                if (!empty($queueIds)) {
                    $placeholders = implode(',', array_fill(0, count($queueIds), '?'));
                    $params       = array_merge(['FAILED', $result['error']], $queueIds);
                    $connection->executeStatement(
                        "UPDATE bpmessage_queue SET status = ?, error_message = ?, retry_count = retry_count + 1 WHERE id IN ({$placeholders})",
                        $params
                    );
                }

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
        $lot->setErrorMessage($result['error']);
        $this->entityManager->flush();

        // Force update with SQL to ensure the lot is marked as FINISHED
        // This is CRITICAL - the lot cannot remain OPEN
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'UPDATE bpmessage_lot SET status = ?, finished_at = ?, error_message = ? WHERE id = ?',
            ['FINISHED', $now->format('Y-m-d H:i:s'), $result['error'], $lot->getId()]
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
     * Process a lot (create in API, send messages, and finish).
     *
     * This method handles the complete lot lifecycle:
     * 1. Create lot in BpMessage API (if not already created)
     * 2. Send all pending messages to the API
     * 3. Finalize the lot in the API
     */
    public function processLot(BpMessageLot $lot): bool
    {
        // Step 1: Create lot in BpMessage API if not already created
        if (empty($lot->getExternalLotId())) {
            $this->logger->info('BpMessage: Creating lot in API before processing', [
                'lot_id' => $lot->getId(),
            ]);

            if (!$this->createLotInApi($lot)) {
                // createLotInApi already sets error message and status
                return false;
            }
        }

        // Step 2: Send all pending messages
        $success = $this->sendLotMessages($lot);

        if (!$success) {
            $lot->setStatus('FAILED');

            // Only set generic error if no specific error was set during sendLotMessages
            if (!$lot->getErrorMessage()) {
                $lot->setErrorMessage('Failed to send messages - check individual message errors');
            }

            $this->entityManager->flush();

            // Force update with SQL to ensure persistence (EntityManager flush may not work in all scenarios)
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                ['FAILED', $lot->getErrorMessage(), $lot->getId()]
            );

            return false;
        }

        // Step 3: Finalize the lot
        return $this->finishLot($lot);
    }

    /**
     * Create a lot in the BpMessage API.
     *
     * @return bool True if creation succeeded, false otherwise
     */
    private function createLotInApi(BpMessageLot $lot): bool
    {
        // Get the saved payload
        $lotData = $lot->getCreateLotPayload();

        if (empty($lotData)) {
            $errorMessage = 'No payload saved for lot creation';
            $lot->setStatus('FAILED');
            $lot->setErrorMessage($errorMessage);
            $this->entityManager->flush();

            $this->logger->error('BpMessage: '.$errorMessage, [
                'lot_id' => $lot->getId(),
            ]);

            return false;
        }

        // Update dates to current time since lot creation was deferred
        $localTimezone = new \DateTimeZone('America/Sao_Paulo');
        $now           = new \DateTime('now', $localTimezone);
        $timeWindow    = $lot->getTimeWindow();
        $endDate       = (clone $now)->modify("+{$timeWindow} seconds");

        // Update the payload with fresh dates
        $lotData['startDate'] = $now->format('Y-m-d\TH:i:s.vP');
        $lotData['endDate']   = $endDate->format('Y-m-d\TH:i:s.vP');

        // Update lot entity dates
        $lot->setStartDate($now);
        $lot->setEndDate($endDate);
        $lot->setCreateLotPayload($lotData);
        $this->entityManager->flush();

        // Set API base URL
        $this->client->setBaseUrl($lot->getApiBaseUrl());

        $this->logger->info('BpMessage: Creating lot in API', [
            'lot_id'  => $lot->getId(),
            'payload' => $lotData,
        ]);

        try {
            $result = $this->client->createLot($lotData);

            if (!$result['success']) {
                $lot->setStatus('FAILED');
                $lot->setErrorMessage($result['error']);
                $this->entityManager->flush();

                // Force SQL update
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement(
                    'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                    ['FAILED', $result['error'], $lot->getId()]
                );

                $this->logger->error('BpMessage: API CreateLot failed', [
                    'lot_id' => $lot->getId(),
                    'error'  => $result['error'],
                ]);

                return false;
            }

            // Update lot with external ID
            $lot->setExternalLotId((string) $result['idLot']);
            $this->entityManager->persist($lot);
            $this->entityManager->flush();

            // Force SQL update
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET external_lot_id = ? WHERE id = ?',
                [(string) $result['idLot'], $lot->getId()]
            );

            $this->logger->info('BpMessage: Lot created successfully in API', [
                'lot_id'          => $lot->getId(),
                'external_lot_id' => $lot->getExternalLotId(),
            ]);

            return true;
        } catch (\Exception $e) {
            $lot->setStatus('FAILED');
            $lot->setErrorMessage($e->getMessage());
            $this->entityManager->flush();

            // Force SQL update
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                ['FAILED', $e->getMessage(), $lot->getId()]
            );

            $this->logger->error('BpMessage: Exception creating lot in API', [
                'lot_id' => $lot->getId(),
                'error'  => $e->getMessage(),
            ]);

            return false;
        }
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
