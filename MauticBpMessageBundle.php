<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle;

use Mautic\PluginBundle\Bundle\PluginBundleBase;
use MauticPlugin\MauticBpMessageBundle\Migration\Engine;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * MauticBpMessageBundle - Integração com BpMessage API para envio de mensagens em lote.
 *
 * Este plugin permite enviar mensagens SMS, WhatsApp e RCS através da API BpMessage
 * utilizando o sistema de lotes para otimizar o envio de grandes volumes.
 */
class MauticBpMessageBundle extends PluginBundleBase
{
    /**
     * {@inheritdoc}
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    /**
     * {@inheritdoc}
     */
    public function boot(): void
    {
        parent::boot();

        $this->runMigrations();
    }

    /**
     * Run plugin migrations.
     */
    private function runMigrations(): void
    {
        /** @var ContainerInterface $container */
        $container = $this->container;

        if (!$container) {
            return;
        }

        try {
            $entityManager = $container->get('doctrine.orm.entity_manager');
            $tablePrefix   = $container->getParameter('mautic.db_table_prefix');

            $engine = new Engine(
                $entityManager,
                $tablePrefix,
                $this->getPath(),
                'MauticBpMessageBundle'
            );

            $engine->up();
        } catch (\Exception $e) {
            // Log error but don't break the application
            if ($container->has('monolog.logger.mautic')) {
                $logger = $container->get('monolog.logger.mautic');
                $logger->error('BpMessage migrations failed: '.$e->getMessage());
            }
        }
    }
}
