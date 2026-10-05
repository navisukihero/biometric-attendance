-- UCC HR integrated attendance and payroll compatibility migration
-- Target: existing MySQL 8 / MariaDB 10.4+ installation
--
-- IMPORTANT:
--   1. Select the existing UCC HR database before importing this file.
--   2. This script never drops an application table and never changes employee,
--      fingerprint, attendance, payroll, or account primary keys.
--   3. The existing `attendance` table remains the processed daily-attendance
--      table so all current website and ESP32 API routes stay compatible.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Employee compensation is entered by HR. Position remains descriptive only.
ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS employment_type ENUM('Full-Time','Part-Time') NULL AFTER `position`,
    ADD COLUMN IF NOT EXISTS pay_type ENUM('Monthly','Daily','Hourly') NOT NULL DEFAULT 'Daily' AFTER `employment_type`,
    ADD COLUMN IF NOT EXISTS basic_rate DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `pay_type`,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

UPDATE employees
SET basic_rate = daily_rate
WHERE basic_rate = 0 AND daily_rate > 0;

CREATE TABLE IF NOT EXISTS employee_compensation_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    employment_type ENUM('Full-Time','Part-Time') NULL,
    pay_type ENUM('Monthly','Daily','Hourly') NOT NULL,
    basic_rate DECIMAL(12,2) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    change_reason VARCHAR(500) NULL,
    changed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_compensation_employee_effective (employee_id, effective_from),
    KEY idx_compensation_employee_period (employee_id, effective_from, effective_to),
    CONSTRAINT fk_compensation_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    CONSTRAINT fk_compensation_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO employee_compensation_history
    (employee_id, employment_type, pay_type, basic_rate, effective_from, change_reason)
SELECT e.id, e.employment_type, e.pay_type,
       CASE WHEN e.basic_rate > 0 THEN e.basic_rate ELSE e.daily_rate END,
       DATE(e.created_at),
       'Legacy compensation migrated from employees.daily_rate'
FROM employees e
WHERE NOT EXISTS (
    SELECT 1 FROM employee_compensation_history ech WHERE ech.employee_id=e.id
);

-- Break minutes are a schedule property and are snapshotted into attendance.
ALTER TABLE default_work_schedules
    ADD COLUMN IF NOT EXISTS break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `shift_end`;

ALTER TABLE work_schedules
    ADD COLUMN IF NOT EXISTS break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `shift_end`;

