<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use MauticPlugin\MauticBpMessageBundle\Migration\AbstractMigration;

/**
 * Add create_lot_payload column to bpmessage_lot table.
 *
 * This JSON column stores the complete payload sent to BpMessage API createLot/createEmailLot,
 * allowing monitoring of values like startDate and endDate, and future extensibility
 * without requiring additional columns.
 */
class Version_1_0_2 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('bpmessage_lot'));

            // Apply if create_lot_payload column doesn't exist
            return !$table->hasColumn('create_lot_payload');
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('bpmessage_lot');

        // Check if column exists before adding (extra safety)
        if (!$this->hasColumn($table, 'create_lot_payload')) {
            // Add create_lot_payload column as JSON (MySQL 5.7.8+)
            // JSON type provides automatic validation, binary storage, and optimized functions
            $this->addSql("ALTER TABLE {$table} ADD create_lot_payload JSON DEFAULT NULL COMMENT 'JSON payload sent to createLot API' AFTER error_message");
        }
    }
}
