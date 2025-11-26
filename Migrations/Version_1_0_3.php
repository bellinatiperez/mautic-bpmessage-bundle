<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use MauticPlugin\MauticBpMessageBundle\Migration\AbstractMigration;

/**
 * Rename externalLotId column to external_lot_id (snake_case convention).
 *
 * The column was incorrectly created with camelCase name in some environments.
 * This migration ensures the column uses snake_case to match Doctrine mapping.
 */
class Version_1_0_3 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('bpmessage_lot'));

            // Apply if old camelCase column exists OR snake_case doesn't exist
            return $table->hasColumn('externalLotId') || !$table->hasColumn('external_lot_id');
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('bpmessage_lot');

        // Rename camelCase to snake_case if the old column exists
        if ($this->hasColumn($table, 'externalLotId')) {
            $this->addSql("ALTER TABLE {$table} CHANGE externalLotId external_lot_id VARCHAR(191) DEFAULT NULL");
        }
        // Add the column if it doesn't exist at all
        elseif (!$this->hasColumn($table, 'external_lot_id')) {
            $this->addSql("ALTER TABLE {$table} ADD external_lot_id VARCHAR(191) DEFAULT NULL AFTER id");
        }
    }
}
