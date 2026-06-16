<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManager;
use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\MauticBpMessageBundle\Http\BpMessageClient;
use MauticPlugin\MauticBpMessageBundle\Http\CRMClient;
use MauticPlugin\MauticBpMessageBundle\Service\EmailLotManager;
use MauticPlugin\MauticBpMessageBundle\Service\EmailMessageMapper;
use MauticPlugin\MauticBpMessageBundle\Service\LotManager;
use MauticPlugin\MauticBpMessageBundle\Service\MessageMapper;
use MauticPlugin\MauticBpMessageBundle\Service\RoutesService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
    ];

    // Auto-load all classes in the bundle with autowiring (like MauticSocialBundle)
    // Note: Entity is already excluded via MauticCoreExtension::DEFAULT_EXCLUDES
    $services->load('MauticPlugin\\MauticBpMessageBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    // Register CRMClient explicitly with empty defaults (configured at runtime via Integration settings)
    $services->set(CRMClient::class)
        ->args([
            service(LoggerInterface::class),
            '', // baseUrl - configured at runtime
            '', // apiKey - configured at runtime
        ]);

    // Register LotManager explicitly to ensure all nullable dependencies are injected
    $services->set(LotManager::class)
        ->args([
            service(EntityManager::class),
            service(BpMessageClient::class),
            service(LoggerInterface::class),
            service(RoutesService::class),
            service(MessageMapper::class),
            service(CRMClient::class),
            service(IntegrationHelper::class),
        ]);

    // Register EmailLotManager explicitly to ensure the nullable EmailMessageMapper
    // is injected. Without it, autowiring leaves the optional argument null and email
    // dispatch falls back to the stored payload, ignoring the configured Email Field
    // (collection) and sending an empty "to" (BpMessage: "'To' must not be empty.").
    $services->set(EmailLotManager::class)
        ->args([
            service(EntityManager::class),
            service(BpMessageClient::class),
            service(LoggerInterface::class),
            service(EmailMessageMapper::class),
        ]);

    // Observação: a substituição do scheduler de intervalo do core
    // (FixedHourIntervalScheduler) é feita via CompilerPass
    // (OverrideIntervalSchedulerPass), pois o serviço primário é
    // 'mautic.campaign.scheduler.interval' e não a FQCN. A subclasse já é
    // auto-carregada pelo ->load() acima.
};
