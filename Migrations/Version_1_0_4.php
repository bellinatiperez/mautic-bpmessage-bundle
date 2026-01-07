<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use MauticPlugin\MauticBpMessageBundle\Migration\AbstractMigration;

/**
 * Add crm_id column to bpmessage_lot table.
 *
 * This column stores the CRM identifier for the lot, used for route name lookup.
 */
class Version_1_0_4 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            $table = $schema->getTable($this->concatPrefix('bpmessage_lot'));

            // Apply if crm_id column doesn't exist
            return !$table->hasColumn('crm_id');
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function up(): void
    {
        $table = $this->concatPrefix('bpmessage_lot');

        // Add crm_id column if it doesn't exist
        if (!$this->hasColumn($table, 'crm_id')) {
            $this->addSql("ALTER TABLE {$table} ADD crm_id VARCHAR(191) DEFAULT NULL AFTER book_business_foreign_id");
        }
    }
}
