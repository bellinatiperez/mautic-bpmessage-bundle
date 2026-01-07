<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use MauticPlugin\MauticBpMessageBundle\Migration\AbstractMigration;

/**
 * Add event_id column to bpmessage_lot table.
 *
 * This column stores the campaign event ID, ensuring each campaign event
 * has its own separate lot. This prevents email duplication conflicts
 * between different events that might use the same service settings.
 */
class Version_1_0_5 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('bpmessage_lot'));

            // Apply if event_id column doesn't exist
            return !$table->hasColumn('event_id');
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('bpmessage_lot');

        // Add event_id column if it doesn't exist
        if (!$this->hasColumn($table, 'event_id')) {
            $this->addSql("ALTER TABLE {$table} ADD event_id INT DEFAULT NULL AFTER campaign_id");
            $this->addSql("CREATE INDEX idx_event_id ON {$table} (event_id)");
        }
    }
}