-- Part-Time employees may split one workday into several non-overlapping
-- periods. The existing work_schedules row remains the weekly day/envelope so
-- all deployed routes retain their original key and compatibility contract.
CREATE TABLE IF NOT EXISTS work_schedule_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_schedule_id INT UNSIGNED NOT NULL,
    period_order TINYINT UNSIGNED NOT NULL,
    period_start TIME NOT NULL,
    period_end TIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schedule_period_order (work_schedule_id, period_order),
    KEY idx_schedule_period_time (work_schedule_id, period_start, period_end),
    CONSTRAINT fk_schedule_period_parent
        FOREIGN KEY (work_schedule_id) REFERENCES work_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS holidays (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_name VARCHAR(160) NOT NULL,
    holiday_date DATE NOT NULL,
    holiday_type ENUM('Regular Holiday','Special Non-Working Day') NOT NULL,
    description VARCHAR(500) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_holiday_date (holiday_date),
    KEY idx_holiday_status_date (status, holiday_date),
    CONSTRAINT fk_holiday_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leave_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    leave_type ENUM('Paid Leave','Unpaid Leave') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    approved_by INT UNSIGNED NULL,
    date_approved DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_leave_employee_dates (employee_id, start_date, end_date),
    KEY idx_leave_status_dates (status, start_date, end_date),
    CONSTRAINT fk_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_leave_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Extend the existing processed daily-attendance table instead of renaming it.
ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS schedule_periods_snapshot LONGTEXT NULL AFTER `schedule_source`,
    ADD COLUMN IF NOT EXISTS break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `schedule_periods_snapshot`,
    ADD COLUMN IF NOT EXISTS employment_type_snapshot VARCHAR(20) NULL AFTER `break_minutes`,
    ADD COLUMN IF NOT EXISTS legacy_single_pair TINYINT(1) NOT NULL DEFAULT 0 AFTER `employment_type_snapshot`,
    ADD COLUMN IF NOT EXISTS regular_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER `worked_minutes`,
    ADD COLUMN IF NOT EXISTS undertime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER `late_minutes`,
    ADD COLUMN IF NOT EXISTS potential_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER `undertime_minutes`,
    ADD COLUMN IF NOT EXISTS approved_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER `potential_overtime_minutes`,
    ADD COLUMN IF NOT EXISTS day_classification VARCHAR(60) NOT NULL DEFAULT 'Normal Work Day' AFTER `approved_overtime_minutes`,
    ADD COLUMN IF NOT EXISTS holiday_id BIGINT UNSIGNED NULL AFTER `day_classification`,
    ADD COLUMN IF NOT EXISTS leave_request_id BIGINT UNSIGNED NULL AFTER `holiday_id`,
    ADD COLUMN IF NOT EXISTS processed_at DATETIME NULL AFTER `notes`,
    ADD COLUMN IF NOT EXISTS processing_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `processed_at`,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `processing_version`;

-- Legacy status values remain valid so existing history is not rewritten.
-- New processing uses the complete uppercase status vocabulary.
ALTER TABLE attendance MODIFY COLUMN status ENUM(
    'Present','Late','Absent','On leave',
    'PRESENT','LATE','UNDERTIME','LATE_AND_UNDERTIME','HALF_DAY','ABSENT',
    'PAID_LEAVE','UNPAID_LEAVE','REST_DAY','REGULAR_HOLIDAY',
    'SPECIAL_NON_WORKING_DAY','HOLIDAY_WORK','REST_DAY_WORK','UNSCHEDULED','INCOMPLETE'
) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'PRESENT';

CREATE INDEX IF NOT EXISTS idx_attendance_status_date ON attendance (`status`, `scan_date`);
CREATE INDEX IF NOT EXISTS idx_attendance_holiday ON attendance (`holiday_id`);
CREATE INDEX IF NOT EXISTS idx_attendance_leave ON attendance (`leave_request_id`);

-- Append-only accepted biometric transactions. Each independently scheduled
-- session owns one IN and one OUT; a stable punch request id makes retries safe.
CREATE TABLE IF NOT EXISTS attendance_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    fingerprint_id SMALLINT UNSIGNED NULL,
    attendance_date DATE NOT NULL,
    action ENUM('TIME_IN','TIME_OUT') NOT NULL,
    session_index TINYINT UNSIGNED NOT NULL DEFAULT 1,
    punch_sequence TINYINT UNSIGNED NOT NULL DEFAULT 1,
    scanned_at DATETIME(6) NOT NULL,
    device_id VARCHAR(80) NOT NULL,
    command_uuid VARCHAR(64) NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'ESP32 Fingerprint',
    processed_attendance_id BIGINT UNSIGNED NULL,
    punch_request_id VARCHAR(96) NULL,
    event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_log_event (event_key),
    UNIQUE KEY uq_attendance_log_session_action (employee_id, attendance_date, session_index, action),
    UNIQUE KEY uq_attendance_log_device_request (device_id, punch_request_id),
    KEY idx_attendance_log_sequence (employee_id, attendance_date, punch_sequence),
    KEY idx_attendance_log_scanned (scanned_at),
    KEY idx_attendance_log_fingerprint (fingerprint_id),
    KEY idx_attendance_log_command (command_uuid),
    KEY idx_attendance_log_processed (processed_attendance_id),
    CONSTRAINT fk_attendance_log_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_log_processed FOREIGN KEY (processed_attendance_id) REFERENCES attendance(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS overtime_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED NULL,
    attendance_date DATE NOT NULL,
    potential_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    requested_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    approved_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    request_source ENUM('Automatic','Employee','Administrator') NOT NULL DEFAULT 'Employee',
    reason VARCHAR(1000) NULL,
    approved_by INT UNSIGNED NULL,
    approval_date DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_overtime_employee_date (employee_id, attendance_date),
    KEY idx_overtime_status_date (status, attendance_date),
    KEY idx_overtime_attendance (attendance_id),
    CONSTRAINT fk_overtime_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_overtime_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
    CONSTRAINT fk_overtime_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Existing daily attendance becomes the initial processed history. Existing
-- overtime is potential only; historical released payroll rows are preserved.
UPDATE attendance
SET regular_minutes = GREATEST(worked_minutes - overtime_minutes, 0),
    potential_overtime_minutes = overtime_minutes,
    day_classification = CASE
        WHEN schedule_type='Off' THEN 'Rest Day'
        WHEN schedule_type='Unscheduled' THEN 'Unscheduled'
        ELSE 'Normal Work Day'
    END,
    processed_at = COALESCE(processed_at, created_at)
WHERE processed_at IS NULL;

INSERT IGNORE INTO attendance_logs
    (employee_id, fingerprint_id, attendance_date, action, session_index, punch_sequence, scanned_at,
     device_id, command_uuid, source, processed_attendance_id, event_key)
SELECT a.employee_id,
       NULL,
       a.scan_date,
       'TIME_IN',
       1,
       1,
       TIMESTAMP(a.scan_date, a.time_in),
       'legacy-import',
       NULL,
       'Legacy attendance backfill',
       a.id,
       SHA2(CONCAT('legacy|', a.id, '|TIME_IN'), 256)
FROM attendance a
WHERE a.time_in IS NOT NULL
;

INSERT IGNORE INTO attendance_logs
    (employee_id, fingerprint_id, attendance_date, action, session_index, punch_sequence, scanned_at,
     device_id, command_uuid, source, processed_attendance_id, event_key)
SELECT a.employee_id,
       NULL,
       a.scan_date,
       'TIME_OUT',
       1,
       2,
       CASE
           WHEN a.time_out < a.time_in
               THEN TIMESTAMP(DATE_ADD(a.scan_date, INTERVAL 1 DAY), a.time_out)
           ELSE TIMESTAMP(a.scan_date, a.time_out)
       END,
       'legacy-import',
       NULL,
       'Legacy attendance backfill',
       a.id,
       SHA2(CONCAT('legacy|', a.id, '|TIME_OUT'), 256)
FROM attendance a
WHERE a.time_in IS NOT NULL
  AND a.time_out IS NOT NULL
;

-- A completed record that predates schedule sessions used one IN/OUT envelope
-- for the whole day. Grandfather it so a later versioned reprocessing cannot
-- silently turn historical/paid Present days into Half-Day or Absent.
UPDATE attendance a
SET a.legacy_single_pair=1
WHERE a.processing_version<5
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

-- Payroll runs gain a review/finalization workflow without deleting Released,
-- which remains accepted as a historical legacy status.
ALTER TABLE payroll_runs MODIFY COLUMN status ENUM(
    'Draft','For Review','Approved','Finalized','Paid','Released'
) NOT NULL DEFAULT 'Draft';
ALTER TABLE payroll_runs
    ADD COLUMN IF NOT EXISTS reviewed_by INT UNSIGNED NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER `reviewed_by`,
    ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED NULL AFTER `reviewed_at`,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER `approved_by`,
    ADD COLUMN IF NOT EXISTS finalized_by INT UNSIGNED NULL AFTER `approved_at`,
    ADD COLUMN IF NOT EXISTS finalized_at DATETIME NULL AFTER `finalized_by`,
    ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL AFTER `finalized_at`,
    ADD COLUMN IF NOT EXISTS policy_snapshot LONGTEXT NULL AFTER `paid_at`,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `policy_snapshot`;

CREATE INDEX IF NOT EXISTS idx_payroll_period ON payroll_runs (`period_start`, `period_end`);
CREATE INDEX IF NOT EXISTS idx_payroll_status_period ON payroll_runs (`status`, `period_end`);

ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS employment_type VARCHAR(20) NULL AFTER `employee_id`,
    ADD COLUMN IF NOT EXISTS pay_type VARCHAR(20) NOT NULL DEFAULT 'Daily' AFTER `employment_type`,
    ADD COLUMN IF NOT EXISTS basic_rate DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `pay_type`,
    ADD COLUMN IF NOT EXISTS regular_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER `days_worked`,
    ADD COLUMN IF NOT EXISTS regular_hours DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `regular_minutes`,
    ADD COLUMN IF NOT EXISTS hourly_equivalent_rate DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER `regular_hours`,
    ADD COLUMN IF NOT EXISTS regular_pay DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `hourly_equivalent_rate`,
    ADD COLUMN IF NOT EXISTS late_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER `regular_pay`,
    ADD COLUMN IF NOT EXISTS late_deduction DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `late_minutes`,
    ADD COLUMN IF NOT EXISTS undertime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER `late_deduction`,
    ADD COLUMN IF NOT EXISTS absence_days DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER `undertime_minutes`,
    ADD COLUMN IF NOT EXISTS unpaid_leave_days DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER `absence_days`,
    ADD COLUMN IF NOT EXISTS approved_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER `unpaid_leave_days`,
    ADD COLUMN IF NOT EXISTS ot_rate DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER `overtime_hours`,
    ADD COLUMN IF NOT EXISTS holiday_hours DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `overtime_pay`,
    ADD COLUMN IF NOT EXISTS holiday_pay DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `holiday_hours`,
    ADD COLUMN IF NOT EXISTS rest_day_hours DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `holiday_pay`,
    ADD COLUMN IF NOT EXISTS rest_day_pay DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `rest_day_hours`,
    ADD COLUMN IF NOT EXISTS other_earnings DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `rest_day_pay`,
    ADD COLUMN IF NOT EXISTS total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `gross_pay`,
    ADD COLUMN IF NOT EXISTS calculation_snapshot LONGTEXT NULL AFTER `net_pay`;

UPDATE payroll_items pi
JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
JOIN employees e ON e.id=pi.employee_id
SET pi.employment_type=e.employment_type,
    pi.pay_type=e.pay_type,
    pi.basic_rate=CASE WHEN e.basic_rate > 0 THEN e.basic_rate ELSE e.daily_rate END,
    pi.regular_pay=GREATEST(pi.gross_pay-pi.overtime_pay, 0)
-- Only untouched rows from the pre-integration payroll schema need this
-- compatibility backfill. Integrated runs/items carry immutable snapshots,
-- while basic_rate=0 is the default added above to every legacy item. Once a
-- valid legacy rate is backfilled, rerunning this migration is a no-op even
-- when zero regular pay or zero deductions are legitimate for that item.
WHERE pr.policy_snapshot IS NULL
  AND pi.calculation_snapshot IS NULL
  AND pi.basic_rate=0;

-- Late is the only payroll deduction. Reconcile legacy totals before removing
-- obsolete broad/statutory fields so old rows remain internally explainable.
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

-- Administrator recovery now verifies the current password directly. Retired
-- reset links redirect safely to that flow, so their old token store is unused.
DROP TABLE IF EXISTS password_reset_tokens;

CREATE TABLE IF NOT EXISTS payroll_item_attendance (
    payroll_item_id BIGINT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payroll_item_id, attendance_id),
    UNIQUE KEY uq_payroll_consumed_attendance (attendance_id),
    CONSTRAINT fk_payroll_link_item FOREIGN KEY (payroll_item_id) REFERENCES payroll_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_payroll_link_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Improve the existing activity log into a structured audit log while keeping
-- its original id/user/action contract used by the dashboard.
ALTER TABLE activity_logs
    ADD COLUMN IF NOT EXISTS module VARCHAR(80) NOT NULL DEFAULT 'System' AFTER `action`,
    ADD COLUMN IF NOT EXISTS record_id VARCHAR(80) NULL AFTER `module`,
    ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER `record_id`,
    ADD COLUMN IF NOT EXISTS old_values LONGTEXT NULL AFTER `description`,
    ADD COLUMN IF NOT EXISTS new_values LONGTEXT NULL AFTER `old_values`,
    ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) NULL AFTER `new_values`;

CREATE INDEX IF NOT EXISTS idx_activity_module_date ON activity_logs (`module`, `created_at`);
UPDATE activity_logs SET description=action WHERE description IS NULL;

-- Policy values are centralized in settings. HR must confirm them before
-- finalizing payroll.
INSERT INTO settings (`key`,`value`) VALUES
('regular_hours_per_day','8'),
('working_day_basis','22'),
('break_duration','60'),
('late_deduction_enabled','1'),
('overtime_enabled','1'),
('overtime_requires_approval','1'),
('employee_overtime_requests_enabled','1'),
('overtime_multiplier','1.25'),
('regular_holiday_worked_multiplier','1.00'),
('regular_holiday_overtime_multiplier','1.00'),
('special_day_worked_multiplier','1.00'),
('special_day_overtime_multiplier','1.00'),
('rest_day_multiplier','1.00'),
('regular_holiday_rest_day_multiplier','1.00'),
('special_day_rest_day_multiplier','1.00'),
('payroll_frequency','Semi-monthly'),
('rounding_rule','nearest_cent'),
('currency','PHP')
ON DUPLICATE KEY UPDATE `value`=`value`;

UPDATE default_work_schedules
SET break_minutes=CAST((SELECT `value` FROM settings WHERE `key`='break_duration' LIMIT 1) AS UNSIGNED)
WHERE schedule_type='Work' AND break_minutes=0;

UPDATE work_schedules ws
JOIN employees e ON e.id=ws.employee_id
SET ws.break_minutes=CAST((SELECT `value` FROM settings WHERE `key`='break_duration' LIMIT 1) AS UNSIGNED)
WHERE e.employment_type='Full-Time'
  AND ws.schedule_type='Work'
  AND ws.break_minutes=0;

-- Do not manufacture lunch periods for Part-Time staff. Their required punch
-- count comes only from the explicit periods HR assigns in Work Schedule; a
-- legacy continuous Part-Time shift remains one IN/OUT pair.

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-05-integrated-attendance-payroll-v1',
    'Additive raw attendance, approvals, configurable payroll, and audit foundation'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

-- Migration verification summary.
SELECT
    (SELECT COUNT(*) FROM employees) AS employees_preserved,
    (SELECT COUNT(*) FROM attendance) AS daily_attendance_preserved,
    (SELECT COUNT(*) FROM attendance_logs) AS raw_logs_available,
    (SELECT COUNT(*) FROM payroll_runs) AS payroll_runs_preserved,
    (SELECT COUNT(*) FROM employee_accounts) AS employee_accounts_preserved;
