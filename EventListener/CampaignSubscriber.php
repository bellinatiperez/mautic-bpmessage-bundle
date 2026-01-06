<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use MauticPlugin\MauticBpMessageBundle\Exception\LotCreationException;
use MauticPlugin\MauticBpMessageBundle\Form\Type\BpMessageActionType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\BpMessageEmailActionType;
use MauticPlugin\MauticBpMessageBundle\Form\Type\BpMessageEmailTemplateActionType;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageEmailModel;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageEmailTemplateModel;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Campaign event subscriber for BpMessage integration.
 */
class CampaignSubscriber implements EventSubscriberInterface
{
    private BpMessageModel $bpMessageModel;
    private BpMessageEmailModel $bpMessageEmailModel;
    private BpMessageEmailTemplateModel $bpMessageEmailTemplateModel;
    private LoggerInterface $logger;
    private Connection $connection;

    public function __construct(
        BpMessageModel $bpMessageModel,
        BpMessageEmailModel $bpMessageEmailModel,
        BpMessageEmailTemplateModel $bpMessageEmailTemplateModel,
        LoggerInterface $logger,
        Connection $connection,
    ) {
        $this->bpMessageModel              = $bpMessageModel;
        $this->bpMessageEmailModel         = $bpMessageEmailModel;
        $this->bpMessageEmailTemplateModel = $bpMessageEmailTemplateModel;
        $this->logger                      = $logger;
        $this->connection                  = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD                            => ['onCampaignBuild', 0],
            'mautic.bpmessage.on_campaign_trigger_action'                => ['onCampaignTriggerAction', 0],
            'mautic.bpmessage.email.on_campaign_trigger_action'          => ['onCampaignTriggerEmailAction', 0],
            'mautic.bpmessage.email_template.on_campaign_trigger_action' => ['onCampaignTriggerEmailTemplateAction', 0],
        ];
    }

    /**
     * Register BpMessage actions in campaign builder.
     */
    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        // Register Send BpMessage action (SMS/WhatsApp/RCS)
        $event->addAction(
            'bpmessage.send',
            [
                'label'              => 'mautic.bpmessage.campaign.action.send',
                'description'        => 'mautic.bpmessage.campaign.action.send.descr',
                'batchEventName'     => 'mautic.bpmessage.on_campaign_trigger_action',
                'formType'           => BpMessageActionType::class,
                'formTypeCleanMasks' => [
                    'lot_data'          => 'raw',
                    'additional_data'   => 'raw',
                    'message_variables' => 'raw',
                    'route_data'        => 'raw',
                ],
                'channel'        => 'bpmessage',
                'channelIdField' => 'bpmessage_id',
            ]
        );

        // Register Send BpMessage Email action
        $event->addAction(
            'bpmessage.send_email',
            [
                'label'              => 'mautic.bpmessage.campaign.action.send_email',
                'description'        => 'mautic.bpmessage.campaign.action.send_email.descr',
                'batchEventName'     => 'mautic.bpmessage.email.on_campaign_trigger_action',
                'formType'           => BpMessageEmailActionType::class,
                'formTypeCleanMasks' => [
                    'lot_data'        => 'raw',
                    'additional_data' => 'raw',
                    'email_variables' => 'raw',
                    'email_body'      => 'raw',  // Preserve HTML in email body
                ],
                'channel'        => 'bpmessage_email',
                'channelIdField' => 'bpmessage_email_id',
            ]
        );

        // Register Send BpMessage Email Template action
        $event->addAction(
            'bpmessage.send_email_template',
            [
                'label'              => 'mautic.bpmessage.campaign.action.send_email_template',
                'description'        => 'mautic.bpmessage.campaign.action.send_email_template.descr',
                'batchEventName'     => 'mautic.bpmessage.email_template.on_campaign_trigger_action',
                'formType'           => BpMessageEmailTemplateActionType::class,
                'formTypeCleanMasks' => [
                    'lot_data'        => 'raw',
                    'additional_data' => 'raw',
                    'email_variables' => 'raw',
                    'email_body'      => 'raw',  // Preserve HTML in email body (if template has custom body)
                ],
                'channel'        => 'bpmessage_email_template',
                'channelIdField' => 'bpmessage_email_template_id',
            ]
        );
    }

    /**
     * Execute BpMessage action when triggered in campaign (batch processing).
     */
    public function onCampaignTriggerAction(PendingEvent $event): void
    {
        $this->logger->info('BpMessage: onCampaignTriggerAction CALLED');

        $config   = $event->getEvent()->getProperties();
        $campaign = $event->getEvent()->getCampaign();

        // Add event name to config for lot naming
        $config['event_name'] = $event->getEvent()->getName();

        $this->logger->info('BpMessage: Campaign', [
            'campaign_id'   => $campaign ? $campaign->getId() : 'NULL',
            'campaign_name' => $campaign ? $campaign->getName() : 'NULL',
        ]);

        if (!$campaign) {
            $this->logger->error('BpMessage: Campaign not found in event');
            $event->failAll('Campaign not found');

            return;
        }

        // Process each log (which contains the contact)
        $logs = $event->getPending();

        $this->logger->info('BpMessage: Processing logs', [
            'count' => is_countable($logs) ? count($logs) : 'unknown',
        ]);

        foreach ($logs as $log) {
            $lead = $log->getLead();

            $this->logger->info('BpMessage: Processing lead', [
                'lead_id' => $lead->getId(),
                'log_id'  => $log->getId(),
            ]);

            try {
                $result = $this->bpMessageModel->sendMessage($lead, $config, $campaign);

                if ($result['success']) {
                    $event->pass($log);
                    $this->logger->info('BpMessage: Lead passed', ['lead_id' => $lead->getId()]);
                } else {
                    $event->fail($log, $result['message']);
                    $this->logger->warning('BpMessage: Lead failed', [
                        'lead_id' => $lead->getId(),
                        'message' => $result['message'],
                    ]);
                }
            } catch (LotCreationException $e) {
                // Lot creation failed - this is a configuration/API error, NOT a lead error
                // Do NOT mark leads as failed - leave them pending for retry
                $this->logger->error('BpMessage: LOT CREATION FAILED - Leads remain pending for retry', [
                    'campaign_id'     => $campaign->getId(),
                    'lot_id'          => $e->getLotId(),
                    'error'           => $e->getMessage(),
                    'is_config_error' => $e->isConfigurationError(),
                    'affected_leads'  => is_countable($logs) ? count($logs) : 'unknown',
                ]);

                // Stop processing remaining leads - they will all fail with the same lot error
                $this->logger->warning('BpMessage: Stopping batch processing due to lot creation failure');
                break;
            } catch (\Exception $e) {
                // Lead-specific error - mark this lead as failed
                $event->fail($log, $e->getMessage());
                $this->logger->error('BpMessage: Lead-specific exception occurred', [
                    'lead_id' => $lead->getId(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Execute BpMessage Email action when triggered in campaign (batch processing).
     */
    public function onCampaignTriggerEmailAction(PendingEvent $event): void
    {
        $this->logger->info('BpMessage Email: onCampaignTriggerEmailAction CALLED');

        $config   = $event->getEvent()->getProperties();
        $campaign = $event->getEvent()->getCampaign();

        // Add event name to config for lot naming
        $config['event_name'] = $event->getEvent()->getName();

        $this->logger->info('BpMessage Email: Campaign', [
            'campaign_id'   => $campaign ? $campaign->getId() : 'NULL',
            'campaign_name' => $campaign ? $campaign->getName() : 'NULL',
        ]);

        if (!$campaign) {
            $this->logger->error('BpMessage Email: Campaign not found in event');
            $event->failAll('Campaign not found');

            return;
        }

        // Process each log (which contains the contact)
        $logs = $event->getPending();

        $this->logger->info('BpMessage Email: Processing logs', [
            'count' => is_countable($logs) ? count($logs) : 'unknown',
        ]);

        foreach ($logs as $log) {
            $lead = $log->getLead();

            $this->logger->info('BpMessage Email: Processing lead', [
                'lead_id' => $lead->getId(),
                'log_id'  => $log->getId(),
            ]);

            try {
                $result = $this->bpMessageEmailModel->sendEmail($lead, $config, $campaign);

                if ($result['success']) {
                    $event->pass($log);
                    $this->logger->info('BpMessage Email: Lead passed', ['lead_id' => $lead->getId()]);
                } else {
                    $event->fail($log, $result['message']);
                    $this->logger->warning('BpMessage Email: Lead failed', [
                        'lead_id' => $lead->getId(),
                        'message' => $result['message'],
                    ]);
                }
            } catch (LotCreationException $e) {
                // Lot creation failed - this is a configuration/API error, NOT a lead error
                // Do NOT mark leads as failed - leave them pending for retry
                $this->logger->error('BpMessage Email: LOT CREATION FAILED - Leads remain pending for retry', [
                    'campaign_id'     => $campaign->getId(),
                    'lot_id'          => $e->getLotId(),
                    'error'           => $e->getMessage(),
                    'is_config_error' => $e->isConfigurationError(),
                    'affected_leads'  => is_countable($logs) ? count($logs) : 'unknown',
                ]);

                // Stop processing remaining leads - they will all fail with the same lot error
                $this->logger->warning('BpMessage Email: Stopping batch processing due to lot creation failure');
                break;
            } catch (\Exception $e) {
                // Lead-specific error - mark this lead as failed
                $event->fail($log, $e->getMessage());
                $this->logger->error('BpMessage Email: Lead-specific exception occurred', [
                    'lead_id' => $lead->getId(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Execute BpMessage Email Template action when triggered in campaign (batch processing).
     */
    public function onCampaignTriggerEmailTemplateAction(PendingEvent $event): void
    {
        $this->logger->info('BpMessage Email Template: onCampaignTriggerEmailTemplateAction CALLED');

        $config   = $event->getEvent()->getProperties();
        $campaign = $event->getEvent()->getCampaign();

        // Add event name to config for lot naming
        $config['event_name'] = $event->getEvent()->getName();

        $this->logger->info('BpMessage Email Template: Campaign', [
            'campaign_id'   => $campaign ? $campaign->getId() : 'NULL',
            'campaign_name' => $campaign ? $campaign->getName() : 'NULL',
        ]);

        if (!$campaign) {
            $this->logger->error('BpMessage Email Template: Campaign not found in event');
            $event->failAll('Campaign not found');

            return;
        }

        // Process each log (which contains the contact)
        $logs = $event->getPending();

        $this->logger->info('BpMessage Email Template: Processing logs', [
            'count' => is_countable($logs) ? count($logs) : 'unknown',
        ]);

        $lotFailed    = false;
        $lotError     = null;
        $failedLogIds = [];

        foreach ($logs as $log) {
            // If lot creation failed previously, skip remaining leads but mark as failed (temporarily)
            // These will be removed from failed_log table to allow retry
            if ($lotFailed) {
                $event->fail($log, $lotError->getMessage());
                $failedLogIds[] = $log->getId();
                continue;
            }

            $lead = $log->getLead();

            $this->logger->info('BpMessage Email Template: Processing lead', [
                'lead_id' => $lead->getId(),
                'log_id'  => $log->getId(),
            ]);

            try {
                $result = $this->bpMessageEmailTemplateModel->sendEmailFromTemplate($lead, $config, $campaign);

                if ($result['success']) {
                    $event->pass($log);
                    $this->logger->info('BpMessage Email Template: Lead passed', ['lead_id' => $lead->getId()]);
                } else {
                    $event->fail($log, $result['message']);
                    $this->logger->warning('BpMessage Email Template: Lead failed', [
                        'lead_id' => $lead->getId(),
                        'message' => $result['message'],
                    ]);
                }
            } catch (LotCreationException $e) {
                // Lot creation failed - this is a configuration/API error, NOT a lead error
                // Mark this log as failed temporarily (will be removed from failed_log for retry)
                $event->fail($log, $e->getMessage());
                $failedLogIds[] = $log->getId();

                // Set flag to skip remaining leads
                $lotFailed = true;
                $lotError  = $e;

                $this->logger->error('BpMessage Email Template: LOT CREATION FAILED - Leads will be available for retry', [
                    'campaign_id'     => $campaign->getId(),
                    'lot_id'          => $e->getLotId(),
                    'error'           => $e->getMessage(),
                    'is_config_error' => $e->isConfigurationError(),
                    'affected_leads'  => is_countable($logs) ? count($logs) : 'unknown',
                ]);

                // Continue to mark remaining logs as failed (temporarily)
                $this->logger->warning('BpMessage Email Template: Marking remaining leads for retry');
            } catch (\Exception $e) {
                // Lead-specific error - mark this lead as failed permanently
                $event->fail($log, $e->getMessage());
                $this->logger->error('BpMessage Email Template: Lead-specific exception occurred', [
                    'lead_id' => $lead->getId(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Reset date_triggered for lot creation failures to allow retry
        // This is done BEFORE Mautic writes to failed_log table
        if (!empty($failedLogIds)) {
            $this->connection->executeStatement(
                'UPDATE campaign_lead_event_log SET date_triggered = NULL WHERE id IN (?)',
                [$failedLogIds],
                [Connection::PARAM_INT_ARRAY]
            );

            $this->logger->info('BpMessage Email Template: Reset logs for retry', [
                'count'   => count($failedLogIds),
                'log_ids' => $failedLogIds,
            ]);
        }
    }
}
