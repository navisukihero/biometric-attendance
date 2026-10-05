-- UCC HR fixed daily-rate payroll migration
--
-- Active compensation model:
--   Approved Daily Rate = employee.basic_rate = employee.daily_rate
--   Monthly Basic Salary = Approved Daily Rate x 30
--
-- Existing payroll_items are historical snapshots. This migration never
-- recalculates gross pay, deductions, net pay, or an existing payslip.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO settings (`key`, `value`) VALUES ('working_day_basis', '30')
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);

-- Never downgrade an installation after the later flexible-pay-type migration
-- has been applied. This makes an accidental rerun of this older update safe.
SET @ucchr_flexible_pay_types_installed = (
    SELECT COUNT(*) FROM schema_migrations
    WHERE migration_key='2026-09-11-flexible-pay-types-v1'
);

SET @ucchr_regular_hours_per_day = COALESCE(
    NULLIF((SELECT CAST(`value` AS DECIMAL(10,2)) FROM settings WHERE `key`='regular_hours_per_day' LIMIT 1), 0),
    8
);

-- Convert active employee master data before narrowing the enum. Existing
-- Hourly values become their daily equivalent; Monthly values use the same
-- fixed 30-day basis required by the application.
UPDATE employees
SET daily_rate = ROUND(CASE pay_type
        WHEN 'Monthly' THEN basic_rate / 30
        WHEN 'Hourly' THEN basic_rate * @ucchr_regular_hours_per_day
        ELSE CASE WHEN basic_rate > 0 THEN basic_rate ELSE daily_rate END
    END, 2),
    basic_rate = ROUND(CASE pay_type
        WHEN 'Monthly' THEN basic_rate / 30
        WHEN 'Hourly' THEN basic_rate * @ucchr_regular_hours_per_day
        ELSE CASE WHEN basic_rate > 0 THEN basic_rate ELSE daily_rate END
    END, 2),
    pay_type = 'Daily'
WHERE @ucchr_flexible_pay_types_installed = 0;

-- Draft recalculation can still read effective-dated compensation safely.
-- Finalized payroll items below remain unchanged and therefore keep their
-- original money values even when this source history is normalized.
UPDATE employee_compensation_history
SET basic_rate = ROUND(CASE pay_type
        WHEN 'Monthly' THEN basic_rate / 30
        WHEN 'Hourly' THEN basic_rate * @ucchr_regular_hours_per_day
        ELSE basic_rate
    END, 2),
    pay_type = 'Daily'
WHERE @ucchr_flexible_pay_types_installed = 0;

SET @ucchr_daily_employee_alter = IF(
    @ucchr_flexible_pay_types_installed > 0,
    'SELECT 1',
    'ALTER TABLE employees MODIFY COLUMN pay_type ENUM(''Daily'') NOT NULL DEFAULT ''Daily'', MODIFY COLUMN basic_rate DECIMAL(12,2) NOT NULL DEFAULT 0, MODIFY COLUMN daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0'
);
PREPARE ucchr_daily_employee_stmt FROM @ucchr_daily_employee_alter;
EXECUTE ucchr_daily_employee_stmt;
DEALLOCATE PREPARE ucchr_daily_employee_stmt;

SET @ucchr_daily_history_alter = IF(
    @ucchr_flexible_pay_types_installed > 0,
    'SELECT 1',
    'ALTER TABLE employee_compensation_history MODIFY COLUMN pay_type ENUM(''Daily'') NOT NULL'
);
PREPARE ucchr_daily_history_stmt FROM @ucchr_daily_history_alter;
EXECUTE ucchr_daily_history_stmt;
DEALLOCATE PREPARE ucchr_daily_history_stmt;

ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS monthly_basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER hourly_equivalent_rate;

-- Preserve historical payslip values exactly. Before this column existed,
-- regular_pay was the stored basic-pay snapshot for that payroll item.
UPDATE payroll_items
SET monthly_basic_salary = regular_pay
WHERE monthly_basic_salary = 0 AND regular_pay > 0;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-10-fixed-daily-rate-payroll-v1',
    'Approved Daily Rate with fixed 30-day monthly basic salary and immutable payroll-item snapshot'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

SELECT
    (SELECT COUNT(*) FROM employees WHERE pay_type<>'Daily') AS non_daily_employees,
    (SELECT COUNT(*) FROM employee_compensation_history WHERE pay_type<>'Daily') AS non_daily_history,
    (SELECT COUNT(*) FROM payroll_items WHERE monthly_basic_salary<>regular_pay) AS historical_basic_snapshot_differences,
    (SELECT COUNT(*) FROM schema_migrations WHERE migration_key='2026-09-10-fixed-daily-rate-payroll-v1') AS migration_marker;
