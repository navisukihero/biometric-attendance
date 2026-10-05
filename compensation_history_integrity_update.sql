-- UCC HR compensation-history integrity repair
-- MariaDB 10.4 compatible and safe to rerun.
--
-- Root cause addressed:
-- Older compensation rows were created before Employment Type became
-- mandatory. The employee master record was later completed, but those dated
-- rows retained NULL and correctly blocked payroll for their effective dates.
-- Backfill only the missing Employment Type from the owning employee record;
-- rates, pay types, effective dates, and finalized payroll snapshots are not
-- recalculated or overwritten.

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

SET @ucchr_compensation_rows_to_repair := (
    SELECT COUNT(*)
    FROM employee_compensation_history ech
    JOIN employees e ON e.id=ech.employee_id
    WHERE ech.employment_type IS NULL
      AND e.employment_type IN ('Full-Time','Part-Time')
);

UPDATE employee_compensation_history ech
JOIN employees e ON e.id=ech.employee_id
SET ech.employment_type=e.employment_type
WHERE ech.employment_type IS NULL
  AND e.employment_type IN ('Full-Time','Part-Time');

-- The current application permits Daily, Hourly, and Monthly compensation. These
-- constraints stop direct SQL/import paths from recreating the invalid state
-- that the Employee form already rejects. Historical payroll_items remain
-- untouched immutable snapshots.
ALTER TABLE employees
    MODIFY COLUMN employment_type ENUM('Full-Time','Part-Time') NOT NULL,
    MODIFY COLUMN pay_type ENUM('Daily','Hourly','Monthly') NOT NULL DEFAULT 'Daily';

ALTER TABLE employee_compensation_history
    MODIFY COLUMN employment_type ENUM('Full-Time','Part-Time') NOT NULL,
    MODIFY COLUMN pay_type ENUM('Daily','Hourly','Monthly') NOT NULL;

INSERT INTO activity_logs
    (user_id, action, module, record_id, description, old_values, new_values,
     ip_address, created_at)
SELECT
    NULL,
    'Repaired legacy compensation history',
    'Employees',
    NULL,
    CONCAT('Backfilled Employment Type on ', @ucchr_compensation_rows_to_repair,
           ' legacy compensation row(s); rates and effective dates were preserved.'),
    NULL,
    CONCAT('{"repaired_rows":', @ucchr_compensation_rows_to_repair, '}'),
    NULL,
    NOW()
WHERE @ucchr_compensation_rows_to_repair > 0;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-19-compensation-history-integrity-v1',
    'Backfill required Employment Type in dated compensation and enforce supported HR pay types'
)
ON DUPLICATE KEY UPDATE migration_key=VALUES(migration_key);

SELECT
    @ucchr_compensation_rows_to_repair AS repaired_history_rows,
    (SELECT COUNT(*) FROM employees
     WHERE employment_type NOT IN ('Full-Time','Part-Time')
        OR pay_type NOT IN ('Daily','Hourly','Monthly')
        OR basic_rate<=0) AS invalid_employee_compensation,
    (SELECT COUNT(*) FROM employee_compensation_history
     WHERE employment_type NOT IN ('Full-Time','Part-Time')
        OR pay_type NOT IN ('Daily','Hourly','Monthly')
        OR basic_rate<=0) AS invalid_history_compensation,
    (SELECT COUNT(*) FROM schema_migrations
     WHERE migration_key='2026-09-19-compensation-history-integrity-v1') AS migration_marker;
