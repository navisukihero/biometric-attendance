-- UCC HR legacy flexible Daily / Hourly / Monthly compensation migration
--
-- Expected Monthly Salary:
--   Daily   = Approved Rate x 30 days
--   Hourly  = Approved Rate x scheduled hours in the selected calendar month
--   Monthly = Approved Rate
--
-- Generated payroll is calculated by PHP and frozen in payroll_items:
--   Daily   = Approved Rate x 30, plus approved OT, less valid deductions
--   Hourly  = eligible regular hours x Approved Rate, plus approved OT,
--             less explicit deductions (missing hours are not deducted twice)
--   Monthly = Approved Rate, plus approved OT, less valid deductions
--
-- This migration changes allowed rate units only. It never recalculates or
-- overwrites existing payroll_items, so released payslips stay immutable.
--
-- Compatibility note: newer installations apply
-- compensation_history_integrity_update.sql after this migration. That update
-- makes Daily/Hourly the only active employee Pay Types. If this older script
-- is accidentally rerun afterward, it must not widen the employee/history
-- columns back to Monthly. Frozen payroll_items remain compatible with legacy
-- Monthly snapshots.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO settings (`key`, `value`) VALUES ('working_day_basis', '30')
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);

SET @ucchr_compensation_integrity_installed := (
    SELECT COUNT(*)
    FROM schema_migrations
    WHERE migration_key='2026-09-19-compensation-history-integrity-v1'
);

-- Keep the original column update for installations that have not reached the
-- integrity migration yet. Use a harmless statement after that migration so
-- a rerun cannot make Monthly selectable again.
SET @ucchr_employee_pay_type_sql := IF(
    @ucchr_compensation_integrity_installed > 0,
    'SELECT 1',
    'ALTER TABLE employees MODIFY COLUMN pay_type ENUM(''Daily'',''Hourly'',''Monthly'') NOT NULL DEFAULT ''Daily'', MODIFY COLUMN basic_rate DECIMAL(12,2) NOT NULL DEFAULT 0, MODIFY COLUMN daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0'
);
PREPARE ucchr_employee_pay_type_stmt FROM @ucchr_employee_pay_type_sql;
EXECUTE ucchr_employee_pay_type_stmt;
DEALLOCATE PREPARE ucchr_employee_pay_type_stmt;

SET @ucchr_history_pay_type_sql := IF(
    @ucchr_compensation_integrity_installed > 0,
    'SELECT 1',
    'ALTER TABLE employee_compensation_history MODIFY COLUMN pay_type ENUM(''Daily'',''Hourly'',''Monthly'') NOT NULL'
);
PREPARE ucchr_history_pay_type_stmt FROM @ucchr_history_pay_type_sql;
EXECUTE ucchr_history_pay_type_stmt;
DEALLOCATE PREPARE ucchr_history_pay_type_stmt;

ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS monthly_basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER hourly_equivalent_rate;

-- Preserve the previous basic-pay snapshot for historical rows created before
-- monthly_basic_salary existed. No gross, deduction, or net value is changed.
UPDATE payroll_items
SET monthly_basic_salary = regular_pay
WHERE monthly_basic_salary = 0 AND regular_pay > 0;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-11-flexible-pay-types-v1',
    'Daily x 30, schedule-based Hourly, and configured Monthly salary calculations with immutable payroll snapshots'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

SELECT
    (SELECT COUNT(*) FROM employees
     WHERE IF(
        @ucchr_compensation_integrity_installed > 0,
        pay_type NOT IN ('Daily','Hourly'),
        pay_type NOT IN ('Daily','Hourly','Monthly')
     )) AS invalid_employee_pay_types,
    (SELECT COUNT(*) FROM employee_compensation_history
     WHERE IF(
        @ucchr_compensation_integrity_installed > 0,
        pay_type NOT IN ('Daily','Hourly'),
        pay_type NOT IN ('Daily','Hourly','Monthly')
     )) AS invalid_history_pay_types,
    (SELECT COUNT(*) FROM schema_migrations WHERE migration_key='2026-09-11-flexible-pay-types-v1') AS migration_marker;
