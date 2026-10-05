-- Ubay Community College HR System
-- MySQL 8 / MariaDB 10.4+

CREATE DATABASE IF NOT EXISTS ucchr_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ucchr_system;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS employee_account_tokens;
DROP TABLE IF EXISTS employee_accounts;
DROP TABLE IF EXISTS device_status;
DROP TABLE IF EXISTS device_nonces;
DROP TABLE IF EXISTS device_commands;
DROP TABLE IF EXISTS fingerprint_template_slots;
DROP TABLE IF EXISTS fingerprint_registrations;
DROP TABLE IF EXISTS payroll_items;
DROP TABLE IF EXISTS payroll_runs;
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS work_schedules;
DROP TABLE IF EXISTS default_work_schedules;
DROP TABLE IF EXISTS employees;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS admin_auth_throttles;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(60) NOT NULL DEFAULT 'Administrator',
    last_login DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_users_password_hash CHECK (
        CHAR_LENGTH(password_hash) BETWEEN 20 AND 255
        AND LEFT(password_hash, 1) = '$'
    )
) ENGINE=InnoDB;

-- Persistent, privacy-preserving protection for administrator login and
-- verified password recovery. Only SHA-256 scope digests are retained; the
-- table never stores a submitted username, IP address, or password.
CREATE TABLE admin_auth_throttles (
    scope_type ENUM('Client','Account') NOT NULL,
    scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scope_type, scope_hash),
    KEY idx_admin_auth_throttle_expiry (locked_until),
    KEY idx_admin_auth_throttle_updated (updated_at)
) ENGINE=InnoDB;

CREATE TABLE departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    code VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE employees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_no VARCHAR(30) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NOT NULL,
    date_of_birth DATE NULL,
    gender VARCHAR(30) NOT NULL DEFAULT 'Not specified',
    civil_status VARCHAR(30) NOT NULL DEFAULT 'Single',
    department_id INT UNSIGNED NULL,
    position VARCHAR(120) NOT NULL,
    daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    contact_number VARCHAR(40) NULL,
    email VARCHAR(160) NULL,
    status ENUM('Active','Inactive','On leave') NOT NULL DEFAULT 'Active',
    fingerprint_status ENUM('Enrolled','Not enrolled') NOT NULL DEFAULT 'Not enrolled',
    fingerprint_code VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_fingerprint_code (fingerprint_code),
    CONSTRAINT fk_employee_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Employee self-service credentials are intentionally separate from users.
-- This prevents an employee account from ever authenticating into the admin
-- application. The unique employee number is the initial portal username.
CREATE TABLE employee_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NULL,
    account_status ENUM('Pending','Active','Disabled') NOT NULL DEFAULT 'Pending',
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login DATETIME NULL,
    password_changed_at DATETIME NULL,
    reset_requested_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_account_employee (employee_id),
    UNIQUE KEY uq_employee_account_username (username),
    KEY idx_employee_account_status (account_status),
    KEY idx_employee_account_created_by (created_by),
    CONSTRAINT fk_employee_account_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_account_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE employee_account_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_account_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('Activation','Reset') NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_account_token_hash (token_hash),
    KEY idx_employee_account_token_lookup (employee_account_id, purpose, used_at, expires_at),
    KEY idx_employee_account_token_creator (created_by),
    CONSTRAINT fk_employee_account_token_account FOREIGN KEY (employee_account_id) REFERENCES employee_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_account_token_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- One authoritative employee <-> AS608 template-slot mapping. The legacy
