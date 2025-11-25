<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Doctrine\ORM\EntityManager;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use MauticPlugin\MauticBpMessageBundle\Exception\LotCreationException;
use MauticPlugin\MauticBpMessageBundle\Http\BpMessageClient;
use Psr\Log\LoggerInterface;

/**
 * Service to manage BpMessage email lots (creation, queuing, sending, finishing).
 */
class EmailLotManager
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
     * Get or create an active email lot for a campaign.
     *
     * @param array $config Action configuration
     *
     * @throws LotCreationException if lot creation fails
     */
    public function getOrCreateActiveLot(Campaign $campaign, array $config): BpMessageLot
    {
        // Check if there's an open email lot for this campaign with matching configuration
        // Email lots are unique by: campaignId + idServiceSettings (idQuotaSettings is always 0 for emails)
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.campaignId = :campaignId')
            ->andWhere('l.status = :status')
            ->andWhere('l.idQuotaSettings = 0')
            ->andWhere('l.idServiceSettings = :idServiceSettings')
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('status', 'OPEN')
            ->setParameter('idServiceSettings', (int) $config['id_service_settings'])
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1);

        $lot = $qb->getQuery()->getOneOrNullResult();

        // Check if lot can be reused - endDate expiration has PRIORITY
        if (null !== $lot && !$lot->isExpiredByEndDate() && !$lot->shouldCloseByCount() && !$lot->shouldCloseByTime()) {
            $this->logger->info('BpMessage Email: Using existing OPEN lot', [
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

            $this->logger->info('BpMessage Email: Cannot reuse lot, creating new one', [
                'lot_id'  => $lot->getId(),
                'reasons' => implode(', ', $reasons),
            ]);
        }

        // Check if there's a recent CREATING lot (within last 60 seconds) to prevent duplicates
        // This happens when multiple leads are processed in quick succession
        // Must match same configuration: idServiceSettings (idQuotaSettings is always 0 for emails)
        $recentThreshold = new \DateTime('-60 seconds');
        $qbCreating      = $this->entityManager->createQueryBuilder();
        $qbCreating->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.campaignId = :campaignId')
            ->andWhere('l.status = :status')
            ->andWhere('l.idQuotaSettings = 0')
            ->andWhere('l.idServiceSettings = :idServiceSettings')
            ->andWhere('l.createdAt > :threshold')
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('status', 'CREATING')
            ->setParameter('idServiceSettings', (int) $config['id_service_settings'])
            ->setParameter('threshold', $recentThreshold)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1);

        $creatingLot = $qbCreating->getQuery()->getOneOrNullResult();

        if (null !== $creatingLot) {
            $this->logger->info('BpMessage Email: Reusing recent CREATING lot to prevent duplicates', [
                'lot_id'      => $creatingLot->getId(),
                'campaign_id' => $campaign->getId(),
                'created_at'  => $creatingLot->getCreatedAt()->format('Y-m-d H:i:s'),
            ]);

            return $creatingLot;
        }

        // Create new email lot
        $this->logger->info('BpMessage Email: Creating new lot', [
            'campaign_id'   => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
        ]);

        return $this->createLot($campaign, $config);
    }

    /**
     * Create a new email lot.
     *
     * @throws LotCreationException if lot creation fails
     */
    private function createLot(Campaign $campaign, array $config): BpMessageLot
    {
        // Create lot entity
        $lot = new BpMessageLot();
        $lot->setName($config['lot_name'] ?? "Email Campaign {$campaign->getName()}");

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
                    $this->logger->warning('BpMessage Email: Invalid startDate format, using current time', [
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
                    $this->logger->warning('BpMessage Email: Invalid endDate format, calculating from startDate + timeWindow', [
                        'endDate' => $config['lot_data']['endDate'],
                        'error'   => $e->getMessage(),
                    ]);
                    $endDate = (clone $startDate)->modify("+{$timeWindow} seconds");
                }
            }
        }

        $lot->setStartDate($startDate);
        $lot->setEndDate($endDate);
        $lot->setUserCpf('system');
        $lot->setIdQuotaSettings(0); // Not used for email lots
        $lot->setIdServiceSettings((int) $config['id_service_settings']);
        $lot->setCampaignId($campaign->getId());
        $lot->setApiBaseUrl($config['api_base_url']);
        $lot->setBatchSize((int) ($config['batch_size'] ?? $config['default_batch_size'] ?? 1000));
        $lot->setTimeWindow($timeWindow);
        $lot->setStatus('CREATING');

        // Set book_business_foreign_id if provided
        if (!empty($config['book_business_foreign_id'])) {
            $lot->setBookBusinessForeignId($config['book_business_foreign_id']);
        }

        // Save to database first
        $this->entityManager->persist($lot);
        $this->entityManager->flush();

        // Call BpMessage API to create email lot
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
            'user'              => 'system',
            'idServiceSettings' => $lot->getIdServiceSettings(),
        ];

        // Add optional fields
        if (!empty($config['crm_id'])) {
            $lotData['crmId'] = (int) $config['crm_id'];
        }

        if (!empty($config['book_business_foreign_id'])) {
            $lotData['bookBusinessForeignId'] = $config['book_business_foreign_id'];
        }

        if (!empty($config['step_foreign_id'])) {
            $lotData['stepForeignId'] = $config['step_foreign_id'];
        }

        if (isset($config['is_radar_lot'])) {
            $lotData['isRadarLot'] = (bool) $config['is_radar_lot'];
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
            $result = $this->client->createEmailLot($lotData);

            if (!$result['success']) {
                $lot->setStatus('FAILED');
                $lot->setErrorMessage($result['error']);
                $this->entityManager->flush();

                throw new LotCreationException("Failed to create email lot in BpMessage: {$result['error']}", $lot->getId());
            }

            // Update lot with external ID
            $lot->setExternalLotId((string) $result['idLotEmail']);
            $lot->setStatus('OPEN');

            // Ensure the entity is managed and flush immediately
            $this->entityManager->persist($lot);
            $this->entityManager->flush();

            // Verify persistence with a direct SQL update as fallback
            // This ensures the lot status is updated even if EntityManager has issues during batch processing
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET status = ?, externalLotId = ? WHERE id = ?',
                ['OPEN', (string) $result['idLotEmail'], $lot->getId()]
            );

            // Force refresh from database to ensure we have the latest data
            $this->entityManager->refresh($lot);

            $this->logger->info('BpMessage Email: Lot created successfully', [
                'lot_id'          => $lot->getId(),
                'external_lot_id' => $lot->getExternalLotId(),
                'status'          => $lot->getStatus(),
            ]);

            return $lot;
        } catch (LotCreationException $e) {
            // Already a LotCreationException, just re-throw it
            throw $e;
        } catch (\Exception $e) {
            // If any other exception occurs during API call, mark lot as FAILED
            $lot->setStatus('FAILED');
            $lot->setErrorMessage('API call exception: '.$e->getMessage());
            $this->entityManager->flush();

            $this->logger->error('BpMessage Email: Exception during lot creation', [
                'lot_id' => $lot->getId(),
                'error'  => $e->getMessage(),
            ]);

            // Wrap in LotCreationException to signal this is a lot-level error, not a lead error
            throw new LotCreationException('API call exception: '.$e->getMessage(), $lot->getId(), 0, $e);
        }
    }

    /**
     * Queue an email for a lot.
     */
    public function queueEmail(BpMessageLot $lot, Lead $lead, array $emailData): BpMessageQueue
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
            $this->logger->warning('BpMessage Email: Lead already queued', [
                'lot_id'  => $lot->getId(),
                'lead_id' => $lead->getId(),
            ]);

            throw new \RuntimeException("Lead {$lead->getId()} is already queued for lot {$lot->getId()}");
        }

        $queue = new BpMessageQueue();
        $queue->setLot($lot);
        $queue->setLead($lead);
        $queue->setPayloadArray($emailData);
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

        $this->logger->info('BpMessage Email: Email queued', [
            'lot_id'         => $lot->getId(),
            'lead_id'        => $lead->getId(),
            'queue_id'       => $queue->getId(),
            'messages_count' => $lot->getMessagesCount(),
        ]);

        return $queue;
    }

    /**
     * Send all pending emails for a lot.
     */
    public function sendLotEmails(BpMessageLot $lot): bool
    {
        if (!$lot->isOpen()) {
            $this->logger->warning('BpMessage Email: Cannot send emails, lot is not open', [
                'lot_id' => $lot->getId(),
                'status' => $lot->getStatus(),
            ]);

            return false;
        }

        $lot->setStatus('SENDING');
        $this->entityManager->flush();

        $this->logger->info('BpMessage Email: Starting to send lot emails', [
            'lot_id'          => $lot->getId(),
            'external_lot_id' => $lot->getExternalLotId(),
        ]);

        // Get all pending emails
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('q')
            ->from(BpMessageQueue::class, 'q')
            ->where('q.lot = :lot')
            ->andWhere('q.status = :status')
            ->setParameter('lot', $lot)
            ->setParameter('status', 'PENDING')
            ->orderBy('q.createdAt', 'ASC');

        $pendingEmails = $qb->getQuery()->getResult();

        if (empty($pendingEmails)) {
            $this->logger->warning('BpMessage Email: No pending emails to send', [
                'lot_id' => $lot->getId(),
            ]);

            return true;
        }

        $this->logger->info('BpMessage Email: Found pending emails', [
            'lot_id' => $lot->getId(),
            'count'  => count($pendingEmails),
        ]);

        // Send in batches of 5000 (BpMessage limit)
        $batches = array_chunk($pendingEmails, 5000);
        $success = true;

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->info('BpMessage Email: Sending batch', [
                'lot_id'      => $lot->getId(),
                'batch_index' => $batchIndex,
                'batch_size'  => count($batch),
            ]);

            $emails = array_map(function (BpMessageQueue $queue) {
                return $queue->getPayloadArray();
            }, $batch);

            $this->client->setBaseUrl($lot->getApiBaseUrl());
            $result = $this->client->addEmailsToLot((int) $lot->getExternalLotId(), $emails);

            if ($result['success']) {
                // Mark emails as sent
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

                $this->logger->info('BpMessage Email: Batch sent successfully', [
                    'lot_id'      => $lot->getId(),
                    'batch_index' => $batchIndex,
                ]);
            } else {
                $success = false;

                // Save error message to lot for user visibility
                $errorMessage = "Batch {$batchIndex} failed: ".$result['error'];
                $lot->setErrorMessage($errorMessage);
                $lot->setStatus('FAILED');

                // Mark emails as failed using bulk update for reliability
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

                $this->logger->error('BpMessage Email: Batch failed', [
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
     * Finish an email lot (close it in BpMessage).
     */
    public function finishLot(BpMessageLot $lot): bool
    {
        $this->logger->info('BpMessage Email: Finishing lot', [
            'lot_id'          => $lot->getId(),
            'external_lot_id' => $lot->getExternalLotId(),
        ]);

        $this->client->setBaseUrl($lot->getApiBaseUrl());
        $result = $this->client->finishEmailLot((int) $lot->getExternalLotId());

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

            $this->logger->info('BpMessage Email: Lot finished successfully', [
                'lot_id' => $lot->getId(),
                'status' => $lot->getStatus(),
            ]);

            return true;
        }

        // API failed to finish, but emails were sent - still mark as FINISHED
        // The lot MUST be closed because BpMessage won't accept more emails
        $this->logger->warning('BpMessage Email: Failed to finish lot via API, but emails were sent. Marking as FINISHED locally.', [
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

        $this->logger->info('BpMessage Email: Lot marked as FINISHED locally', [
            'lot_id' => $lot->getId(),
            'status' => $lot->getStatus(),
        ]);

        return true; // Consider it success since emails were sent
    }

    /**
     * Process an email lot (send emails and finish).
     */
    public function processLot(BpMessageLot $lot): bool
    {
        $success = $this->sendLotEmails($lot);

        if (!$success) {
            $lot->setStatus('FAILED');

            // Only set generic error if no specific error was set during sendLotEmails
            if (!$lot->getErrorMessage()) {
                $lot->setErrorMessage('Failed to send emails - check individual message errors');
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

        return $this->finishLot($lot);
    }
}
