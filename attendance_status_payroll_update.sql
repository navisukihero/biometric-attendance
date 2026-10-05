-- UCC HR schedule-based attendance status and payroll deduction migration
--
-- Normal completed workdays are classified by the PHP attendance processor as
-- PRESENT, HALF_DAY, or ABSENT. Late and undertime remain measured details.
-- Payroll snapshots an explicit absence/unpaid-day deduction for Daily and
-- Monthly employees. Hourly employees are not double-deducted because only
-- their eligible worked minutes are paid.
--
-- This migration is additive. Existing released payslips retain zero in the
-- new column and are never recalculated.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS half_day_deduction DECIMAL(12,2) NOT NULL DEFAULT 0
        AFTER undertime_deduction,
    ADD COLUMN IF NOT EXISTS absence_deduction DECIMAL(12,2) NOT NULL DEFAULT 0
        AFTER half_day_deduction;

INSERT INTO settings (`key`, `value`) VALUES
    ('half_day_minimum_percent', '50'),
    ('full_day_minimum_percent', '75')
ON DUPLICATE KEY UPDATE `value`=`value`;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-11-attendance-status-payroll-v1',
    'Schedule-derived Present/Half-Day/Absent status with frozen absence and unpaid-day payroll deductions'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

SELECT
    (SELECT COUNT(*)
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE()
       AND TABLE_NAME='payroll_items'
       AND COLUMN_NAME='half_day_deduction') AS half_day_deduction_column,
    (SELECT COUNT(*)
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE()
       AND TABLE_NAME='payroll_items'
       AND COLUMN_NAME='absence_deduction') AS absence_deduction_column,
    (SELECT COUNT(*)
     FROM schema_migrations
     WHERE migration_key='2026-09-11-attendance-status-payroll-v1') AS migration_marker;
