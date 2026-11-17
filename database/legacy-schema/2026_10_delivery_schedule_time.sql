-- FILE PURPOSE
-- File   : database/legacy-schema/2026_10_delivery_schedule_time.sql
-- Feature: Legacy database
-- Purpose: Old SQL patch: adds pickup time to deliveries. Kept for reference; Laravel migrations are
--          used now.

-- AstraTrack migration: add pickup time support for the Delivery Details editor.
-- Run against the target database before deploying shipment_view.php.
-- Safe to rerun; does not change existing delivery dates or records.

SET NAMES utf8mb4;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'scheduled_time') = 0,
    'ALTER TABLE deliveries ADD COLUMN scheduled_time TIME NULL AFTER scheduled_date',
    'SELECT ''deliveries.scheduled_time already OK'' AS info');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) AS scheduled_time_column_expected_1
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME = 'scheduled_time';
