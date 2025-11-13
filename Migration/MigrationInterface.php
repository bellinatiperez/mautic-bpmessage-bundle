<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Migration;

interface MigrationInterface
{
    /**
     * Check if the migration should be executed.
     */
    public function shouldExecute(): bool;

    /**
     * Execute the migration.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function execute(): void;
}
