<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use MauticPlugin\MauticBpMessageBundle\Migration\AbstractMigration;

/**
 * Initial migration - Create bpmessage_lot and bpmessage_queue tables.
 */
class Version_1_0_0 extends AbstractMigration
{
    protected function isApplicable(Schema $schema): bool
    {
        try {
            return !$schema->hasTable($this->concatPrefix('bpmessage_lot'))
                || !$schema->hasTable($this->concatPrefix('bpmessage_queue'));
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function up(): void
    {
        $lotTable   = $this->concatPrefix('bpmessage_lot');
        $queueTable = $this->concatPrefix('bpmessage_queue');

        // Create bpmessage_lot table
        $this->addSql("
            CREATE TABLE IF NOT EXISTS {$lotTable} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `external_lot_id` VARCHAR(191) DEFAULT NULL,
                `name` VARCHAR(191) DEFAULT NULL,
                `start_date` DATETIME NOT NULL,
                `end_date` DATETIME NOT NULL,
                `user_cpf` VARCHAR(191) DEFAULT NULL,
                `id_quota_settings` INT(11) NOT NULL,
                `id_service_settings` INT(11) NOT NULL,
                `service_type` INT(11) DEFAULT NULL,
                `id_book_business_send_group` INT(11) DEFAULT NULL,
                `book_business_foreign_id` VARCHAR(255) DEFAULT NULL,
                `image_url` LONGTEXT DEFAULT NULL,
                `image_name` VARCHAR(191) DEFAULT NULL,
                `status` VARCHAR(191) DEFAULT NULL,
                `messages_count` INT(11) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `finished_at` DATETIME DEFAULT NULL,
                `campaign_id` INT(11) DEFAULT NULL,
                `api_base_url` VARCHAR(191) DEFAULT NULL,
                `batch_size` INT(11) NOT NULL DEFAULT 1000,
                `time_window` INT(11) NOT NULL DEFAULT 300,
                `error_message` LONGTEXT DEFAULT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_status` (`status`),
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_campaign_id` (`campaign_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create bpmessage_queue table
        $this->addSql("
            CREATE TABLE IF NOT EXISTS {$queueTable} (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `lot_id` INT UNSIGNED NOT NULL,
                `lead_id` BIGINT(20) UNSIGNED NOT NULL,
                `payload_json` LONGTEXT NOT NULL,
                `status` VARCHAR(191) DEFAULT NULL,
                `retry_count` SMALLINT(6) NOT NULL DEFAULT 0,
                `error_message` LONGTEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `sent_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_lot_status` (`lot_id`, `status`),
                INDEX `idx_created_at` (`created_at`),
                INDEX `idx_status` (`status`),
                CONSTRAINT `fk_bpmessage_queue_lot`
                    FOREIGN KEY (`lot_id`)
                    REFERENCES {$lotTable} (`id`)
                    ON DELETE CASCADE,
                CONSTRAINT `fk_bpmessage_queue_lead`
                    FOREIGN KEY (`lead_id`)
                    REFERENCES {$this->concatPrefix('leads')} (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
