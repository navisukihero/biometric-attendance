-- UCC HR System: transparent payroll review, legacy monthly-schedule table
-- compatibility, schedule-based attendance classification, and source deductions.
-- Additive and rerunnable on MariaDB/MySQL installations used by XAMPP.

CREATE TABLE IF NOT EXISTS employee_monthly_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    schedule_month DATE NOT NULL,
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
    schedule_type ENUM('Work','Off') NOT NULL DEFAULT 'Off',
    shift_start TIME NULL,
    shift_end TIME NULL,
    break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    schedule_periods LONGTEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_monthly_schedule (employee_id, schedule_month, day_of_week),
    KEY idx_monthly_schedule_month (schedule_month, employee_id),
    CONSTRAINT fk_monthly_schedule_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_monthly_schedule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employee_deduction_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    deduction_date DATE NOT NULL,
    deduction_type ENUM('Cash Advance','Other') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(500) NULL,
    status ENUM('Active','Cancelled') NOT NULL DEFAULT 'Active',
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_deduction_employee_date (employee_id, deduction_date, status),
    CONSTRAINT fk_deduction_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
    CONSTRAINT fk_deduction_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE payroll_items
    ADD COLUMN IF NOT EXISTS undertime_deduction DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER undertime_minutes,
    ADD COLUMN IF NOT EXISTS cash_advance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER undertime_deduction,
    ADD COLUMN IF NOT EXISTS other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cash_advance_deduction;

-- Keep all legacy values readable while adding the new schedule-based status.
ALTER TABLE attendance MODIFY COLUMN status ENUM(
    'Present','Late','Absent','On leave',
    'PRESENT','LATE','UNDERTIME','LATE_AND_UNDERTIME','HALF_DAY','ABSENT',
    'PAID_LEAVE','UNPAID_LEAVE','REST_DAY','REGULAR_HOLIDAY',
    'SPECIAL_NON_WORKING_DAY','HOLIDAY_WORK','REST_DAY_WORK','UNSCHEDULED','INCOMPLETE'
) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'PRESENT';

INSERT INTO settings (`key`, `value`) VALUES
('undertime_deduction_enabled', '1'),
('half_day_minimum_percent', '50'),
('full_day_minimum_percent', '75')
ON DUPLICATE KEY UPDATE `value`=`value`;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-09-payroll-review-automation-v1',
    'Schedule-based day classification, explicit source deductions, transparent payroll review, and legacy schedule-table compatibility'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

SELECT
    (SELECT COUNT(*) FROM employee_monthly_schedules) AS monthly_schedule_rows,
    (SELECT COUNT(*) FROM employee_deduction_entries) AS source_deduction_rows,
    (SELECT COUNT(*) FROM payroll_items) AS payroll_items_preserved;
