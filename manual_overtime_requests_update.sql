-- Enforce employee-submitted overtime requests.
-- Apply after integrated_attendance_payroll_update.sql.

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Pending automatic placeholders were never employee requests and are not
-- payable. Clear any stale attendance projection before removing them.
UPDATE attendance a
JOIN overtime_requests ot ON ot.attendance_id=a.id
SET a.approved_overtime_minutes=0
WHERE ot.request_source='Automatic'
  AND ot.status='Pending';

DELETE FROM overtime_requests
WHERE request_source='Automatic'
  AND status='Pending';

-- Keep legacy enum members so historical decided records remain readable, but
-- make the safe manual source the database default for all future inserts.
ALTER TABLE overtime_requests
    MODIFY COLUMN request_source ENUM('Automatic','Employee','Administrator')
    NOT NULL DEFAULT 'Employee';

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-12-manual-overtime-requests-v1',
    'Remove pending automatic OT placeholders and default requests to Employee'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

SELECT
    (SELECT COUNT(*) FROM overtime_requests
     WHERE request_source='Automatic' AND status='Pending') AS pending_automatic_requests,
    (SELECT COUNT(*) FROM overtime_requests
     WHERE request_source='Employee') AS employee_requests,
    (SELECT COUNT(*) FROM schema_migrations
     WHERE migration_key='2026-09-12-manual-overtime-requests-v1') AS migration_marker;
