-- UCC HR schedule-session biometric attendance migration
-- Adds independent IN/OUT pairs for Full-Time morning/afternoon sessions and
-- flexible Part-Time periods. Safe to run more than once on MariaDB 10.4+.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS employment_type_snapshot VARCHAR(20) NULL
        AFTER break_minutes,
    ADD COLUMN IF NOT EXISTS legacy_single_pair TINYINT(1) NOT NULL DEFAULT 0
        AFTER employment_type_snapshot;

ALTER TABLE attendance MODIFY COLUMN status ENUM(
    'Present','Late','Absent','On leave',
    'PRESENT','LATE','UNDERTIME','LATE_AND_UNDERTIME','HALF_DAY','ABSENT',
    'PAID_LEAVE','UNPAID_LEAVE','REST_DAY','REGULAR_HOLIDAY',
    'SPECIAL_NON_WORKING_DAY','HOLIDAY_WORK','REST_DAY_WORK','UNSCHEDULED','INCOMPLETE'
) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'PRESENT';

UPDATE attendance a
JOIN employees e ON e.id=a.employee_id
SET a.employment_type_snapshot=e.employment_type
WHERE a.employment_type_snapshot IS NULL;

ALTER TABLE attendance_logs
    ADD COLUMN IF NOT EXISTS session_index TINYINT UNSIGNED NULL AFTER action,
    ADD COLUMN IF NOT EXISTS punch_sequence TINYINT UNSIGNED NULL AFTER session_index,
    ADD COLUMN IF NOT EXISTS punch_request_id VARCHAR(96) NULL AFTER processed_attendance_id;

-- The previous model had at most one IN and one OUT per day, so both existing
-- events belong safely to legacy session 1.
UPDATE attendance_logs
SET session_index=COALESCE(session_index, 1),
    punch_sequence=COALESCE(
        punch_sequence,
        CASE WHEN action='TIME_IN' THEN 1 ELSE 2 END
    );

ALTER TABLE attendance_logs
    MODIFY COLUMN session_index TINYINT UNSIGNED NOT NULL DEFAULT 1,
    MODIFY COLUMN punch_sequence TINYINT UNSIGNED NOT NULL DEFAULT 1;

-- Grandfather every completed one-pair day processed by an older engine,
-- including real ESP32 rows (not only rows named "legacy-import"). This keeps
-- historical attendance/payroll stable when version 5 reprocesses a period.
SET @attendance_session_cutover = COALESCE(
    (SELECT MIN(applied_at) FROM schema_migrations
     WHERE migration_key IN (
         '2026-09-13-multi-session-attendance-v1',
         '2026-09-13-multi-session-attendance-v2'
     )),
    NOW()
);

UPDATE attendance a
SET a.legacy_single_pair=1
WHERE (
        a.processing_version<5
        OR (SELECT MAX(al.created_at) FROM attendance_logs al
            WHERE al.employee_id=a.employee_id AND al.attendance_date=a.scan_date)
            <= @attendance_session_cutover
      )
  AND a.time_in IS NOT NULL
  AND a.time_out IS NOT NULL
  AND (SELECT COUNT(*) FROM attendance_logs al
       WHERE al.employee_id=a.employee_id AND al.attendance_date=a.scan_date)=2
  AND (SELECT COUNT(*) FROM attendance_logs al
       WHERE al.employee_id=a.employee_id AND al.attendance_date=a.scan_date
         AND al.action='TIME_IN')=1
  AND (SELECT COUNT(*) FROM attendance_logs al
       WHERE al.employee_id=a.employee_id AND al.attendance_date=a.scan_date
         AND al.action='TIME_OUT')=1;

UPDATE attendance a
SET a.legacy_single_pair=0
WHERE a.legacy_single_pair=1
  AND EXISTS (
      SELECT 1 FROM attendance_logs al
      WHERE al.employee_id=a.employee_id AND al.attendance_date=a.scan_date
        AND al.session_index>1
  );

-- Add the replacement employee-leading index before dropping the old one;
-- InnoDB may currently use the old key to support the employee foreign key.
CREATE UNIQUE INDEX IF NOT EXISTS uq_attendance_log_session_action
    ON attendance_logs (employee_id, attendance_date, session_index, action);
CREATE UNIQUE INDEX IF NOT EXISTS uq_attendance_log_device_request
    ON attendance_logs (device_id, punch_request_id);
CREATE INDEX IF NOT EXISTS idx_attendance_log_sequence
    ON attendance_logs (employee_id, attendance_date, punch_sequence);

SET @drop_daily_action = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME='attendance_logs'
          AND INDEX_NAME='uq_attendance_log_daily_action'
    ),
    'ALTER TABLE attendance_logs DROP INDEX uq_attendance_log_daily_action',
    'SELECT 1'
);
PREPARE ucchr_stmt FROM @drop_daily_action;
EXECUTE ucchr_stmt;
DEALLOCATE PREPARE ucchr_stmt;

-- No biometric event is allowed to manufacture an OT request. Remove old
-- pending automatic placeholders so the employee can submit the one official
-- manual request for that date. Rejected/cancelled/approved historical rows are
-- retained for audit, but application/payroll code never treats them as payable.
DELETE FROM overtime_requests
WHERE request_source='Automatic'
  AND status='Pending';

-- Attendance and dashboards expose only OT originating from an approved
-- Employee request. Clear stale projections from the retired automatic flow;
-- immutable already-generated payslips remain unchanged.
UPDATE attendance a
SET a.approved_overtime_minutes=0
WHERE a.approved_overtime_minutes>0
  AND NOT EXISTS (
      SELECT 1
      FROM overtime_requests ot
      WHERE ot.employee_id=a.employee_id
        AND ot.attendance_date=a.scan_date
        AND ot.request_source='Employee'
        AND ot.status='Approved'
        AND ot.approved_minutes>0
  );

ALTER TABLE overtime_requests
    MODIFY COLUMN request_source ENUM('Automatic','Employee','Administrator')
        NOT NULL DEFAULT 'Employee';

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-13-multi-session-attendance-v2',
    'Ordered Full-Time sessions, flexible Part-Time punches, protected legacy days, and manual-only overtime approvals'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance_logs'
       AND COLUMN_NAME='session_index') AS session_index_column,
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance_logs'
       AND INDEX_NAME='uq_attendance_log_session_action') AS session_action_index,
    (SELECT COUNT(*) FROM schema_migrations
     WHERE migration_key='2026-09-13-multi-session-attendance-v2') AS migration_marker;
