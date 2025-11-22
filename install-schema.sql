-- =====================================================
-- BpMessage Plugin - Manual Installation Script
-- =====================================================
-- This script creates all tables and foreign keys for the BpMessage plugin
-- Use this if automatic migrations failed during deployment
--
-- Usage:
--   mysql -u<user> -p<password> <database> < install-schema.sql
--   OR
--   ddev exec mysql < plugins/MauticBpMessageBundle/install-schema.sql
--
-- IMPORTANT: Adjust the table prefix if your Mautic installation uses one
-- =====================================================

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL';

-- =====================================================
-- Table: bpmessage_lot
-- =====================================================
-- Stores batch/lot information for BpMessage sending
-- =====================================================

CREATE TABLE IF NOT EXISTS `bpmessage_lot` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `external_lot_id` VARCHAR(191) DEFAULT NULL COMMENT 'External lot ID from BpMessage API',
    `name` VARCHAR(191) DEFAULT NULL COMMENT 'Lot name for identification',
    `start_date` DATETIME NOT NULL COMMENT 'Lot validity start date',
    `end_date` DATETIME NOT NULL COMMENT 'Lot validity end date',
    `user_cpf` VARCHAR(191) DEFAULT NULL COMMENT 'CPF of the user who created the lot',
    `id_quota_settings` INT(11) NOT NULL COMMENT 'Quota settings ID from BpMessage',
    `id_service_settings` INT(11) NOT NULL COMMENT 'Service settings ID from BpMessage',
    `service_type` INT(11) DEFAULT NULL COMMENT 'Service type (SMS, WhatsApp, RCS)',
    `id_book_business_send_group` INT(11) DEFAULT NULL COMMENT 'Book business send group ID',
    `book_business_foreign_id` VARCHAR(255) DEFAULT NULL COMMENT 'Foreign ID for book business',
    `image_url` LONGTEXT DEFAULT NULL COMMENT 'URL of the image to be sent',
    `image_name` VARCHAR(191) DEFAULT NULL COMMENT 'Name of the image file',
    `status` VARCHAR(191) DEFAULT NULL COMMENT 'Lot status: CREATING, OPEN, CLOSED, PROCESSING, FINISHED, FAILED',
    `priority` TINYINT(4) NOT NULL DEFAULT 0 COMMENT 'Priority level for processing',
    `messages_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Total number of messages in this lot',
    `created_at` DATETIME NOT NULL COMMENT 'Timestamp when lot was created',
    `finished_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when lot processing finished',
    `campaign_id` INT(11) DEFAULT NULL COMMENT 'Associated Mautic campaign ID',
    `api_base_url` VARCHAR(191) DEFAULT NULL COMMENT 'BpMessage API base URL',
    `batch_size` INT(11) NOT NULL DEFAULT 1000 COMMENT 'Maximum messages per batch',
    `time_window` INT(11) NOT NULL DEFAULT 300 COMMENT 'Time window in seconds before auto-close',
    `error_message` LONGTEXT DEFAULT NULL COMMENT 'Error message if lot failed',
    PRIMARY KEY (`id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_campaign_id` (`campaign_id`),
    INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Stores BpMessage batch/lot information for sending messages';

-- =====================================================
-- Table: bpmessage_queue
-- =====================================================
-- Stores individual messages pending to be sent
-- =====================================================

CREATE TABLE IF NOT EXISTS `bpmessage_queue` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lot_id` INT UNSIGNED NOT NULL COMMENT 'Reference to bpmessage_lot',
    `lead_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Reference to Mautic lead/contact',
    `payload_json` LONGTEXT NOT NULL COMMENT 'JSON payload with message data',
    `status` VARCHAR(191) DEFAULT NULL COMMENT 'Message status: PENDING, SENT, FAILED',
    `retry_count` SMALLINT(6) NOT NULL DEFAULT 0 COMMENT 'Number of retry attempts',
    `error_message` LONGTEXT DEFAULT NULL COMMENT 'Error message if sending failed',
    `created_at` DATETIME NOT NULL COMMENT 'Timestamp when message was queued',
    `sent_at` DATETIME DEFAULT NULL COMMENT 'Timestamp when message was sent',
    PRIMARY KEY (`id`),
    INDEX `idx_lot_status` (`lot_id`, `status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_status` (`status`),
    INDEX `idx_lead_id` (`lead_id`),
    CONSTRAINT `fk_bpmessage_queue_lot`
        FOREIGN KEY (`lot_id`)
        REFERENCES `bpmessage_lot` (`id`)
        ON DELETE CASCADE
        ON UPDATE NO ACTION,
    CONSTRAINT `fk_bpmessage_queue_lead`
        FOREIGN KEY (`lead_id`)
        REFERENCES `leads` (`id`)
        ON DELETE CASCADE
        ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Queue of individual messages to be sent via BpMessage';

-- =====================================================
-- Verification Queries
-- =====================================================
-- Run these to verify the tables were created correctly
-- =====================================================

-- Check if tables exist
SELECT 'bpmessage_lot' AS table_name, COUNT(*) AS exists_count
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_lot'
UNION ALL
SELECT 'bpmessage_queue', COUNT(*)
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_queue';

-- Check foreign keys
SELECT
    kcu.CONSTRAINT_NAME,
    kcu.TABLE_NAME,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    rc.DELETE_RULE,
    rc.UPDATE_RULE
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE kcu.TABLE_SCHEMA = DATABASE()
  AND kcu.TABLE_NAME = 'bpmessage_queue'
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL;

-- Restore settings
SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;

-- =====================================================
-- Installation Complete
-- =====================================================
SELECT '✓ BpMessage tables created successfully!' AS status;
