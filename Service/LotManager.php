<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Service;

use Doctrine\ORM\EntityManager;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticBpMessageBundle\Http\BpMessageClient;
use MauticPlugin\MauticBpMessageBundle\Http\CRMClient;
use MauticPlugin\MauticBpMessageBundle\Integration\BpMessageIntegration;
use Psr\Log\LoggerInterface;

/**
 * Service to manage BpMessage lots (creation, queuing, sending, finishing).
 */
class LotManager
{
    private EntityManager $entityManager;
    private BpMessageClient $client;
    private LoggerInterface $logger;
    private ?RoutesService $routesService = null;
    private ?MessageMapper $messageMapper = null;
    private ?CRMClient $crmClient = null;
    private ?IntegrationHelper $integrationHelper = null;

    public function __construct(
        EntityManager $entityManager,
        BpMessageClient $client,
        LoggerInterface $logger,
        ?RoutesService $routesService = null,
        ?MessageMapper $messageMapper = null,
        ?CRMClient $crmClient = null,
        ?IntegrationHelper $integrationHelper = null,
    ) {
        $this->entityManager     = $entityManager;
        $this->client            = $client;
        $this->logger            = $logger;
        $this->routesService     = $routesService;
        $this->messageMapper     = $messageMapper;
        $this->crmClient         = $crmClient;
        $this->integrationHelper = $integrationHelper;
    }

    /**
     * Set the routes service (for dependency injection).
     */
    public function setRoutesService(RoutesService $routesService): void
    {
        $this->routesService = $routesService;
    }

    /**
     * Configure CRM Client with integration settings.
     * Must be called before using CRM API features.
     *
     * @return bool True if CRM API is enabled and configured, false otherwise
     */
    private function configureCrmClient(): bool
    {
        if (null === $this->crmClient || null === $this->integrationHelper) {
            return false;
        }

        /** @var BpMessageIntegration|null $integration */
        $integration = $this->integrationHelper->getIntegrationObject('BpMessage');

        if (!$integration) {
            $this->logger->warning('BpMessage: Integration not found for CRM API');

            return false;
        }

        $settings = $integration->getIntegrationSettings();
        if (!$settings || !$settings->getIsPublished()) {
            $this->logger->warning('BpMessage: Integration not published for CRM API');

            return false;
        }

        $crmApiUrl = $integration->getCrmApiBaseUrl();
        $crmApiKey = $integration->getCrmApiKey();

        if (empty($crmApiUrl) || empty($crmApiKey)) {
            $this->logger->warning('BpMessage: CRM API URL or API Key not configured');

            return false;
        }

        // Configure the CRM client
        $this->crmClient->setBaseUrl($crmApiUrl);
        $this->crmClient->setApiKey($crmApiKey);

        $this->logger->info('BpMessage: CRM Client configured', [
            'api_url' => $crmApiUrl,
        ]);

        return true;
    }

