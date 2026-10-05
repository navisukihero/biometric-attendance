-- Five physical AS608 templates per employee fingerprint profile.
-- Run this once against the database selected by config/database.php.
-- Existing employee records, canonical fingerprint slots, and attendance stay intact.

CREATE TABLE IF NOT EXISTS fingerprint_template_slots (
    employee_id INT UNSIGNED NOT NULL,
    position ENUM('CENTER','LEFT','RIGHT','UPPER','LOWER') NOT NULL,
    sensor_slot SMALLINT UNSIGNED NOT NULL,
    mapping_status ENUM('Reserved','Pending','Enrolled','Failed') NOT NULL DEFAULT 'Reserved',
    enrollment_version INT UNSIGNED NOT NULL DEFAULT 0,
    device_id VARCHAR(80) NULL,
    enrolled_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id, position),
    UNIQUE KEY uq_fingerprint_template_sensor_slot (sensor_slot),
    KEY idx_fingerprint_template_employee_status (employee_id, mapping_status),
    CONSTRAINT fk_fingerprint_template_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Compatibility note: older MySQL/MariaDB/phpMyAdmin builds do not accept
-- CHECK constraints consistently. The 1..127 AS608 range is enforced by the
-- PHP command layer and ESP32 firmware instead.

-- Preserve every existing single-template enrollment as the CENTER template.   
-- The other four physical slots are reserved automatically when Sync/Enroll
-- or Re-enroll is queued for that employee.
INSERT INTO fingerprint_template_slots
    (employee_id, position, sensor_slot, mapping_status,
     enrollment_version, device_id, enrolled_at)
SELECT fr.employee_id, 'CENTER', fr.fingerprint_slot, fr.mapping_status,
       fr.enrollment_version, fr.device_id, fr.enrolled_at
FROM fingerprint_registrations fr
ON DUPLICATE KEY UPDATE
    sensor_slot=VALUES(sensor_slot),
    mapping_status=VALUES(mapping_status),
    enrollment_version=VALUES(enrollment_version),
    device_id=VALUES(device_id),
    enrolled_at=VALUES(enrolled_at);
