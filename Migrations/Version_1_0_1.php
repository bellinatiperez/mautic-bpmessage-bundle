<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use MauticPlugin\MauticBpMessageBundle\Migration\AbstractMigration;

/**
 * Add priority column to bpmessage_lot table
 * Example migration to demonstrate the system
 */
class Version_1_0_1 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('bpmessage_lot'));

            // Apply if priority column doesn't exist
            return !$table->hasColumn('priority');
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('bpmessage_lot');

        // Check if column exists before adding (extra safety)
        if (!$this->hasColumn($table, 'priority')) {
            // Add priority column
            $this->addSql("ALTER TABLE {$table} ADD priority TINYINT(4) NOT NULL DEFAULT 0 AFTER status");

            // Add index for priority
            $this->addSql("CREATE INDEX idx_priority ON {$table} (priority)");
        }
    }
}
