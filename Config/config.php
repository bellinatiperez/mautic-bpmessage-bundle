<?php

declare(strict_types=1);

return [
    'name' => 'BpMessage',
    'description' => 'Integrates Mautic with BpMessage API for sending SMS, WhatsApp and RCS messages in batch mode',
    'version' => '1.0.0',
    'author' => 'Bellinati',

    'routes' => [],

    'menu' => [],

    'services' => [
        'events' => [
            'mautic.bpmessage.campaign.subscriber' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\EventListener\CampaignSubscriber::class,
                'arguments' => [
                    'mautic.bpmessage.model.bpmessage',
                    'mautic.bpmessage.model.email',
                    'mautic.bpmessage.model.email_template',
                    'monolog.logger.mautic',
                    'database_connection',
                ],
            ],
        ],
        'forms' => [
            'mautic.bpmessage.form.type.action' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Form\Type\BpMessageActionType::class,
                'alias' => 'bpmessage_action',
            ],
            'mautic.bpmessage.form.type.email_action' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Form\Type\BpMessageEmailActionType::class,
                'alias' => 'bpmessage_email_action',
            ],
            'mautic.bpmessage.form.type.email_template_action' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Form\Type\BpMessageEmailTemplateActionType::class,
                'alias' => 'bpmessage_email_template_action',
            ],
        ],
        'models' => [
            'mautic.bpmessage.model.bpmessage' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Model\BpMessageModel::class,
                'arguments' => [
                    'mautic.bpmessage.service.lot_manager',
                    'mautic.bpmessage.service.message_mapper',
                    'doctrine.orm.entity_manager',
                    'monolog.logger.mautic',
                    'mautic.helper.integration',
                ],
            ],
            'mautic.bpmessage.model.email' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Model\BpMessageEmailModel::class,
                'arguments' => [
                    'mautic.bpmessage.service.email_lot_manager',
                    'mautic.bpmessage.service.email_message_mapper',
                    'doctrine.orm.entity_manager',
                    'monolog.logger.mautic',
                    'mautic.helper.integration',
                ],
            ],
            'mautic.bpmessage.model.email_template' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Model\BpMessageEmailTemplateModel::class,
                'arguments' => [
                    'mautic.bpmessage.service.email_lot_manager',
                    'mautic.bpmessage.service.email_template_message_mapper',
                    'doctrine.orm.entity_manager',
                    'monolog.logger.mautic',
                    'mautic.helper.integration',
                ],
            ],
        ],
        'other' => [
            'mautic.bpmessage.http.client' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Http\BpMessageClient::class,
                'arguments' => [
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.bpmessage.repository.lot' => [
                'class' => Doctrine\ORM\EntityRepository::class,
                'factory' => ['@doctrine.orm.entity_manager', 'getRepository'],
                'arguments' => [
                    \MauticPlugin\MauticBpMessageBundle\Entity\BpMessageLot::class,
                ],
            ],
            'mautic.bpmessage.repository.queue' => [
                'class' => Doctrine\ORM\EntityRepository::class,
                'factory' => ['@doctrine.orm.entity_manager', 'getRepository'],
                'arguments' => [
                    \MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue::class,
                ],
            ],
            'mautic.bpmessage.service.lot_manager' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Service\LotManager::class,
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'mautic.bpmessage.http.client',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.bpmessage.service.message_mapper' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Service\MessageMapper::class,
                'arguments' => [
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.bpmessage.service.email_lot_manager' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Service\EmailLotManager::class,
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'mautic.bpmessage.http.client',
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.bpmessage.service.email_message_mapper' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Service\EmailMessageMapper::class,
                'arguments' => [
                    'monolog.logger.mautic',
                ],
            ],
            'mautic.bpmessage.service.email_template_message_mapper' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Service\EmailTemplateMessageMapper::class,
                'arguments' => [
                    'monolog.logger.mautic',
                    'doctrine.orm.entity_manager',
                    'mautic.helper.mailer',
                ],
            ],
        ],
        'commands' => [
            'mautic.bpmessage.command.process' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Command\ProcessBpMessageQueuesCommand::class,
                'arguments' => [
                    'mautic.bpmessage.model.bpmessage',
                    'mautic.bpmessage.model.email',
                ],
                'tag' => 'console.command',
            ],
            'mautic.bpmessage.command.cleanup' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Command\CleanupBpMessageCommand::class,
                'arguments' => [
                    'mautic.bpmessage.model.bpmessage',
                ],
                'tag' => 'console.command',
            ],
            'mautic.bpmessage.command.test_actions' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Command\TestBpMessageActionsCommand::class,
                'arguments' => [
                    'doctrine.orm.entity_manager',
                    'mautic.bpmessage.service.message_mapper',
                    'mautic.bpmessage.service.email_message_mapper',
                    'mautic.bpmessage.service.email_template_message_mapper',
                ],
                'tag' => 'console.command',
            ],
            'mautic.bpmessage.command.create_test_template' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Command\CreateTestTemplateCommand::class,
                'arguments' => [
                    'doctrine.orm.entity_manager',
                ],
                'tag' => 'console.command',
            ],
            'mautic.bpmessage.command.test_api' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Command\TestApiConnectionCommand::class,
                'arguments' => [
                    'mautic.bpmessage.http.client',
                    'monolog.logger.mautic',
                    'mautic.helper.integration',
                ],
                'tag' => 'console.command',
            ],
        ],
        'integrations' => [
            'mautic.integration.bpmessage' => [
                'class' => \MauticPlugin\MauticBpMessageBundle\Integration\BpMessageIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],
    ],

    'parameters' => [
        'api_base_url' => 'https://api.bpmessage.com.br',
    ],
];