    /**
     * Resolve idQuotaSettings from the config using GetRoutes API.
     *
     * @param array $config Action configuration containing id_service_settings, crm_id, book_business_foreign_id, service_type
     *
     * @return int|null The resolved idQuotaSettings or null if not found
     */
    private function resolveIdQuotaSettings(array $config): ?int
    {
        // Check if we have the required fields to resolve via GetRoutes
        if (empty($config['id_service_settings']) || empty($config['crm_id']) || empty($config['book_business_foreign_id'])) {
            $this->logger->warning('BpMessage LotManager: Missing required fields to resolve idQuotaSettings', [
                'id_service_settings'     => $config['id_service_settings'] ?? null,
                'crm_id'                  => $config['crm_id'] ?? null,
                'book_business_foreign_id' => $config['book_business_foreign_id'] ?? null,
            ]);

            return null;
        }

        if (null === $this->routesService) {
            $this->logger->error('BpMessage LotManager: RoutesService not available, cannot resolve idQuotaSettings');

            return null;
        }

        $idServiceSettings     = (int) $config['id_service_settings'];
        // Keep as strings to preserve leading zeros and alphanumeric values
        $crmId                 = (string) $config['crm_id'];
        $bookBusinessForeignId = (string) $config['book_business_foreign_id'];
        $serviceType           = (int) ($config['service_type'] ?? 1);

        $idQuotaSettings = $this->routesService->getQuotaSettingsForRoute(
            $idServiceSettings,
            $bookBusinessForeignId,
            $crmId,
            $serviceType
        );

        if (null === $idQuotaSettings) {
            $this->logger->error('BpMessage LotManager: Could not resolve idQuotaSettings for route', [
                'id_service_settings'      => $idServiceSettings,
                'crm_id'                   => $crmId,
                'book_business_foreign_id' => $bookBusinessForeignId,
                'service_type'             => $serviceType,
            ]);
        } else {
            $this->logger->info('BpMessage LotManager: Resolved idQuotaSettings from GetRoutes', [
                'id_service_settings' => $idServiceSettings,
                'id_quota_settings'   => $idQuotaSettings,
            ]);
        }

        return $idQuotaSettings;
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
        // Resolve idQuotaSettings from GetRoutes API
        $idQuotaSettings = $this->resolveIdQuotaSettings($config);

        if (null === $idQuotaSettings) {
            throw new \RuntimeException('Could not resolve idQuotaSettings for the selected route. Please check CRM ID, Carteira, and Service Type configuration.');
        }

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
            ->setParameter('idQuotaSettings', $idQuotaSettings)
            ->setParameter('idServiceSettings', (int) $config['id_service_settings'])
            ->setParameter('serviceType', (int) ($config['service_type'] ?? 2))
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1);

        $lot = $qb->getQuery()->getOneOrNullResult();

        // Store resolved idQuotaSettings in config for createLot
        $config['_resolved_id_quota_settings'] = $idQuotaSettings;

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
        // Use pre-resolved idQuotaSettings or resolve it now
        $idQuotaSettings = $config['_resolved_id_quota_settings'] ?? $this->resolveIdQuotaSettings($config);

        if (null === $idQuotaSettings) {
            throw new \RuntimeException('Could not resolve idQuotaSettings for lot creation. Please check CRM ID, Carteira, and Service Type configuration.');
        }

        // Create lot entity
        $lot = new BpMessageLot();
        $lot->setLotType('message'); // Explicitly set as message lot (SMS/WhatsApp/RCS)
        // Use event name if available, otherwise fallback to campaign name
        $lotName = $config['event_name'] ?? "Campaign {$campaign->getName()}";
        $lot->setName($lotName);

        $timeWindow = (int) ($config['time_window'] ?? $config['default_time_window'] ?? 300);

        // Set placeholder dates - actual dates will be calculated when lot is created in API
        $now = new \DateTime();
        $lot->setStartDate($now);
        $lot->setEndDate((clone $now)->modify("+{$timeWindow} seconds"));

        $lot->setUserCpf('system');
        $lot->setIdQuotaSettings($idQuotaSettings);
        $lot->setIdServiceSettings((int) $config['id_service_settings']);
        $lot->setServiceType((int) ($config['service_type'] ?? 2));
        $lot->setCampaignId($campaign->getId());
        $lot->setApiBaseUrl($config['api_base_url']);
        $lot->setBatchSize((int) ($config['batch_size'] ?? $config['default_batch_size'] ?? 1000));
        $lot->setTimeWindow($timeWindow);
        $lot->setStatus('OPEN');

        // Save CRM ID and Book Business Foreign ID (Carteira) on the lot entity
        if (!empty($config['crm_id'])) {
            $lot->setCrmId((string) $config['crm_id']);
        }
        if (!empty($config['book_business_foreign_id'])) {
            $lot->setBookBusinessForeignId((string) $config['book_business_foreign_id']);
        }

        if (!empty($config['image_url'])) {
            $lot->setImageUrl($config['image_url']);
        }

        if (!empty($config['image_name'])) {
            $lot->setImageName($config['image_name']);
        }

