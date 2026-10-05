-- Stable employee <-> AS608 template mapping for an existing installation.
-- Safe to run more than once. Existing employees and attendance are preserved.

USE ucchr_system;

ALTER TABLE employees
    ADD UNIQUE INDEX IF NOT EXISTS uq_employee_fingerprint_code (fingerprint_code);

ALTER TABLE device_commands
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

-- Bind existing successful enrollments to the terminal that actually stored
-- them. Installations with no command history remain unbound until the next
-- successful re-enrollment.
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
