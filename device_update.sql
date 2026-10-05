-- ESP32 fingerprint device update for an existing ucchr_system database.
-- Import this once in phpMyAdmin if you already imported ucchr.sql before this hardware update.

USE ucchr_system;

ALTER TABLE employees
    ADD UNIQUE INDEX IF NOT EXISTS uq_employee_fingerprint_code (fingerprint_code);

CREATE TABLE IF NOT EXISTS device_commands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    command_uuid VARCHAR(64) NOT NULL UNIQUE,
    command_type ENUM('ENROLL','VERIFY_ATTENDANCE') NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    fingerprint_slot INT UNSIGNED NOT NULL,
    event_type ENUM('IN','OUT') NULL,
    device_id VARCHAR(80) NULL,
    status ENUM('Pending','Running','Done','Failed') NOT NULL DEFAULT 'Pending',
    result_message VARCHAR(255) NULL,
    requested_by INT UNSIGNED NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    KEY idx_device_command_status (status, requested_at),
    CONSTRAINT fk_device_command_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_command_user FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_nonces (
    device_id VARCHAR(80) NOT NULL,
    nonce VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (device_id, nonce),
    KEY idx_device_nonce_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_status (
    device_id VARCHAR(80) PRIMARY KEY,
    last_seen DATETIME NOT NULL,
    ip_address VARCHAR(45) NULL,
    firmware_version VARCHAR(40) NULL,
    last_message VARCHAR(255) NULL,
    KEY idx_device_last_seen (last_seen)
) ENGINE=InnoDB;

ALTER TABLE device_commands
    MODIFY COLUMN command_type ENUM('ENROLL','VERIFY_ATTENDANCE') NOT NULL,
    ADD COLUMN IF NOT EXISTS event_type ENUM('IN','OUT') NULL AFTER fingerprint_slot,
    ADD COLUMN IF NOT EXISTS enrollment_version INT UNSIGNED NULL AFTER event_type;

INSERT INTO settings (`key`, `value`)
-- Deliberately blank on first install. Generate a unique 32-byte secret and
-- place the same value in settings and the ESP32 secrets.h before connecting.
VALUES ('device_shared_secret', '')
ON DUPLICATE KEY UPDATE `value` = `value`;

UPDATE employees
SET fingerprint_code = CAST(id AS CHAR)
WHERE fingerprint_status = 'Enrolled'
  AND (fingerprint_code IS NULL OR fingerprint_code NOT REGEXP '^[0-9]+$');

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

-- Commands created before versioned mapping cannot safely update the current
-- employee/slot association. They are retained for audit but made terminal.
UPDATE device_commands
SET status='Failed',
    result_message='Superseded by fingerprint mapping migration',
    completed_at=NOW()
WHERE command_type='ENROLL'
  AND status IN ('Pending','Running')
  AND enrollment_version IS NULL;
