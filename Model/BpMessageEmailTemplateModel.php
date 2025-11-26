<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Model;

use Doctrine\ORM\EntityManager;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticBpMessageBundle\Exception\LotCreationException;
use MauticPlugin\MauticBpMessageBundle\Integration\BpMessageIntegration;
use MauticPlugin\MauticBpMessageBundle\Service\EmailLotManager;
use MauticPlugin\MauticBpMessageBundle\Service\EmailTemplateMessageMapper;
use Psr\Log\LoggerInterface;

/**
 * Model for BpMessage email template operations.
 */
class BpMessageEmailTemplateModel
{
    private EmailLotManager $lotManager;
    private EmailTemplateMessageMapper $messageMapper;
    private EntityManager $em;
    private LoggerInterface $logger;
    private IntegrationHelper $integrationHelper;

    public function __construct(
        EmailLotManager $lotManager,
        EmailTemplateMessageMapper $messageMapper,
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
     * Send an email using a template for a lead (called from campaign action).
     *
     * Always registers the contact to the lot queue.
     * If the contact has no email address, marks as FAILED in the queue only (not failing the campaign event).
     *
     * @param array $config Campaign action configuration
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function sendEmailFromTemplate(Lead $lead, array $config, Campaign $campaign): array
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

            // Validate lead (excluding email validation - handled separately below)
            $validation = $this->messageMapper->validateLead($lead, $config);
            if (!$validation['valid']) {
                $errorMsg = implode('; ', $validation['errors']);
                $this->logger->warning('BpMessage Email Template: Lead validation failed', [
                    'lead_id' => $lead->getId(),
                    'errors'  => $errorMsg,
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

            // Get or create active email lot
            $lot = $this->lotManager->getOrCreateActiveLot($campaign, $config);

            // Map lead to email format (with template rendering, passing lot for book_business_foreign_id)
            $emailData = $this->messageMapper->mapLeadToEmail($lead, $config, $campaign, $lot);

            // Check if lead has email address (from the mapped data)
            $hasEmail = !empty($emailData['to']);

            if ($hasEmail) {
                // Queue email normally (PENDING status)
                $this->lotManager->queueEmail($lot, $lead, $emailData);

                $this->logger->info('BpMessage Email Template: Email queued successfully', [
                    'lead_id'     => $lead->getId(),
                    'lot_id'      => $lot->getId(),
                    'campaign_id' => $campaign->getId(),
                    'template_id' => is_array($config['email_template']) ? reset($config['email_template']) : $config['email_template'],
                ]);

                return [
                    'success' => true,
                    'message' => 'Email from template queued successfully',
                ];
            }

            // No email - queue as FAILED but still return success to campaign
            // This ensures the contact is registered in the lot but won't be sent to BpMessage
            $errorMessage = 'Contato sem email';

            $this->lotManager->queueEmailWithStatus(
                $lot,
                $lead,
                $emailData,
                'FAILED',
                $errorMessage
            );

            $this->logger->warning('BpMessage Email Template: Contact queued as FAILED - no email address', [
                'lead_id'     => $lead->getId(),
                'lot_id'      => $lot->getId(),
                'campaign_id' => $campaign->getId(),
            ]);

            // Return success to campaign - contact is registered but marked as failed in queue
            return [
                'success' => true,
                'message' => 'Contact registered (no email - marked as failed)',
            ];
        } catch (LotCreationException $e) {
            // Re-throw LotCreationException to allow CampaignSubscriber to handle it properly
            // This exception indicates a lot-level error, not a lead-specific error
            throw $e;
        } catch (\Exception $e) {
            // Lead-specific errors are caught and returned as failure
            $this->logger->error('BpMessage Email Template: Failed to send email', [
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
     * Get API Base URL from integration.
     */
    private function getApiBaseUrl(): ?string
    {
        /** @var BpMessageIntegration|null $integration */
        $integration = $this->integrationHelper->getIntegrationObject('BpMessage');

        if (!$integration) {
            $this->logger->warning('BpMessage Email Template: Integration not found');

            return 'https://api.bpmessage.com.br'; // Fallback
        }

        $settings = $integration->getIntegrationSettings();

        if (!$settings || !$settings->getIsPublished()) {
            $this->logger->warning('BpMessage Email Template: Integration not published');

            return 'https://api.bpmessage.com.br'; // Fallback
        }

        $apiUrl = $integration->getApiBaseUrl();

        if (!$apiUrl) {
            $this->logger->warning('BpMessage Email Template: API URL not configured, using default');

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
