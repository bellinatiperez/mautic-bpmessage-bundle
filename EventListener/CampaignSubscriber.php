<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use MauticPlugin\MauticBpMessageBundle\Form\Type\BpMessageActionType;
use MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Campaign event subscriber for BpMessage integration
 */
class CampaignSubscriber implements EventSubscriberInterface
{
    private BpMessageModel $bpMessageModel;
    private LoggerInterface $logger;

    public function __construct(BpMessageModel $bpMessageModel, LoggerInterface $logger)
    {
        $this->bpMessageModel = $bpMessageModel;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            'mautic.bpmessage.on_campaign_trigger_action' => ['onCampaignTriggerAction', 0],
        ];
    }

    /**
     * Register BpMessage action in campaign builder
     */
    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(
            'bpmessage.send',
            [
                'label' => 'mautic.bpmessage.campaign.action.send',
                'description' => 'mautic.bpmessage.campaign.action.send.descr',
                'batchEventName' => 'mautic.bpmessage.on_campaign_trigger_action',
                'formType' => BpMessageActionType::class,
                'formTypeCleanMasks' => [
                    'lot_data' => 'raw',
                    'additional_data' => 'raw',
                    'message_variables' => 'raw',
                ],
                'channel' => 'bpmessage',
                'channelIdField' => 'bpmessage_id',
            ]
        );
    }

    /**
     * Execute BpMessage action when triggered in campaign (batch processing)
     */
    public function onCampaignTriggerAction(PendingEvent $event): void
    {
        $this->logger->info('BpMessage: onCampaignTriggerAction CALLED');

        $config = $event->getEvent()->getProperties();
        $campaign = $event->getEvent()->getCampaign();

        $this->logger->info('BpMessage: Campaign', [
            'campaign_id' => $campaign ? $campaign->getId() : 'NULL',
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
                'log_id' => $log->getId(),
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
            } catch (\Exception $e) {
                $event->fail($log, $e->getMessage());
                $this->logger->error('BpMessage: Exception occurred', [
                    'lead_id' => $lead->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
