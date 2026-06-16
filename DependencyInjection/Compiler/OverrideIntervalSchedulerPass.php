<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\DependencyInjection\Compiler;

use MauticPlugin\MauticBpMessageBundle\Scheduler\FixedHourIntervalScheduler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Substitui a IMPLEMENTAÇÃO do scheduler de intervalo do core pelo
 * FixedHourIntervalScheduler, sem editar o core e de forma robusta a load order.
 *
 * Por que CompilerPass (e não override de serviço no services.php):
 * o EventScheduler injeta o Interval pelo serviço primário
 * 'mautic.campaign.scheduler.interval' (a FQCN é apenas um alias para ele).
 * Redefinir o serviço pelo alias/FQCN no plugin não substitui o primário de
 * forma confiável. Aqui alteramos a CLASSE da definição existente, preservando
 * seus argumentos (logger + CoreParametersHelper, compatíveis com a subclasse).
 */
class OverrideIntervalSchedulerPass implements CompilerPassInterface
{
    private const INTERVAL_SCHEDULER_SERVICE = 'mautic.campaign.scheduler.interval';

    public function process(ContainerBuilder $container): void
    {
        // Defensivo: se o core mudar o id do serviço numa versão futura, não quebra.
        if (!$container->hasDefinition(self::INTERVAL_SCHEDULER_SERVICE)) {
            return;
        }

        $container->getDefinition(self::INTERVAL_SCHEDULER_SERVICE)
            ->setClass(FixedHourIntervalScheduler::class);
    }
}
