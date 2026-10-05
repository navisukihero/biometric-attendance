-- Run only after a verified backup of the existing database. This is an
-- additive enum expansion: no employee, compensation, or payroll rows are
-- deleted or rewritten. Older Daily/Hourly rows retain their values.

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

ALTER TABLE employees
    MODIFY COLUMN pay_type ENUM('Daily','Hourly','Monthly') NOT NULL DEFAULT 'Daily';

ALTER TABLE employee_compensation_history
    MODIFY COLUMN pay_type ENUM('Daily','Hourly','Monthly') NOT NULL;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-28-monthly-pay-type-v1',
    'Enable Daily, Hourly, and Monthly effective compensation without changing frozen payroll'
)
ON DUPLICATE KEY UPDATE migration_key=VALUES(migration_key);

SELECT 'Monthly Pay Type enabled for Employee Records and compensation history.' AS result;
