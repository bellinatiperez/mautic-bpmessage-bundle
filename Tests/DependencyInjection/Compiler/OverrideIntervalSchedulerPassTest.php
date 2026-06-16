<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Tests\DependencyInjection\Compiler;

use Mautic\CampaignBundle\Executioner\Scheduler\Mode\Interval;
use MauticPlugin\MauticBpMessageBundle\DependencyInjection\Compiler\OverrideIntervalSchedulerPass;
use MauticPlugin\MauticBpMessageBundle\Scheduler\FixedHourIntervalScheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class OverrideIntervalSchedulerPassTest extends TestCase
{
    private const SERVICE_ID = 'mautic.campaign.scheduler.interval';

    public function testOverridesIntervalSchedulerClassPreservingArguments(): void
    {
        $container = new ContainerBuilder();
        $container->register(self::SERVICE_ID, Interval::class)
            ->setArguments(['@logger', '@core_parameters']);

        (new OverrideIntervalSchedulerPass())->process($container);

        $definition = $container->getDefinition(self::SERVICE_ID);
        self::assertSame(FixedHourIntervalScheduler::class, $definition->getClass(), 'A classe do serviço deve passar a ser a subclasse.');
        self::assertSame(['@logger', '@core_parameters'], $definition->getArguments(), 'Os argumentos do core devem ser preservados.');
    }

    public function testNoopWhenCoreServiceIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new OverrideIntervalSchedulerPass())->process($container);

        self::assertFalse($container->hasDefinition(self::SERVICE_ID), 'Sem o serviço do core, o pass não deve criar nada nem lançar erro.');
    }
}
