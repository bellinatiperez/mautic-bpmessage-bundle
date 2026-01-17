<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Model;

use Doctrine\ORM\EntityManager;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot;
use MauticPlugin\MauticBpMessageBundle\Integration\BpMessageIntegration;
use MauticPlugin\MauticBpMessageBundle\Service\EmailLotManager;
use MauticPlugin\MauticBpMessageBundle\Service\EmailMessageMapper;
use Psr\Log\LoggerInterface;

/**
 * Model for BpMessage email operations.
 */
class BpMessageEmailModel
{
    private EmailLotManager $lotManager;
    private EmailMessageMapper $messageMapper;
    private EntityManager $em;
    private LoggerInterface $logger;
    private IntegrationHelper $integrationHelper;

    public function __construct(
        EmailLotManager $lotManager,
        EmailMessageMapper $messageMapper,
        EntityManager $entityManager,
        LoggerInterface $logger,
        IntegrationHelper $integrationHelper,
    ) {
        $this->lotManager        = $lotManager;
        $this->messageMapper     = $messageMapper;
        $this->em                = $entityManager;
        $this->logger            = $logger;
        $this->integrationHelper = $integrationHelper;
    }

    /**
     * Send an email for a lead (called from campaign action).
     *
     * Supports Collection fields - if email_field is a Collection, one email is queued for each address.
     * If the contact has no email address, marks as FAILED in the queue only (not failing the campaign event).
     *
     * @param array $config Campaign action configuration
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function sendEmail(Lead $lead, array $config, Campaign $campaign): array
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
            $config['api_base_url']        = $apiBaseUrl;
            $config['default_batch_size']  = $this->getDefaultBatchSize();
            $config['default_time_window'] = $this->getDefaultTimeWindow();

            // Process lot_data with token replacement (using first lead as reference)
            if (!empty($config['lot_data'])) {
                $config['lot_data'] = $this->messageMapper->processLotData($lead, $config);
            }

            // Get or create active email lot
            $lot = $this->lotManager->getOrCreateActiveLot($campaign, $config);

            // Create ONE placeholder per lead (email will be resolved at dispatch time)
            // This follows the same pattern as LotManager for SMS/WhatsApp
            // The actual emails are fetched and expanded in sendLotEmails()
            $emailData = $this->messageMapper->mapLeadToEmailWithAddress($lead, '', $config, $campaign, $lot);

            // Queue placeholder (PENDING status)
            $this->lotManager->queueEmail($lot, $lead, $emailData);

            $this->logger->info('BpMessage Email: Placeholder queued for lead', [
                'lead_id'     => $lead->getId(),
                'lot_id'      => $lot->getId(),
                'campaign_id' => $campaign->getId(),
                'email_field' => $config['email_field'] ?? 'email',
                'email_limit' => $config['email_limit'] ?? 0,
            ]);

            return [
                'success' => true,
                'message' => 'Email placeholder queued successfully',
            ];
        } catch (\Exception $e) {
            $this->logger->error('BpMessage Email: Failed to send email', [
                'lead_id'     => $lead->getId(),
                'campaign_id' => $campaign->getId(),
                'error'       => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process open email lots that should be closed.
     *
     * @param bool $forceClose Force close all open lots regardless of time/count criteria
     *
     * @return array ['processed' => int, 'succeeded' => int, 'failed' => int]
     */
    public function processOpenLots(bool $forceClose = false): array
    {
        // Find all open email lots - those with lot_type = 'email'
        $qb = $this->em->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.status = :status')
            ->andWhere('l.lotType = :lotType')
            ->setParameter('status', 'OPEN')
            ->setParameter('lotType', 'email')
            ->orderBy('l.createdAt', 'ASC');

        $lots = $qb->getQuery()->getResult();

        // Filter lots that should be closed
        if ($forceClose) {
            // Force close ALL open lots
            $lotsToClose = $lots;
        } else {
            // Only close lots that meet criteria: time window expired OR batch size reached
            $lotsToClose = array_filter($lots, function (BpMessageLot $lot) {
                return $lot->shouldCloseByTime() || $lot->shouldCloseByCount();
            });
        }

        $stats = [
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
        ];

        foreach ($lotsToClose as $lot) {
            ++$stats['processed'];

            $this->logger->info('BpMessage Email: Processing lot', [
                'lot_id'          => $lot->getId(),
                'external_lot_id' => $lot->getExternalLotId(),
                'messages_count'  => $lot->getMessagesCount(),
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

                // Save error message to lot for user visibility
                $errorMessage = 'Exception: '.$e->getMessage();
                $lot->setErrorMessage($errorMessage);
                $lot->setStatus('FAILED');
                $this->em->flush();

                // Force update with SQL to ensure persistence (EntityManager flush may not work in all scenarios)
                $connection = $this->em->getConnection();
                $connection->executeStatement(
                    'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                    ['FAILED', $errorMessage, $lot->getId()]
                );

                $this->logger->error('BpMessage Email: Failed to process lot', [
                    'lot_id' => $lot->getId(),
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Process orphaned CREATING lots (lots stuck in CREATING status)
     * Marks them as FAILED if they've been in CREATING for too long.
     *
     * @param int $ageMinutes Minimum age in minutes to consider a lot as orphaned (default: 5)
     *
     * @return array Statistics about processed orphaned lots
     */
    public function processOrphanedCreatingLots(int $ageMinutes = 5): array
    {
        // Find all CREATING email lots that are older than $ageMinutes
        $qb = $this->em->createQueryBuilder();
        $qb->select('l')
            ->from(BpMessageLot::class, 'l')
            ->where('l.status = :status')
            ->andWhere('l.lotType = :lotType') // Email lots
            ->andWhere('l.externalLotId IS NULL') // No external lot ID means creation failed
            ->andWhere('l.createdAt < :threshold')
            ->setParameter('status', 'CREATING')
            ->setParameter('lotType', 'email')
            ->setParameter('threshold', new \DateTime("-{$ageMinutes} minutes"))
            ->orderBy('l.createdAt', 'ASC');

        $orphanedLots = $qb->getQuery()->getResult();

        $stats = [
            'processed'     => 0,
            'marked_failed' => 0,
        ];

        foreach ($orphanedLots as $lot) {
            ++$stats['processed'];

            $this->logger->warning('BpMessage Email: Found orphaned CREATING lot', [
                'lot_id'      => $lot->getId(),
                'created_at'  => $lot->getCreatedAt()->format('Y-m-d H:i:s'),
                'minutes_old' => (new \DateTime())->diff($lot->getCreatedAt())->i,
            ]);

            // Mark as FAILED
            $lot->setStatus('FAILED');
            $lot->setErrorMessage('Lot creation timed out - stuck in CREATING status');
            $this->em->persist($lot);
            $this->em->flush();

            // Force update with SQL to ensure persistence
            $connection = $this->em->getConnection();
            $connection->executeStatement(
                'UPDATE bpmessage_lot SET status = ?, error_message = ? WHERE id = ?',
                ['FAILED', 'Lot creation timed out - stuck in CREATING status', $lot->getId()]
            );

            ++$stats['marked_failed'];

            $this->logger->info('BpMessage Email: Marked orphaned lot as FAILED', [
                'lot_id' => $lot->getId(),
            ]);
        }

        return $stats;
    }

    /**
     * Force close a specific email lot by ID.
     *
     * @param int $lotId The lot ID to process
     *
     * @return bool True if lot was processed successfully, false otherwise
     */
    public function forceCloseLot(int $lotId): bool
    {
        $lot = $this->em->getRepository(BpMessageLot::class)->find($lotId);

        if (null === $lot) {
            $this->logger->error('BpMessage Email: Lot not found', ['lot_id' => $lotId]);

            return false;
        }

        // Verify this is an email lot
        if (!$lot->isEmailLot()) {
            $this->logger->error('BpMessage Email: Lot is not an email lot', [
                'lot_id'           => $lotId,
                'idQuotaSettings'  => $lot->getIdQuotaSettings(),
            ]);

            return false;
        }

        if (!$lot->isOpen()) {
            $this->logger->warning('BpMessage Email: Lot is not open', [
                'lot_id' => $lotId,
                'status' => $lot->getStatus(),
            ]);

            return false;
        }

        try {
            return $this->lotManager->processLot($lot);
        } catch (\Exception $e) {
            $this->logger->error('BpMessage Email: Failed to force close lot', [
                'lot_id' => $lotId,
                'error'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get API Base URL from integration.
     */
    private function getApiBaseUrl(): ?string
    {
        /** @var BpMessageIntegration|null $integration */
        $integration = $this->integrationHelper->getIntegrationObject('BpMessage');

        if (!$integration) {
            $this->logger->warning('BpMessage Email: Integration not found');

            return 'https://api.bpmessage.com.br'; // Fallback
        }

        $settings = $integration->getIntegrationSettings();

        if (!$settings || !$settings->getIsPublished()) {
            $this->logger->warning('BpMessage Email: Integration not published');

            return 'https://api.bpmessage.com.br'; // Fallback
        }

        $apiUrl = $integration->getApiBaseUrl();

        if (!$apiUrl) {
            $this->logger->warning('BpMessage Email: API URL not configured, using default');

            return 'https://api.bpmessage.com.br'; // Fallback
        }

        return $apiUrl;
    }

    /**
     * Get default batch size from integration.
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
     * Get default time window from integration.
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
}
