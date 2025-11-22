-- =====================================================
-- BpMessage Plugin - Schema Verification Script
-- =====================================================
-- This script verifies the BpMessage tables installation
--
-- Usage:
--   mysql -u<user> -p<password> <database> < verify-schema.sql
--   OR
--   ddev exec mysql < plugins/MauticBpMessageBundle/verify-schema.sql
-- =====================================================

SELECT '=====================================================\n' AS '';
SELECT 'BpMessage Plugin - Schema Verification\n' AS '';
SELECT '=====================================================\n' AS '';

-- =====================================================
-- 1. Check if tables exist
-- =====================================================
SELECT '\n1. TABLE EXISTENCE CHECK\n' AS '';

SELECT
    table_name,
    CASE
        WHEN table_name = 'bpmessage_lot' THEN '✓ EXISTS'
        WHEN table_name = 'bpmessage_queue' THEN '✓ EXISTS'
        ELSE '✗ MISSING'
    END AS status,
    table_rows AS approximate_rows,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('bpmessage_lot', 'bpmessage_queue')
ORDER BY table_name;

-- =====================================================
-- 2. Check table structures
-- =====================================================
SELECT '\n2. BPMESSAGE_LOT STRUCTURE\n' AS '';

SELECT
    column_name,
    column_type,
    is_nullable,
    column_default,
    column_key
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_lot'
ORDER BY ordinal_position;

SELECT '\n3. BPMESSAGE_QUEUE STRUCTURE\n' AS '';

SELECT
    column_name,
    column_type,
    is_nullable,
    column_default,
    column_key
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_queue'
ORDER BY ordinal_position;

-- =====================================================
-- 4. Check foreign keys
-- =====================================================
SELECT '\n4. FOREIGN KEY CONSTRAINTS\n' AS '';

SELECT
    kcu.CONSTRAINT_NAME,
    kcu.TABLE_NAME,
    kcu.COLUMN_NAME,
    CONCAT(kcu.REFERENCED_TABLE_NAME, '.', kcu.REFERENCED_COLUMN_NAME) AS ref_table,
    rc.DELETE_RULE,
    rc.UPDATE_RULE,
    '✓ OK' AS status
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
    ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
    AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE kcu.TABLE_SCHEMA = DATABASE()
  AND kcu.TABLE_NAME = 'bpmessage_queue'
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY kcu.CONSTRAINT_NAME;

-- =====================================================
-- 5. Check indexes
-- =====================================================
SELECT '\n5. INDEXES ON BPMESSAGE_LOT\n' AS '';

SELECT
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns,
    CASE WHEN NON_UNIQUE = 0 THEN 'UNIQUE' ELSE 'INDEX' END AS index_type
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_lot'
GROUP BY INDEX_NAME, NON_UNIQUE
ORDER BY INDEX_NAME;

SELECT '\n6. INDEXES ON BPMESSAGE_QUEUE\n' AS '';

SELECT
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns,
    CASE WHEN NON_UNIQUE = 0 THEN 'UNIQUE' ELSE 'INDEX' END AS index_type
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_queue'
GROUP BY INDEX_NAME, NON_UNIQUE
ORDER BY INDEX_NAME;

-- =====================================================
-- 7. Verify data types compatibility
-- =====================================================
SELECT '\n7. DATA TYPE COMPATIBILITY CHECK\n' AS '';

-- Check lot_id compatibility
SELECT
    'lot_id compatibility' AS check_name,
    CONCAT('bpmessage_lot.id = ', lot.COLUMN_TYPE) AS lot_type,
    CONCAT('bpmessage_queue.lot_id = ', queue.COLUMN_TYPE) AS queue_type,
    CASE
        WHEN lot.COLUMN_TYPE = queue.COLUMN_TYPE THEN '✓ COMPATIBLE'
        ELSE '✗ INCOMPATIBLE'
    END AS status
FROM information_schema.columns lot
JOIN information_schema.columns queue
WHERE lot.table_schema = DATABASE()
  AND lot.table_name = 'bpmessage_lot'
  AND lot.column_name = 'id'
  AND queue.table_schema = DATABASE()
  AND queue.table_name = 'bpmessage_queue'
  AND queue.column_name = 'lot_id'

UNION ALL

-- Check lead_id compatibility
SELECT
    'lead_id compatibility',
    CONCAT('leads.id = ', leads.COLUMN_TYPE),
    CONCAT('bpmessage_queue.lead_id = ', queue.COLUMN_TYPE),
    CASE
        WHEN leads.COLUMN_TYPE = queue.COLUMN_TYPE THEN '✓ COMPATIBLE'
        ELSE '✗ INCOMPATIBLE'
    END
FROM information_schema.columns leads
JOIN information_schema.columns queue
WHERE leads.table_schema = DATABASE()
  AND leads.table_name = 'leads'
  AND leads.column_name = 'id'
  AND queue.table_schema = DATABASE()
  AND queue.table_name = 'bpmessage_queue'
  AND queue.column_name = 'lead_id';

-- =====================================================
-- 8. Summary
-- =====================================================
SELECT '\n8. INSTALLATION SUMMARY\n' AS '';

SELECT
    'Total Tables' AS metric,
    COUNT(*) AS value,
    CASE WHEN COUNT(*) = 2 THEN '✓ OK' ELSE '✗ INCOMPLETE' END AS status
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('bpmessage_lot', 'bpmessage_queue')

UNION ALL

SELECT
    'Total Foreign Keys',
    COUNT(*),
    CASE WHEN COUNT(*) = 2 THEN '✓ OK' ELSE '✗ INCOMPLETE' END
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'bpmessage_queue'
  AND REFERENCED_TABLE_NAME IS NOT NULL

UNION ALL

SELECT
    'Total Indexes on lot',
    COUNT(DISTINCT INDEX_NAME),
    '✓ OK'
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_lot'

UNION ALL

SELECT
    'Total Indexes on queue',
    COUNT(DISTINCT INDEX_NAME),
    '✓ OK'
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'bpmessage_queue';

SELECT '\n=====================================================\n' AS '';
SELECT 'Verification Complete!\n' AS '';
SELECT '=====================================================\n' AS '';
