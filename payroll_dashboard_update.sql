-- Employee-scoped Payroll Dashboard and payment details
-- Target: MariaDB 10.4+ / MySQL-compatible UCC HR installation
-- This migration preserves every existing payroll run and item.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

ALTER TABLE payroll_runs
    ADD COLUMN IF NOT EXISTS scope_employee_id INT UNSIGNED NULL AFTER period_end,
    ADD COLUMN IF NOT EXISTS payment_method ENUM('Cash','Bank Transfer') NOT NULL DEFAULT 'Cash' AFTER status,
    ADD COLUMN IF NOT EXISTS payment_status ENUM('Pending','Paid') NOT NULL DEFAULT 'Pending' AFTER payment_method,
    ADD COLUMN IF NOT EXISTS payment_updated_at DATETIME NULL AFTER payment_status;

-- The former unique period key prevented two different employees from being
-- processed for the same dates. Employee-scoped uniqueness is the correct rule.
DROP INDEX IF EXISTS uq_payroll_period ON payroll_runs;
CREATE INDEX IF NOT EXISTS idx_payroll_period ON payroll_runs (period_start, period_end);
CREATE UNIQUE INDEX IF NOT EXISTS uq_payroll_scope_period
    ON payroll_runs (scope_employee_id, period_start, period_end);
CREATE INDEX IF NOT EXISTS idx_payroll_payment_status
    ON payroll_runs (payment_status, period_end);

UPDATE payroll_runs
SET payment_status = CASE
        WHEN status IN ('Paid','Released') THEN 'Paid'
        ELSE 'Pending'
    END,
    payment_updated_at = COALESCE(payment_updated_at, paid_at, approved_at, processed_at)
WHERE payment_updated_at IS NULL;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-10-payroll-dashboard-v1',
    'Add employee-scoped payroll generation and synchronized payment details'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

SELECT migration_key, applied_at
FROM schema_migrations
WHERE migration_key='2026-09-10-payroll-dashboard-v1';

