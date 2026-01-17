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
 * Service to manage BpMessage email lots (creation, queuing, sending, finishing).
 */
class EmailLotManager
{
    private EntityManager $entityManager;
    private BpMessageClient $client;
    private LoggerInterface $logger;
    private ?EmailMessageMapper $emailMessageMapper = null;

    public function __construct(
        EntityManager $entityManager,
        BpMessageClient $client,
        LoggerInterface $logger,
        ?EmailMessageMapper $emailMessageMapper = null,
    ) {
        $this->entityManager      = $entityManager;
        $this->client             = $client;
        $this->logger             = $logger;
        $this->emailMessageMapper = $emailMessageMapper;
    }

    /**
     * Get or create an active email lot for a campaign event.
     *
     * Each campaign event gets its own lot to avoid email duplication conflicts
     * between different events that might use the same service settings.
     *
     * @param array $config Action configuration (must include 'event_id')
     *
     * @throws LotCreationException if lot creation fails
     */
    public function getOrCreateActiveLot(Campaign $campaign, array $config): BpMessageLot
    {
        // Get event_id from config - each event gets its own lot
        $eventId = isset($config['event_id']) ? (int) $config['event_id'] : null;

        // Check if there's an open email lot for this campaign event with matching configuration
        // Email lots are unique by: campaignId + eventId + idServiceSettings
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.campaignId = :campaignId')
            ->andWhere('l.status = :status')
            ->andWhere('l.idQuotaSettings = 0')
            ->andWhere('l.idServiceSettings = :idServiceSettings')
            ->setParameter('campaignId', $campaign->getId())
            ->setParameter('status', 'OPEN')
            ->setParameter('idServiceSettings', (int) $config['id_service_settings']);

        // Add event_id filter if available
        if (null !== $eventId) {
            $qb->andWhere('l.eventId = :eventId')
                ->setParameter('eventId', $eventId);
        } else {
            $qb->andWhere('l.eventId IS NULL');
        }

        $qb->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1);

        $lot = $qb->getQuery()->getOneOrNullResult();

