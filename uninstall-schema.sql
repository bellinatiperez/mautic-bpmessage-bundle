-- =====================================================
-- BpMessage Plugin - Uninstall Script
-- =====================================================
-- This script removes all BpMessage tables and data
-- WARNING: This will delete all BpMessage data!
--
-- Usage:
--   mysql -u<user> -p<password> <database> < uninstall-schema.sql
--   OR
--   ddev exec mysql < plugins/MauticBpMessageBundle/uninstall-schema.sql
-- =====================================================

SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;

-- Drop tables in correct order (queue first due to foreign keys)
DROP TABLE IF EXISTS `bpmessage_queue`;
DROP TABLE IF EXISTS `bpmessage_lot`;
DROP TABLE IF EXISTS `plugin_fornecedor_settings`;
DROP TABLE IF EXISTS `plugin_dataAnalytics_settings`;

SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;

-- Verify tables were dropped
SELECT 'bpmessage_lot' AS table_name,
       CASE WHEN COUNT(*) = 0 THEN '✓ DROPPED' ELSE '✗ STILL EXISTS' END AS status
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_lot'
UNION ALL
SELECT 'bpmessage_queue',
       CASE WHEN COUNT(*) = 0 THEN '✓ DROPPED' ELSE '✗ STILL EXISTS' END
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_queue'
UNION ALL
SELECT 'plugin_fornecedor_settings',
       CASE WHEN COUNT(*) = 0 THEN '✓ DROPPED' ELSE '✗ STILL EXISTS' END
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'plugin_fornecedor_settings'
UNION ALL
SELECT 'plugin_dataAnalytics_settings',
       CASE WHEN COUNT(*) = 0 THEN '✓ DROPPED' ELSE '✗ STILL EXISTS' END
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'plugin_dataAnalytics_settings';

SELECT '✓ BpMessage tables removed successfully!' AS status;
