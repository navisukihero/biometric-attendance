-- Website <-> ESP32 command, heartbeat, schedule, and payroll update.
-- Run this once on an existing ucchr_system database.

USE ucchr_system;

ALTER TABLE employees
    ADD UNIQUE INDEX IF NOT EXISTS uq_employee_fingerprint_code (fingerprint_code);

ALTER TABLE device_commands
    MODIFY COLUMN command_type ENUM('ENROLL','VERIFY_ATTENDANCE') NOT NULL,
    ADD COLUMN IF NOT EXISTS event_type ENUM('IN','OUT') NULL AFTER fingerprint_slot,
    ADD COLUMN IF NOT EXISTS enrollment_version INT UNSIGNED NULL AFTER event_type;

CREATE TABLE IF NOT EXISTS fingerprint_registrations (
    employee_id INT UNSIGNED PRIMARY KEY,
    fingerprint_slot SMALLINT UNSIGNED NOT NULL,
    mapping_status ENUM('Reserved','Pending','Enrolled','Failed') NOT NULL DEFAULT 'Reserved',
    enrollment_version INT UNSIGNED NOT NULL DEFAULT 0,
    enrollment_command_uuid VARCHAR(64) NULL,
    device_id VARCHAR(80) NULL,
    enrolled_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fingerprint_registration_slot (fingerprint_slot),
    UNIQUE KEY uq_fingerprint_registration_command (enrollment_command_uuid),
    CONSTRAINT fk_fingerprint_registration_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO fingerprint_registrations
    (employee_id, fingerprint_slot, mapping_status, enrolled_at)
SELECT id,
       CAST(fingerprint_code AS UNSIGNED),
       IF(fingerprint_status='Enrolled', 'Enrolled', 'Reserved'),
       IF(fingerprint_status='Enrolled', created_at, NULL)
FROM employees
WHERE fingerprint_code REGEXP '^[0-9]+$'
  AND CAST(fingerprint_code AS UNSIGNED) BETWEEN 1 AND 127
ON DUPLICATE KEY UPDATE
    fingerprint_slot=VALUES(fingerprint_slot),
    mapping_status=IF(fingerprint_registrations.mapping_status IN ('Pending','Failed'), fingerprint_registrations.mapping_status, VALUES(mapping_status));

UPDATE fingerprint_registrations fr
JOIN (
    SELECT dc.employee_id, dc.device_id, dc.completed_at
    FROM device_commands dc
    JOIN (
        SELECT employee_id, MAX(id) AS latest_id
        FROM device_commands
        WHERE command_type='ENROLL' AND status='Done'
        GROUP BY employee_id
    ) latest ON latest.latest_id=dc.id
) completed ON completed.employee_id=fr.employee_id
SET fr.device_id=COALESCE(fr.device_id, completed.device_id),
    fr.enrolled_at=COALESCE(completed.completed_at, fr.enrolled_at)
WHERE fr.mapping_status='Enrolled';

UPDATE device_commands
SET status='Failed',
    result_message='Superseded by fingerprint mapping migration',
    completed_at=NOW()
WHERE command_type='ENROLL'
  AND status IN ('Pending','Running')
  AND enrollment_version IS NULL;

CREATE TABLE IF NOT EXISTS device_status (
    device_id VARCHAR(80) PRIMARY KEY,
    last_seen DATETIME NOT NULL,
    ip_address VARCHAR(45) NULL,
    firmware_version VARCHAR(40) NULL,
    last_message VARCHAR(255) NULL,
    KEY idx_device_last_seen (last_seen)
) ENGINE=InnoDB;

ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS expected_time_in TIME NULL AFTER time_out,
    ADD COLUMN IF NOT EXISTS expected_time_out TIME NULL AFTER expected_time_in,
    ADD COLUMN IF NOT EXISTS worked_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER expected_time_out,
    ADD COLUMN IF NOT EXISTS late_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER worked_minutes,
    ADD COLUMN IF NOT EXISTS overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER late_minutes;

ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS overtime_hours DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER days_worked,
    ADD COLUMN IF NOT EXISTS overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER overtime_hours;

-- Backfill only missing expected-time snapshots. A rerun must never replace
-- historical snapshots merely because an administrator later edited a shift.
UPDATE attendance a
JOIN work_schedules ws
  ON ws.employee_id = a.employee_id
 AND ws.day_of_week = DAYNAME(a.scan_date)
SET a.expected_time_in = COALESCE(a.expected_time_in, ws.shift_start),
    a.expected_time_out = COALESCE(a.expected_time_out, ws.shift_end)
WHERE a.expected_time_in IS NULL OR a.expected_time_out IS NULL;

-- Calculate old rows from their saved snapshot (not the employee's current
-- schedule). The dated expressions correctly handle a shift crossing midnight.
SET @ucchr_grace_minutes := COALESCE(
    (SELECT CAST(`value` AS UNSIGNED) FROM settings WHERE `key`='grace_minutes' LIMIT 1),
    15
);

UPDATE attendance a
SET a.worked_minutes = CASE
        WHEN a.time_in IS NULL OR a.time_out IS NULL THEN a.worked_minutes
        ELSE GREATEST(0, TIMESTAMPDIFF(
            MINUTE,
            TIMESTAMP(a.scan_date, a.time_in),
            CASE
                WHEN a.time_out < a.time_in
                    THEN TIMESTAMP(DATE_ADD(a.scan_date, INTERVAL 1 DAY), a.time_out)
                ELSE TIMESTAMP(a.scan_date, a.time_out)
            END
        ))
    END,
    a.late_minutes = CASE
        WHEN a.time_in IS NULL OR a.expected_time_in IS NULL THEN a.late_minutes
        ELSE GREATEST(0, TIMESTAMPDIFF(
            MINUTE,
            TIMESTAMP(a.scan_date, a.expected_time_in),
            TIMESTAMP(a.scan_date, a.time_in)
        ) - @ucchr_grace_minutes)
    END,
    a.overtime_minutes = CASE
        WHEN a.time_out IS NULL OR a.expected_time_in IS NULL OR a.expected_time_out IS NULL THEN a.overtime_minutes
        ELSE GREATEST(0, TIMESTAMPDIFF(
            MINUTE,
            CASE
                WHEN a.expected_time_out <= a.expected_time_in
                    THEN TIMESTAMP(DATE_ADD(a.scan_date, INTERVAL 1 DAY), a.expected_time_out)
                ELSE TIMESTAMP(a.scan_date, a.expected_time_out)
            END,
            CASE
                WHEN a.time_in IS NOT NULL AND a.time_out < a.time_in
                    THEN TIMESTAMP(DATE_ADD(a.scan_date, INTERVAL 1 DAY), a.time_out)
                ELSE TIMESTAMP(a.scan_date, a.time_out)
            END
        ))
    END;

SET @ucchr_grace_minutes := NULL;

INSERT INTO settings (`key`, `value`)
VALUES ('overtime_multiplier', '1.25')
ON DUPLICATE KEY UPDATE `value` = `value`;