        // Check if lot can be reused: not closed by count or time
        if (null !== $lot && !$lot->shouldCloseByCount() && !$lot->shouldCloseByTime()) {
            $this->logger->info('BpMessage Email: Using existing OPEN lot', [
                'lot_id'          => $lot->getId(),
                'external_lot_id' => $lot->getExternalLotId(),
                'campaign_id'     => $campaign->getId(),
                'event_id'        => $eventId,
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

            $this->logger->info('BpMessage Email: Cannot reuse lot, creating new one', [
                'lot_id'   => $lot->getId(),
                'event_id' => $eventId,
                'reasons'  => implode(', ', $reasons),
            ]);
        }

        // Create new email lot (local only - API call happens during processing)
        $this->logger->info('BpMessage Email: Creating new lot', [
            'campaign_id'   => $campaign->getId(),
            'campaign_name' => $campaign->getName(),
            'event_id'      => $eventId,
        ]);

        return $this->createLot($campaign, $config);
    }

    /**
     * Create a new email lot (local only - API call happens during processing).
     *
     * This method creates a local lot record without calling the BpMessage API.
     * The API call to create the lot happens when the lot is processed (processLot).
     * This prevents creating empty lots in the API when contacts don't have email addresses.
     *
     * Note: startDate and endDate are calculated when the lot is created in BpMessage API,
     * not here. This ensures the dates are accurate at the time of actual processing.
     */
    private function createLot(Campaign $campaign, array $config): BpMessageLot
    {
        // Create lot entity
        $lot = new BpMessageLot();
        $lot->setLotType('email'); // Explicitly set as email lot
        // Use event name if available, otherwise fallback to campaign name
        $lotName = $config['event_name'] ?? "Email Campaign {$campaign->getName()}";
        $lot->setName($lotName);

        $timeWindow = (int) ($config['time_window'] ?? $config['default_time_window'] ?? 300);

        // Set placeholder dates - actual dates will be calculated when lot is created in API
        $now = new \DateTime();
        $lot->setStartDate($now);
        $lot->setEndDate((clone $now)->modify("+{$timeWindow} seconds"));

        $lot->setUserCpf('system');
        $lot->setIdQuotaSettings(0); // Not used for email lots
        $lot->setIdServiceSettings((int) $config['id_service_settings']);
        $lot->setCampaignId($campaign->getId());

        // Set event_id to ensure each campaign event has its own lot
        if (isset($config['event_id'])) {
            $lot->setEventId((int) $config['event_id']);
        }

        $lot->setApiBaseUrl($config['api_base_url']);
        $lot->setBatchSize((int) ($config['batch_size'] ?? $config['default_batch_size'] ?? 1000));
        $lot->setTimeWindow($timeWindow);
        $lot->setStatus('OPEN');

        // Set CRM ID and Book Business Foreign ID (Carteira) if provided
        if (!empty($config['crm_id'])) {
            $lot->setCrmId((string) $config['crm_id']);
        }
        if (!empty($config['book_business_foreign_id'])) {
            $lot->setBookBusinessForeignId((string) $config['book_business_foreign_id']);
        }

        // Save config for API payload creation during processing
        // Dates will be calculated at that time
        $lotConfig = [
            'name'              => $lot->getName(),
            'user'              => 'system',
            'idServiceSettings' => $lot->getIdServiceSettings(),
        ];

        // Add optional fields - keep as strings to preserve leading zeros and alphanumeric values
        if (!empty($config['crm_id'])) {
            $lotConfig['crmId'] = (string) $config['crm_id'];
        }

        if (!empty($config['book_business_foreign_id'])) {
            $lotConfig['bookBusinessForeignId'] = (string) $config['book_business_foreign_id'];
        }

        if (!empty($config['step_foreign_id'])) {
            $lotConfig['stepForeignId'] = $config['step_foreign_id'];
        }

        if (isset($config['is_radar_lot'])) {
            $lotConfig['isRadarLot'] = (bool) $config['is_radar_lot'];
        }

        // Include lot_data for custom fields (but NOT dates - those are calculated during processing)
        if (!empty($config['lot_data']) && is_array($config['lot_data'])) {
            // Remove date fields from lot_data - they will be calculated during processing
            $lotDataWithoutDates = $config['lot_data'];
            unset($lotDataWithoutDates['startDate'], $lotDataWithoutDates['endDate']);
            $lotConfig = array_merge($lotConfig, $lotDataWithoutDates);
        }

        // Save email config for refreshing contact at dispatch time
        $lotConfig['email_field'] = $config['email_field'] ?? 'email';
        $lotConfig['email_limit'] = (int) ($config['email_limit'] ?? 0);

        // Save CPF/CNPJ and Contract field config for payload population
        $lotConfig['cpf_cnpj_field'] = $config['cpf_cnpj_field'] ?? '';
        $lotConfig['contract_field'] = $config['contract_field'] ?? '';

        $lot->setCreateLotPayload($lotConfig);

        // Save to database
        $this->entityManager->persist($lot);
        $this->entityManager->flush();

        $this->logger->info('BpMessage Email: Lot created locally (dates will be calculated during API creation)', [
            'lot_id'      => $lot->getId(),
            'campaign_id' => $campaign->getId(),
            'status'      => $lot->getStatus(),
        ]);

        return $lot;
    }

    /**
     * Queue an email for a lot.
     */
    public function queueEmail(BpMessageLot $lot, Lead $lead, array $emailData): BpMessageQueue
    {
        return $this->queueEmailWithStatus($lot, $lead, $emailData, 'PENDING');
    }

    /**
     * Queue an email for a lot with a specific status.
     * Use this to register contacts that should be marked as FAILED immediately (e.g., missing email).
     *
     * @param string      $status       Status to set (PENDING, FAILED)
     * @param string|null $errorMessage Error message if status is FAILED
     */
    public function queueEmailWithStatus(
        BpMessageLot $lot,
        Lead $lead,
        array $emailData,
        string $status = 'PENDING',
        ?string $errorMessage = null,
    ): BpMessageQueue {
        // Get the email address from payload for duplicate checking
        $emailTo = $emailData['to'] ?? '';

        // Check if this specific email is already queued (allows multiple emails per lead for Collection fields)
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('q')
            ->from(BpMessageQueue::class, 'q')
            ->where('q.lot = :lot')
            ->andWhere('q.lead = :lead')
            ->setParameter('lot', $lot)
            ->setParameter('lead', $lead);

        $existingQueues = $qb->getQuery()->getResult();

        // Check if this exact email address is already queued
        foreach ($existingQueues as $existingQueue) {
            $existingPayload = $existingQueue->getPayloadArray();
            $existingTo      = $existingPayload['to'] ?? '';

            if ($existingTo === $emailTo) {
                $this->logger->warning('BpMessage Email: Email already queued for this lead', [
                    'lot_id'   => $lot->getId(),
                    'lead_id'  => $lead->getId(),
                    'email_to' => $emailTo,
                ]);

                throw new \RuntimeException("Email {$emailTo} for Lead {$lead->getId()} is already queued for lot {$lot->getId()}");
            }
        }

        $queue = new BpMessageQueue();
        $queue->setLot($lot);
        $queue->setLead($lead);
        $queue->setPayloadArray($emailData);
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

        $this->logger->info('BpMessage Email: Email queued', [
            'lot_id'         => $lot->getId(),
            'lead_id'        => $lead->getId(),
            'queue_id'       => $queue->getId(),
            'status'         => $status,
            'messages_count' => $lot->getMessagesCount(),
        ]);

        return $queue;
    }

    /**
     * Send all pending emails for a lot.
     * Note: This method expects the lot to already have an externalLotId (created via createLotInApi).
     */
    public function sendLotEmails(BpMessageLot $lot): bool
    {
        // Verify lot has external ID (should be created by processLot -> createLotInApi)
        if (empty($lot->getExternalLotId())) {
            $errorMessage = 'Lot has no external ID - lot must be created in API first';
            $lot->setErrorMessage($errorMessage);
            $lot->setStatus('FAILED');
            $this->entityManager->flush();

            $this->logger->error('BpMessage Email: '.$errorMessage, [
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

        // Get email config from lot payload for refreshing contact at dispatch time
        $lotConfig   = $lot->getCreateLotPayload();
        $emailConfig = [
            'email_field'    => $lotConfig['email_field'] ?? 'email',
            'email_limit'    => $lotConfig['email_limit'] ?? 0,
            'cpf_cnpj_field' => $lotConfig['cpf_cnpj_field'] ?? '',
            'contract_field' => $lotConfig['contract_field'] ?? '',
        ];

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

        // Get database connection once for all batches
        $connection = $this->entityManager->getConnection();

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->info('BpMessage Email: Sending batch', [
                'lot_id'      => $lot->getId(),
                'batch_index' => $batchIndex,
                'batch_size'  => count($batch),
            ]);

            $emails       = [];
            $validQueues  = [];
            $failedQueues = [];

            // Track updated payloads for persistence
            $updatedPayloads = [];

            // Track new queue entries created for additional emails
            $newQueuesCreated = [];

            // OPTIMIZATION: Fetch all emails in a single query instead of N queries
            if (null !== $this->emailMessageMapper) {
                $emailField    = $emailConfig['email_field'] ?? 'email';
                $emailLimit    = (int) ($emailConfig['email_limit'] ?? 0);
                $cpfCnpjField  = $emailConfig['cpf_cnpj_field'] ?? '';
                $contractField = $emailConfig['contract_field'] ?? '';

                // Build map of leadId -> queue for quick lookup
                $leadIds = [];
                foreach ($batch as $queue) {
                    $leadIds[] = $queue->getLead()->getId();
                }
                $leadIds = array_unique($leadIds);

                // Build SELECT fields dynamically
                $selectFields = "id, {$emailField} as email_value";
                if (!empty($cpfCnpjField)) {
                    $selectFields .= ", {$cpfCnpjField} as cpf_cnpj_value";
                }
                if (!empty($contractField)) {
                    $selectFields .= ", {$contractField} as contract_value";
                }

                // Single query to fetch all values
                $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
                $emailsData   = $connection->fetchAllAssociative(
                    "SELECT {$selectFields} FROM leads WHERE id IN ({$placeholders})",
                    $leadIds
                );

                // Index lead data by lead ID
                $leadsDataById = [];
                foreach ($emailsData as $row) {
                    $leadsDataById[$row['id']] = $row;
                }

                // Process each queue item using pre-fetched data
                // Expand placeholder to multiple emails based on email_limit
                foreach ($batch as $queue) {
                    $leadId   = $queue->getLead()->getId();
                    $leadData = $leadsDataById[$leadId] ?? [];
                    $emailValue = $leadData['email_value'] ?? null;

                    // Parse ALL emails from field (not just first)
                    $allEmails = $this->emailMessageMapper->parseAllEmailValues($emailValue);

                    if (empty($allEmails)) {
                        $failedQueues[] = [
                            'queue' => $queue,
                            'error' => 'Contato sem email no momento do disparo',
                        ];
                        continue;
                    }

                    // Apply email_limit if configured
                    if ($emailLimit > 0 && count($allEmails) > $emailLimit) {
                        $emailsToUse = array_slice($allEmails, 0, $emailLimit);
                        $this->logger->debug('BpMessage Email: Applying email_limit', [
                            'lead_id'       => $leadId,
                            'total_emails'  => count($allEmails),
                            'email_limit'   => $emailLimit,
                            'emails_to_use' => count($emailsToUse),
                        ]);
                    } else {
                        $emailsToUse = $allEmails;
                    }

                    // Build traceability list of all emails from lead field
                    $leadEmailsList = array_map(fn ($e) => $e['email'], $allEmails);

                    // Create one message for each email (up to email_limit)
                    foreach ($emailsToUse as $emailIndex => $emailData) {
                        $emailAddress = $emailData['email'];

                        // Build payload
                        $payload       = $queue->getPayloadArray();
                        $payload['to'] = $emailAddress;
                        $payload['_email_source'] = 'lead';
                        $payload['_email_field']  = $emailField;

                        // Add cpfCnpjReceiver and contract to payload (if fields configured)
                        if (!empty($cpfCnpjField) && !empty($leadData['cpf_cnpj_value'])) {
                            $payload['cpfCnpjReceiver'] = preg_replace('/\D/', '', $leadData['cpf_cnpj_value']);
                        }
                        if (!empty($contractField) && !empty($leadData['contract_value'])) {
                            $payload['contract'] = $leadData['contract_value'];
                        }

                        // Traceability
                        $payload['_selected_email']   = $emailAddress;
                        $payload['_email_index']      = $emailIndex;
                        $payload['_lead_emails_list'] = $leadEmailsList;

                        if (0 === $emailIndex) {
                            // First email: update original placeholder queue
                            $emails[] = $payload;
                            $updatedPayloads[$queue->getId()] = $payload;
                            $validQueues[] = $queue;
                        } else {
                            // Additional emails: create new queue entries
                            $newQueue = new BpMessageQueue();
                            $newQueue->setLead($queue->getLead());
                            $newQueue->setLot($lot);
                            $newQueue->setStatus('PENDING');
                            $newQueue->setPayloadJson(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                            $this->entityManager->persist($newQueue);

                            $emails[] = $payload;
                            $validQueues[] = $newQueue;
                            $newQueuesCreated[] = $newQueue;

                            $this->logger->debug('BpMessage Email: Created additional queue entry for email_limit', [
                                'lead_id'     => $leadId,
                                'lot_id'      => $lot->getId(),
                                'email_index' => $emailIndex,
                                'email'       => $emailAddress,
                            ]);
                        }
                    }
                }

                // Flush new queue entries to database
                if (!empty($newQueuesCreated)) {
                    $this->entityManager->flush();
                    $this->logger->info('BpMessage Email: Created additional queue entries for email_limit', [
                        'lot_id'           => $lot->getId(),
                        'new_queues_count' => count($newQueuesCreated),
                    ]);
                }
            } else {
                // Fallback: use stored payload (legacy behavior)
                foreach ($batch as $queue) {
                    $emails[]      = $queue->getPayloadArray();
                    $validQueues[] = $queue;
                }
            }

            // OPTIMIZATION: Batch update payloads using single query with CASE WHEN
            if (!empty($updatedPayloads)) {
                // Build batch update query
                $cases  = [];
                $ids    = [];
                $params = [];
                foreach ($updatedPayloads as $queueId => $payload) {
                    $cases[]  = "WHEN id = ? THEN ?";
                    $params[] = $queueId;
                    $params[] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $ids[]    = $queueId;
                }

                $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $sql            = "UPDATE bpmessage_queue SET payload_json = CASE ".implode(' ', $cases)." END WHERE id IN ({$idPlaceholders})";
                $params         = array_merge($params, $ids);

                $connection->executeStatement($sql, $params);

                $this->logger->debug('BpMessage Email: Updated queue payloads with refreshed emails (batch)', [
                    'lot_id'        => $lot->getId(),
                    'updated_count' => count($updatedPayloads),
                ]);
            }

            // OPTIMIZATION: Batch update failed queues
            if (!empty($failedQueues)) {
                $failedIds = array_map(fn ($f) => $f['queue']->getId(), $failedQueues);
                $placeholders = implode(',', array_fill(0, count($failedIds), '?'));
                $params       = array_merge(['FAILED', 'Contato sem email no momento do disparo'], $failedIds);
                $connection->executeStatement(
                    "UPDATE bpmessage_queue SET status = ?, error_message = ? WHERE id IN ({$placeholders})",
                    $params
                );

                $this->logger->info('BpMessage Email: Marked failed emails (no valid email at dispatch time)', [
                    'lot_id'       => $lot->getId(),
                    'failed_count' => count($failedQueues),
                ]);
            }

            // Skip API call if no valid emails
            if (empty($emails)) {
                $this->logger->warning('BpMessage Email: No valid emails in batch after email refresh', [
                    'lot_id'      => $lot->getId(),
                    'batch_index' => $batchIndex,
                ]);
                continue;
            }

            $this->client->setBaseUrl($lot->getApiBaseUrl());
            $result = $this->client->addEmailsToLot((int) $lot->getExternalLotId(), $emails);

            if ($result['success']) {
                // Mark emails as sent
                $ids = array_map(function (BpMessageQueue $queue) {
                    return $queue->getId();
                }, $validQueues);

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
                $errorMessage = $result['error'];
                $lot->setErrorMessage($errorMessage);
                $lot->setStatus('FAILED');

                // Mark emails as failed using bulk update for reliability
                $queueIds = array_map(function (BpMessageQueue $queue) {
                    return $queue->getId();
                }, $validQueues);

                $this->entityManager->flush();

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

        $this->logger->info('BpMessage Email: Lot marked as FINISHED locally', [
            'lot_id' => $lot->getId(),
            'status' => $lot->getStatus(),
        ]);

        return true; // Consider it success since emails were sent
    }

    /**
     * Process an email lot (create in API, send emails, and finish).
     *
     * This method handles the complete lot lifecycle:
     * 1. Create lot in BpMessage API (if not already created)
     * 2. Send all pending emails to the API
     * 3. Finalize the lot in the API
     */
    public function processLot(BpMessageLot $lot): bool
    {
        // Step 1: Create lot in BpMessage API if not already created
        if (empty($lot->getExternalLotId())) {
            $this->logger->info('BpMessage Email: Creating lot in API before processing', [
                'lot_id' => $lot->getId(),
            ]);

            if (!$this->createLotInApi($lot)) {
                // createLotInApi already sets error message and status
                return false;
            }
        }

        // Step 2: Send all pending emails
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

        // Step 3: Finalize the lot
        return $this->finishLot($lot);
    }

    /**
     * Create an email lot in the BpMessage API.
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

            $this->logger->error('BpMessage Email: '.$errorMessage, [
                'lot_id' => $lot->getId(),
            ]);

            return false;
        }

        // Update dates to current time since lot creation was deferred
        // Use default PHP timezone (America/Sao_Paulo) for consistency
        $now        = new \DateTime();
        $timeWindow = $lot->getTimeWindow();
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

        $this->logger->info('BpMessage Email: Creating lot in API', [
            'lot_id'  => $lot->getId(),
            'payload' => $lotData,
        ]);

        try {
            $result = $this->client->createEmailLot($lotData);

            if (!$result['success']) {
                // Translate API error to user-friendly message
                $friendlyError = $this->translateApiError($result['error'], $lot->getName());

                $lot->setStatus('FAILED');
                $lot->setErrorMessage($friendlyError);
                $this->entityManager->flush();

                // Force SQL update
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement(
                    'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                    ['FAILED', $friendlyError, $lot->getId()]
                );

                $this->logger->error('BpMessage Email: API CreateEmailLot failed', [
                    'lot_id'         => $lot->getId(),
                    'error'          => $result['error'],
                    'friendly_error' => $friendlyError,
                ]);

                return false;
            }

            // Update lot with external ID
            $lot->setExternalLotId((string) $result['idLotEmail']);
            $this->entityManager->persist($lot);
            $this->entityManager->flush();

            // Force SQL update
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET external_lot_id = ? WHERE id = ?',
                [(string) $result['idLotEmail'], $lot->getId()]
            );

            $this->logger->info('BpMessage Email: Lot created successfully in API', [
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

            $this->logger->error('BpMessage Email: Exception creating email lot in API', [
                'lot_id' => $lot->getId(),
                'error'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Translate API error messages to user-friendly messages.
     *
     * @param string      $apiError  The original API error message
     * @param string|null $lotName   Optional lot name for context
     */
    private function translateApiError(string $apiError, ?string $lotName = null): string
    {
        // Map of API error patterns to friendly messages
        $errorMappings = [
            "'Id Quota Settings' must not be equal to '0'" => $lotName
                ? "Não foi possível criar o lote: '{$lotName}' não possui configuração de cota válida. Entre em contato com o administrador ou selecione outra rota."
                : 'A rota selecionada não possui configuração de cota válida. Selecione outra rota ou entre em contato com o administrador.',
            "'Crm Id' must not be equal to '0'" => 'O CRM ID não está configurado corretamente. Verifique a configuração da ação de campanha.',
            "'Book Business Foreign Id' must not be empty" => 'A Carteira (Book Business Foreign Id) não está configurada. Verifique a configuração da ação de campanha.',
            'Não há rota padrão configurada' => 'Não há rota padrão configurada para envio de e-mail. Configure uma rota padrão no painel BpMessage.',
        ];

        // Check each mapping
        foreach ($errorMappings as $pattern => $friendlyMessage) {
            if (str_contains($apiError, $pattern)) {
                return $friendlyMessage;
            }
        }

        // Return original error if no mapping found
        return $apiError;
    }
}