-- employees.fingerprint_* columns are kept as a compatibility mirror for
-- existing reports, while this row prevents stale re-enrollment commands
-- from silently remapping an employee.
CREATE TABLE fingerprint_registrations (
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

-- One employee fingerprint profile owns five physical AS608 template slots.
-- fingerprint_registrations.fingerprint_slot remains the canonical CENTER
-- slot used by the website, commands, attendance, and payroll.
CREATE TABLE fingerprint_template_slots (
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
    CONSTRAINT fk_fingerprint_template_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE default_work_schedules (
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') PRIMARY KEY,
    schedule_type ENUM('Work','Off') NOT NULL DEFAULT 'Work',
    shift_start TIME NULL,
    shift_end TIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_default_schedule_times CHECK (
        (schedule_type='Off' AND shift_start IS NULL AND shift_end IS NULL)
        OR
        (schedule_type='Work' AND shift_start IS NOT NULL AND shift_end IS NOT NULL AND shift_start<>shift_end)
    )
) ENGINE=InnoDB;

CREATE TABLE work_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    schedule_type ENUM('Work','Off') NOT NULL DEFAULT 'Work',
    shift_start TIME NULL,
    shift_end TIME NULL,
    UNIQUE KEY uq_employee_day (employee_id, day_of_week),
    CONSTRAINT fk_schedule_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT chk_employee_schedule_times CHECK (
        (schedule_type='Off' AND shift_start IS NULL AND shift_end IS NULL)
        OR
        (schedule_type='Work' AND shift_start IS NOT NULL AND shift_end IS NOT NULL AND shift_start<>shift_end)
    )
) ENGINE=InnoDB;

CREATE TABLE work_schedule_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_schedule_id INT UNSIGNED NOT NULL,
    period_order TINYINT UNSIGNED NOT NULL,
    period_start TIME NOT NULL,
    period_end TIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schedule_period_order (work_schedule_id, period_order),
    KEY idx_schedule_period_time (work_schedule_id, period_start, period_end),
    CONSTRAINT fk_schedule_period_parent FOREIGN KEY (work_schedule_id) REFERENCES work_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    scan_date DATE NOT NULL,
    time_in TIME NULL,
    time_out TIME NULL,
    expected_time_in TIME NULL,
    expected_time_out TIME NULL,
    schedule_type ENUM('Work','Off','Unscheduled') NULL,
    schedule_source VARCHAR(32) NULL,
    schedule_periods_snapshot LONGTEXT NULL,
    worked_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    late_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('Present','Late','Absent','On leave') NOT NULL DEFAULT 'Present',
    source VARCHAR(40) NOT NULL DEFAULT 'Biometric',
    notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_scan_date (employee_id, scan_date),
    KEY idx_attendance_date (scan_date),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE device_commands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    command_uuid VARCHAR(64) NOT NULL UNIQUE,
    command_type ENUM('ENROLL','VERIFY_ATTENDANCE') NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    fingerprint_slot INT UNSIGNED NOT NULL,
    event_type ENUM('IN','OUT') NULL,
    enrollment_version INT UNSIGNED NULL,
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

CREATE TABLE device_nonces (
    device_id VARCHAR(80) NOT NULL,
    nonce VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (device_id, nonce),
    KEY idx_device_nonce_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE device_status (
    device_id VARCHAR(80) PRIMARY KEY,
    last_seen DATETIME NOT NULL,
    ip_address VARCHAR(45) NULL,
    firmware_version VARCHAR(40) NULL,
    last_message VARCHAR(255) NULL,
    KEY idx_device_last_seen (last_seen)
) ENGINE=InnoDB;

CREATE TABLE payroll_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    scope_employee_id INT UNSIGNED NULL,
    processed_by INT UNSIGNED NULL,
    processed_at DATETIME NOT NULL,
    status ENUM('Draft','Released') NOT NULL DEFAULT 'Draft',
    payment_method ENUM('Cash','Bank Transfer') NOT NULL DEFAULT 'Cash',
    payment_status ENUM('Pending','Paid') NOT NULL DEFAULT 'Pending',
    payment_updated_at DATETIME NULL,
    UNIQUE KEY uq_payroll_scope_period (scope_employee_id, period_start, period_end),
    KEY idx_payroll_period (period_start, period_end),
    KEY idx_payroll_payment_status (payment_status, period_end),
    CONSTRAINT fk_payroll_scope_employee FOREIGN KEY (scope_employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payroll_user FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payroll_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payroll_run_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    days_worked DECIMAL(6,2) NOT NULL,
    overtime_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
    overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    gross_pay DECIMAL(12,2) NOT NULL,
    total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_pay DECIMAL(12,2) NOT NULL,
    UNIQUE KEY uq_payroll_employee (payroll_run_id, employee_id),
    CONSTRAINT fk_item_run FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE settings (
    `key` VARCHAR(80) PRIMARY KEY,
    `value` TEXT NOT NULL
) ENGINE=InnoDB;

INSERT INTO users (full_name,username,email,password_hash,role) VALUES
('HR Admin','admin','admin@ucchr.edu.ph','$2y$10$AojQ4wr9EmpNJoIMDB7M8O0RE2RuON5eAMAbzWCnttH6Amsnq2kTK','Administrator');

INSERT INTO departments (name,code) VALUES
('Admin Staff','ADMIN'),('Faculty','FAC'),('Finance','FIN'),('Registrar','REG'),('Maintenance','MNT');

INSERT INTO employees (employee_no,first_name,middle_name,last_name,date_of_birth,gender,civil_status,department_id,position,daily_rate,contact_number,email,status,fingerprint_status,fingerprint_code) VALUES
('EMP-0010','Jules Ivan','','Zafra','1998-01-14','Male','Single',1,'Admin Aide',650.00,'0912 345 6789','j.zafra@ucchr.edu.ph','Active','Enrolled','1'),
('EMP-0011','Patricia','','Alas','1996-05-22','Female','Single',1,'HR Officer',800.00,'0917 110 2200','p.alas@ucchr.edu.ph','Active','Enrolled','2'),
('EMP-0020','Johnrel','','Rojo','1994-08-03','Male','Single',2,'Instructor III',1150.00,'0918 221 3300','j.rojo@ucchr.edu.ph','Active','Enrolled','3'),
('EMP-0021','Rafael','','Tutor','1995-11-19','Male','Single',2,'Instructor II',1050.00,'0919 332 4400','r.tutor@ucchr.edu.ph','Active','Enrolled','4'),
('EMP-0022','Cristine Kate','','Cadoldolan','1997-04-08','Female','Single',2,'Instructor I',950.00,'0920 443 5500','c.cadoldolan@ucchr.edu.ph','Active','Enrolled','5');


INSERT INTO employee_accounts
    (employee_id, username, password_hash, account_status, must_change_password, created_by)
SELECT id, employee_no, NULL, 'Pending', 1, 1
FROM employees;

INSERT INTO fingerprint_registrations (employee_id, fingerprint_slot, mapping_status, enrolled_at)
SELECT id, CAST(fingerprint_code AS UNSIGNED),
       IF(fingerprint_status='Enrolled', 'Enrolled', 'Reserved'),
       IF(fingerprint_status='Enrolled', created_at, NULL)
FROM employees
WHERE fingerprint_code REGEXP '^[0-9]+$'
  AND CAST(fingerprint_code AS UNSIGNED) BETWEEN 1 AND 127;

INSERT INTO fingerprint_template_slots
    (employee_id, position, sensor_slot, mapping_status,
     enrollment_version, device_id, enrolled_at)
SELECT employee_id, 'CENTER', fingerprint_slot, mapping_status,
       enrollment_version, device_id, enrolled_at
FROM fingerprint_registrations;

INSERT INTO default_work_schedules (day_of_week,schedule_type,shift_start,shift_end) VALUES
('Monday','Work','08:00:00','17:00:00'),
('Tuesday','Work','08:00:00','17:00:00'),
('Wednesday','Work','08:00:00','17:00:00'),
('Thursday','Work','08:00:00','17:00:00'),
('Friday','Work','08:00:00','17:00:00'),
('Saturday','Work','08:00:00','17:00:00'),
('Sunday','Off',NULL,NULL);

INSERT INTO attendance
    (employee_id,scan_date,time_in,time_out,expected_time_in,expected_time_out,
     schedule_type,schedule_source,worked_minutes,late_minutes,overtime_minutes,status,source)
VALUES
(1,CURDATE(),'08:00:00',NULL,      '08:00:00','17:00:00','Work','Existing Snapshot',0,  0, 0,'Present','Biometric'),
(2,CURDATE(),'08:07:00','17:03:00','08:00:00','17:00:00','Work','Existing Snapshot',536,0, 3,'Present','Biometric'),
(3,CURDATE(),'08:20:00',NULL,      '08:00:00','17:00:00','Work','Existing Snapshot',0,  5, 0,'Late','Biometric'),
(4,CURDATE(),'07:55:00','17:10:00','08:00:00','17:00:00','Work','Existing Snapshot',555,0,10,'Present','Biometric'),
(5,CURDATE(),'08:11:00','17:01:00','08:00:00','17:00:00','Work','Existing Snapshot',530, 0,1,'Present','Biometric');

INSERT INTO payroll_runs (period_start,period_end,processed_by,processed_at,status)
VALUES (DATE_FORMAT(CURDATE(),'%Y-%m-01'),DATE_ADD(DATE_FORMAT(CURDATE(),'%Y-%m-01'),INTERVAL 14 DAY),1,NOW(),'Released');

INSERT INTO payroll_items (payroll_run_id,employee_id,days_worked,gross_pay,total_deductions,net_pay)
SELECT 1,id,11,daily_rate*11,0,daily_rate*11
FROM employees WHERE status='Active';

INSERT INTO activity_logs (user_id,action,created_at) VALUES
(1,'Ran payroll - current pay period',NOW()-INTERVAL 1 DAY),
(1,'Edited employee record - Jules Ivan Zafra',NOW()-INTERVAL 2 DAY),
(1,'Logged in',NOW()-INTERVAL 3 DAY);

INSERT INTO settings (`key`,`value`) VALUES
('institution_name','Ubay Community College'),('payroll_day','30'),('grace_minutes','15'),('overtime_multiplier','1.25'),('timezone','Asia/Manila'),('device_shared_secret','');