        // Save config for API payload creation during processing
        // Dates will be calculated at that time
        // Include bookBusinessForeignId and crmId for route name lookup on errors
        // Keep as strings to preserve leading zeros and alphanumeric values
        $lotConfig = [
            'name'                  => $lot->getName(),
            'user'                  => 'system',
            'idQuotaSettings'       => $lot->getIdQuotaSettings(),
            'idServiceSettings'     => $lot->getIdServiceSettings(),
            'bookBusinessForeignId' => (string) ($config['book_business_foreign_id'] ?? ''),
            'crmId'                 => (string) ($config['crm_id'] ?? ''),
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

        // Save phone config for refreshing contact at dispatch time
        $lotConfig['phone_field'] = $config['phone_field'] ?? 'mobile';
        $lotConfig['phone_limit'] = (int) ($config['phone_limit'] ?? 0);
        $lotConfig['phone_type_filter'] = $config['phone_type_filter'] ?? 'all';

        // Save phone source config for CRM API lookup
        $lotConfig['phone_source'] = $config['phone_source'] ?? 'lead';
        $lotConfig['cpf_cnpj_field'] = $config['cpf_cnpj_field'] ?? '';
        $lotConfig['contract_field'] = $config['contract_field'] ?? '';

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

        // Get phone config from lot payload for refreshing contact at dispatch time
        $lotConfig   = $lot->getCreateLotPayload();
        $phoneConfig = [
            'phone_field'       => $lotConfig['phone_field'] ?? 'mobile',
            'phone_limit'       => $lotConfig['phone_limit'] ?? 0,
            'phone_type_filter' => $lotConfig['phone_type_filter'] ?? 'all',
            'phone_source'      => $lotConfig['phone_source'] ?? 'lead',
            'cpf_cnpj_field'    => $lotConfig['cpf_cnpj_field'] ?? '',
            'contract_field'    => $lotConfig['contract_field'] ?? '',
        ];

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

        // Get database connection once for all batches
        $connection = $this->entityManager->getConnection();

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->info('BpMessage: Sending batch', [
                'lot_id'      => $lot->getId(),
                'batch_index' => $batchIndex,
                'batch_size'  => count($batch),
            ]);

            $messages     = [];
            $validQueues  = [];
            $failedQueues = [];

            // Track updated payloads for persistence
            $updatedPayloads = [];

            // Determine phone source: lead (from contact field) or crm_api (from external API)
            $phoneSource = $phoneConfig['phone_source'] ?? 'lead';

            // Configure CRM Client if using CRM API source
            $crmApiConfigured = false;
            if ('crm_api' === $phoneSource && null !== $this->crmClient) {
                $crmApiConfigured = $this->configureCrmClient();
                if (!$crmApiConfigured) {
                    $this->logger->warning('BpMessage: CRM API not configured, falling back to lead source');
                    $phoneSource = 'lead';
                }
            }

            if ('crm_api' === $phoneSource && $crmApiConfigured) {
                // PHONE SOURCE: CRM API
                // Fetch phones from external CRM API based on CPF/CNPJ
                $this->logger->info('BpMessage: Using CRM API as phone source', [
                    'lot_id'     => $lot->getId(),
                    'batch_size' => count($batch),
                ]);

                $cpfCnpjField  = $phoneConfig['cpf_cnpj_field'] ?? '';
                $contractField = $phoneConfig['contract_field'] ?? '';

                if (empty($cpfCnpjField)) {
                    $this->logger->error('BpMessage: CRM API phone source configured but cpf_cnpj_field is empty');
                    // Fallback to lead source
                    $phoneSource = 'lead';
                } else {
                    // Build map of leadId -> queue and fetch CPF/CNPJ values
                    $leadIds = [];
                    foreach ($batch as $queue) {
                        $leadIds[] = $queue->getLead()->getId();
                    }
                    $leadIds = array_unique($leadIds);

                    // Fetch CPF/CNPJ and contract values in a single query
                    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
                    $selectFields = "id, {$cpfCnpjField} as cpf_cnpj_value";
                    if (!empty($contractField)) {
                        $selectFields .= ", {$contractField} as contract_value";
                    }
                    $leadsData = $connection->fetchAllAssociative(
                        "SELECT {$selectFields} FROM leads WHERE id IN ({$placeholders})",
                        $leadIds
                    );

                    // Index lead data by ID
                    $leadsDataById = [];
                    foreach ($leadsData as $row) {
                        $leadsDataById[$row['id']] = $row;
                    }

                    // Fetch phones from CRM API for each unique CPF/CNPJ
                    $phonesByCpfCnpj = [];
                    $uniqueCpfCnpjs  = [];
                    foreach ($leadsDataById as $leadData) {
                        $cpfCnpj = preg_replace('/\D/', '', $leadData['cpf_cnpj_value'] ?? '');
                        if (!empty($cpfCnpj) && !isset($uniqueCpfCnpjs[$cpfCnpj])) {
                            $uniqueCpfCnpjs[$cpfCnpj] = true;
                        }
                    }

                    // Call CRM API for each unique CPF/CNPJ
                    foreach (array_keys($uniqueCpfCnpjs) as $cpfCnpj) {
                        // Ensure cpfCnpj is string (PHP converts numeric array keys to int)
                        $cpfCnpjStr = (string) $cpfCnpj;
                        $result = $this->crmClient->fetchPhones($cpfCnpjStr);
                        if ($result['success'] && !empty($result['phones'])) {
                            $phonesByCpfCnpj[$cpfCnpjStr] = $result['phones'];
                        } else {
                            $this->logger->warning('BpMessage: CRM API returned no phones', [
                                'cpfCnpj' => substr($cpfCnpjStr, 0, 3).'***'.substr($cpfCnpjStr, -2),
                                'error'   => $result['error'] ?? 'No phones found',
                            ]);
                            $phonesByCpfCnpj[$cpfCnpjStr] = [];
                        }
                    }

                    // Process each queue item using CRM API phones
                    // Now with phone_limit support: create N queue entries (one per phone up to limit)
                    $phoneTypeFilter = $phoneConfig['phone_type_filter'] ?? 'all';
                    $phoneLimit      = (int) ($phoneConfig['phone_limit'] ?? 0);

                    // Track new queues created for additional phones
                    $newQueuesCreated = [];

                    foreach ($batch as $queue) {
                        $leadId   = $queue->getLead()->getId();
                        $leadData = $leadsDataById[$leadId] ?? null;

                        if (null === $leadData) {
                            // Clear phone from payload for traceability (CRM API was configured but lookup failed)
                            $payload = $queue->getPayloadArray();
                            $payload['areaCode'] = '';
                            $payload['phone'] = '';
                            $payload['_phone_source'] = 'crm_api';
                            $payload['_phone_error'] = 'Contato não encontrado';
                            $updatedPayloads[$queue->getId()] = $payload;

                            $failedQueues[] = [
                                'queue' => $queue,
                                'error' => 'Contato não encontrado',
                            ];
                            continue;
                        }

                        $cpfCnpj = preg_replace('/\D/', '', $leadData['cpf_cnpj_value'] ?? '');
                        if (empty($cpfCnpj)) {
                            // Clear phone from payload for traceability
                            $payload = $queue->getPayloadArray();
                            $payload['areaCode'] = '';
                            $payload['phone'] = '';
                            $payload['_phone_source'] = 'crm_api';
                            $payload['_phone_error'] = 'CPF/CNPJ vazio';
                            $updatedPayloads[$queue->getId()] = $payload;

                            $failedQueues[] = [
                                'queue' => $queue,
                                'error' => 'Contato sem CPF/CNPJ para busca na API CRM',
                            ];
                            continue;
                        }

                        $crmPhones = $phonesByCpfCnpj[$cpfCnpj] ?? [];
                        if (empty($crmPhones)) {
                            // Clear phone from payload for traceability
                            $payload = $queue->getPayloadArray();
                            $payload['areaCode'] = '';
                            $payload['phone'] = '';
                            $payload['_phone_source'] = 'crm_api';
                            $payload['_cpf_cnpj_used'] = $cpfCnpj;
                            $payload['_phone_error'] = 'Nenhum telefone na API CRM';
                            $updatedPayloads[$queue->getId()] = $payload;

                            $failedQueues[] = [
                                'queue' => $queue,
                                'error' => 'Nenhum telefone encontrado na API CRM',
                            ];
                            continue;
                        }

                        // Filter phones by type and collect valid ones
                        $validPhones = [];
                        foreach ($crmPhones as $crmPhone) {
                            $phoneNumber = $crmPhone['numeroTelefone'] ?? '';
                            if (empty($phoneNumber)) {
                                continue;
                            }

                            // Normalize phone using MessageMapper
                            $normalized = $this->messageMapper->normalizePhone($phoneNumber);

                            // Apply phone type filter
                            if (!$this->messageMapper->matchesPhoneTypeFilter($normalized, $phoneTypeFilter)) {
                                continue;
                            }

                            // Validate phone length
                            $phoneDigits = $normalized['areaCode'].$normalized['phone'];
                            if (strlen($phoneDigits) < 8) {
                                continue;
                            }

                            $validPhones[] = [
                                'normalized' => $normalized,
                                'original' => $crmPhone,
                            ];
                        }

                        if (empty($validPhones)) {
                            // Clear phone from payload for traceability
                            $payload = $queue->getPayloadArray();
                            $payload['areaCode'] = '';
                            $payload['phone'] = '';
                            $payload['_phone_source'] = 'crm_api';
                            $payload['_cpf_cnpj_used'] = $cpfCnpj;
                            $payload['_phone_error'] = 'Nenhum telefone válido compatível com filtro';
                            $updatedPayloads[$queue->getId()] = $payload;

                            $failedQueues[] = [
                                'queue' => $queue,
                                'error' => 'Nenhum telefone válido compatível com filtro na API CRM',
                            ];
                            continue;
                        }

                        // Apply phone_limit: select only the first N phones (already sorted by pontuacao)
                        if ($phoneLimit > 0 && count($validPhones) > $phoneLimit) {
                            $phonesToUse = array_slice($validPhones, 0, $phoneLimit);
                        } else {
                            $phonesToUse = $validPhones;
                        }

                        // Build traceability data (list of all phones from API)
                        $crmPhonesList = array_map(function ($phone) {
                            return [
                                'numero' => $phone['numeroTelefone'] ?? '',
                                'pontuacao' => $phone['pontuacao'] ?? 0,
                                'crm' => $phone['crm'] ?? '',
                            ];
                        }, $crmPhones);

                        // Create one message for each phone (up to phone_limit)
                        foreach ($phonesToUse as $phoneIndex => $phoneData) {
                            $normalized = $phoneData['normalized'];
                            $originalPhone = $phoneData['original'];

                            // Build payload with phone and traceability data
                            $payload = $queue->getPayloadArray();
                            $payload['areaCode'] = $normalized['areaCode'];
                            $payload['phone'] = $normalized['phone'];
                            $payload['_phone_source'] = 'crm_api';
                            $payload['_cpf_cnpj_used'] = $cpfCnpj;

                            // Add cpfCnpjReceiver and contract to payload
                            $payload['cpfCnpjReceiver'] = $cpfCnpj;
                            $contractValue = $leadData['contract_value'] ?? '';
                            if (!empty($contractValue)) {
                                $payload['contract'] = $contractValue;
                            }

                            // Traceability: which phone was selected for THIS message
                            $payload['_selected_phone'] = [
                                'numero' => $originalPhone['numeroTelefone'] ?? '',
                                'pontuacao' => $originalPhone['pontuacao'] ?? 0,
                                'crm' => $originalPhone['crm'] ?? '',
                            ];

                            // Traceability: all phones returned by CRM API
                            $payload['_crm_phones_list'] = $crmPhonesList;

                            if (0 === $phoneIndex) {
                                // First phone: update the original placeholder queue
                                $messages[] = $payload;
                                $updatedPayloads[$queue->getId()] = $payload;
                                $validQueues[] = $queue;
                            } else {
                                // Additional phones: create new queue entries
                                $newQueue = new BpMessageQueue();
                                $newQueue->setLead($queue->getLead());
                                $newQueue->setLot($lot);
                                $newQueue->setStatus('PENDING');
                                $newQueue->setPayloadJson(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                $this->entityManager->persist($newQueue);

                                $messages[] = $payload;
                                $validQueues[] = $newQueue;
                                $newQueuesCreated[] = $newQueue;
                            }
                        }
                    }

                    // Flush new queue entries to database
                    if (!empty($newQueuesCreated)) {
                        $this->entityManager->flush();
                        $this->logger->info('BpMessage: Created additional queue entries for phone_limit', [
                            'lot_id' => $lot->getId(),
                            'new_queues_count' => count($newQueuesCreated),
                        ]);
                    }
                }
            }

            // PHONE SOURCE: LEAD (from contact field) - with phone_limit support
            if ('lead' === $phoneSource && null !== $this->messageMapper) {
                $phoneField = $phoneConfig['phone_field'] ?? 'mobile';
                $cpfCnpjField = $phoneConfig['cpf_cnpj_field'] ?? '';
                $contractField = $phoneConfig['contract_field'] ?? '';
                $newQueuesCreatedLead = [];

                // Fetch cpf_cnpj and contract values for all leads in batch (if fields configured)
                $leadIds = array_map(fn($q) => $q->getLead()->getId(), $batch);
                $leadIds = array_unique($leadIds);
                $leadsExtraData = [];

                if (!empty($cpfCnpjField) || !empty($contractField)) {
                    $selectFields = 'id';
                    if (!empty($cpfCnpjField)) {
                        $selectFields .= ", {$cpfCnpjField} as cpf_cnpj_value";
                    }
                    if (!empty($contractField)) {
                        $selectFields .= ", {$contractField} as contract_value";
                    }
                    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
                    $rows = $connection->fetchAllAssociative(
                        "SELECT {$selectFields} FROM leads WHERE id IN ({$placeholders})",
                        $leadIds
                    );
                    foreach ($rows as $row) {
                        $leadsExtraData[$row['id']] = $row;
                    }
                }

                // Process each queue item - get ALL phones and apply phone_limit
                foreach ($batch as $queue) {
                    $lead = $queue->getLead();

                    // Get ALL phones from lead field (without limit applied)
                    $allPhones = $this->messageMapper->extractAllPhonesFromField($lead, $phoneConfig);

                    if (empty($allPhones)) {
                        $failedQueues[] = [
                            'queue' => $queue,
                            'error' => 'Contato sem telefone no momento do disparo',
                        ];
                        continue;
                    }

                    // Filter out invalid phones (less than 8 digits)
                    $validPhones = [];
                    foreach ($allPhones as $phoneData) {
                        $normalized  = $phoneData['normalized'];
                        $phoneDigits = $normalized['areaCode'].$normalized['phone'];
                        if (strlen($phoneDigits) >= 8) {
                            $validPhones[] = $phoneData;
                        }
                    }

                    if (empty($validPhones)) {
                        $failedQueues[] = [
                            'queue' => $queue,
                            'error' => 'Nenhum telefone válido encontrado no campo do lead',
                        ];
                        continue;
                    }

                    // Apply phone_limit: select only the first N phones
                    if ($phoneLimit > 0 && count($validPhones) > $phoneLimit) {
                        $phonesToUse = array_slice($validPhones, 0, $phoneLimit);
                        $this->logger->debug('BpMessage: Applying phone_limit for LEAD source', [
                            'lead_id'        => $lead->getId(),
                            'total_phones'   => count($validPhones),
                            'phone_limit'    => $phoneLimit,
                            'phones_to_use'  => count($phonesToUse),
                        ]);
                    } else {
                        $phonesToUse = $validPhones;
                    }

                    // Build traceability list of all phones from lead field
                    $leadPhonesList = array_map(function ($p) {
                        return [
                            'numero' => $p['original'] ?? '',
                            'areaCode' => $p['normalized']['areaCode'] ?? '',
                            'phone' => $p['normalized']['phone'] ?? '',
                        ];
                    }, $validPhones);

                    // Create one message for each phone (up to phone_limit)
                    foreach ($phonesToUse as $phoneIndex => $phoneData) {
                        $normalized     = $phoneData['normalized'];
                        $originalPhone  = $phoneData['original'];

                        // Build payload with phone and traceability data
                        $payload             = $queue->getPayloadArray();
                        $payload['areaCode'] = $normalized['areaCode'];
                        $payload['phone']    = $normalized['phone'];
                        $payload['_phone_source'] = 'lead';
                        $payload['_phone_field']  = $phoneField;

                        // Add cpfCnpjReceiver and contract to payload (if fields configured)
                        $leadExtraData = $leadsExtraData[$lead->getId()] ?? [];
                        if (!empty($leadExtraData['cpf_cnpj_value'])) {
                            $payload['cpfCnpjReceiver'] = preg_replace('/\D/', '', $leadExtraData['cpf_cnpj_value']);
                        }
                        if (!empty($leadExtraData['contract_value'])) {
                            $payload['contract'] = $leadExtraData['contract_value'];
                        }

                        // Traceability: selected phone for THIS message
                        $payload['_selected_phone'] = [
                            'numero'   => $originalPhone,
                            'areaCode' => $normalized['areaCode'],
                            'phone'    => $normalized['phone'],
                        ];

                        // Traceability: complete list of all phones from lead field
                        $payload['_lead_phones_list'] = $leadPhonesList;

                        if (0 === $phoneIndex) {
                            // First phone: update the original placeholder queue
                            $messages[] = $payload;
                            $updatedPayloads[$queue->getId()] = $payload;
                            $validQueues[] = $queue;
                        } else {
                            // Additional phones: create new queue entries
                            $newQueue = new BpMessageQueue();
                            $newQueue->setLead($lead);
                            $newQueue->setLot($lot);
                            $newQueue->setStatus('PENDING');
                            $newQueue->setPayloadJson(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                            $this->entityManager->persist($newQueue);

                            $messages[] = $payload;
                            $validQueues[] = $newQueue;
                            $newQueuesCreatedLead[] = $newQueue;

                            $this->logger->debug('BpMessage: Created additional queue entry for phone_limit (LEAD source)', [
                                'lead_id'     => $lead->getId(),
                                'lot_id'      => $lot->getId(),
                                'phone_index' => $phoneIndex,
                                'phone'       => $normalized['areaCode'].'-'.$normalized['phone'],
                            ]);
                        }
                    }
                }

                // Flush new queue entries to database
                if (!empty($newQueuesCreatedLead)) {
                    $this->entityManager->flush();
                    $this->logger->info('BpMessage: Created additional queue entries for phone_limit (LEAD source)', [
                        'lot_id' => $lot->getId(),
                        'new_queues_count' => count($newQueuesCreatedLead),
                    ]);
                }
            } elseif ('lead' === $phoneSource && null === $this->messageMapper) {
                // Fallback: use stored payload (legacy behavior)
                foreach ($batch as $queue) {
                    $messages[]    = $queue->getPayloadArray();
                    $validQueues[] = $queue;
                }
            }

            // OPTIMIZATION: Batch update payloads using single query with CASE WHEN
            if (!empty($updatedPayloads)) {
                // Build batch update query
                $cases  = [];
                $ids    = [];
                $params = [];
                $i      = 0;
                foreach ($updatedPayloads as $queueId => $payload) {
                    $cases[] = "WHEN id = ? THEN ?";
                    $params[] = $queueId;
                    $params[] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $ids[]    = $queueId;
                    ++$i;
                }

                $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $sql            = "UPDATE bpmessage_queue SET payload_json = CASE ".implode(' ', $cases)." END WHERE id IN ({$idPlaceholders})";
                $params         = array_merge($params, $ids);

                $connection->executeStatement($sql, $params);

                $this->logger->debug('BpMessage: Updated queue payloads with refreshed phone numbers (batch)', [
                    'lot_id'        => $lot->getId(),
                    'updated_count' => count($updatedPayloads),
                ]);
            }

            // OPTIMIZATION: Batch update failed queues by error type
            if (!empty($failedQueues)) {
                // Group by error message for efficient batch update
                $failedByError = [];
                foreach ($failedQueues as $failedData) {
                    $error                     = $failedData['error'];
                    $failedByError[$error][] = $failedData['queue']->getId();
                }

                foreach ($failedByError as $error => $queueIds) {
                    $placeholders = implode(',', array_fill(0, count($queueIds), '?'));
                    $params       = array_merge(['FAILED', $error], $queueIds);
                    $connection->executeStatement(
                        "UPDATE bpmessage_queue SET status = ?, error_message = ? WHERE id IN ({$placeholders})",
                        $params
                    );
                }

                $this->logger->info('BpMessage: Marked failed messages (no valid phone at dispatch time)', [
                    'lot_id'       => $lot->getId(),
                    'failed_count' => count($failedQueues),
                ]);
            }

            // Skip API call if no valid messages
            if (empty($messages)) {
                $this->logger->warning('BpMessage: No valid messages in batch after phone refresh', [
                    'lot_id'      => $lot->getId(),
                    'batch_index' => $batchIndex,
                ]);
                continue;
            }

            $this->client->setBaseUrl($lot->getApiBaseUrl());
            $result = $this->client->addMessagesToLot((int) $lot->getExternalLotId(), $messages);

            if ($result['success']) {
                // Mark messages as sent
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
                }, $validQueues);

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

        $this->logger->info('BpMessage: Creating lot in API', [
            'lot_id'  => $lot->getId(),
            'payload' => $lotData,
        ]);

        try {
            $result = $this->client->createLot($lotData);

            if (!$result['success']) {
                // Get route name for better error message
                $routeName = $this->getRouteNameForLot($lot);

                // Translate API error to user-friendly message
                $friendlyError = $this->translateApiError($result['error'], $routeName);

                $lot->setStatus('FAILED');
                $lot->setErrorMessage($friendlyError);
                $this->entityManager->flush();

                // Force SQL update
                $connection = $this->entityManager->getConnection();
                $connection->executeStatement(
                    'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                    ['FAILED', $friendlyError, $lot->getId()]
                );

                $this->logger->error('BpMessage: API CreateLot failed', [
                    'lot_id'         => $lot->getId(),
                    'error'          => $result['error'],
                    'friendly_error' => $friendlyError,
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

    /**
     * Get route name for a lot using RoutesService.
     *
     * @param BpMessageLot $lot The lot to get route name for
     *
     * @return string|null The route name, or null if not found
     */
    private function getRouteNameForLot(BpMessageLot $lot): ?string
    {
        if (null === $this->routesService) {
            return null;
        }

        $payload = $lot->getCreateLotPayload();
        if (empty($payload)) {
            return null;
        }

        $idServiceSettings = $lot->getIdServiceSettings();
        $bookBusinessForeignId = $payload['bookBusinessForeignId'] ?? null;
        $crmId = $payload['crmId'] ?? null;
        $serviceType = $lot->getServiceType();

        if (empty($idServiceSettings) || empty($bookBusinessForeignId) || empty($crmId) || empty($serviceType)) {
            return null;
        }

        return $this->routesService->getRouteNameByIdServiceSettings(
            $idServiceSettings,
            (string) $bookBusinessForeignId,
            (string) $crmId,
            $serviceType
        );
    }

    /**
     * Translate API error messages to user-friendly messages.
     *
     * @param string      $apiError  The original API error message
     * @param string|null $routeName Optional route name for context
     */
    private function translateApiError(string $apiError, ?string $routeName = null): string
    {
        // Map of API error patterns to friendly messages
        $errorMappings = [
            "'Id Quota Settings' must not be equal to '0'" => $routeName
                ? "Não foi possível criar o lote: a rota '{$routeName}' não possui configuração de cota válida. Entre em contato com o administrador ou selecione outra rota."
                : 'A rota selecionada não possui configuração de cota válida. Selecione outra rota ou entre em contato com o administrador.',
            "'Crm Id' must not be equal to '0'" => 'O CRM ID não está configurado corretamente. Verifique a configuração da ação de campanha.',
            "'Book Business Foreign Id' must not be empty" => 'A Carteira (Book Business Foreign Id) não está configurada. Verifique a configuração da ação de campanha.',
            'Não há rota padrão configurada' => 'Não há rota padrão configurada para este tipo de envio. Configure uma rota padrão no painel BpMessage.',
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
