-- UCC HR late-only payroll cleanup (additive/destructive-column migration)
-- Select the existing UCC HR database before importing this file.
-- Take a verified backup first. Values from removed columns remain recoverable
-- only from that backup after this migration runs.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Late is the only monetary deduction retained by the application. Make every
-- historical item internally consistent before obsolete columns are dropped.
UPDATE payroll_items
SET total_deductions=late_deduction,
    net_pay=GREATEST(gross_pay-late_deduction, 0);

ALTER TABLE payroll_items
    DROP COLUMN IF EXISTS undertime_deduction,
    DROP COLUMN IF EXISTS absence_deduction,
    DROP COLUMN IF EXISTS policy_deductions,
    DROP COLUMN IF EXISTS sss,
    DROP COLUMN IF EXISTS philhealth,
    DROP COLUMN IF EXISTS pagibig,
    DROP COLUMN IF EXISTS tax;

DELETE FROM settings
WHERE `key` IN (
    'undertime_deduction_enabled', 'absence_deduction_enabled',
    'sss_rate', 'philhealth_rate', 'pagibig_rate', 'pagibig_cap',
    'withholding_threshold', 'withholding_rate'
);

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-06-remove-obsolete-deductions-v1',
    'Keep Late as the only deduction and remove obsolete statutory/broad deduction fields'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

SELECT
    (SELECT COUNT(*) FROM payroll_items) AS payroll_items_preserved,
    (SELECT COUNT(*) FROM payroll_runs) AS payroll_runs_preserved,
    (SELECT COUNT(*) FROM settings) AS active_settings_preserved;
