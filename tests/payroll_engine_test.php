<?php

declare(strict_types=1);

function payroll_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function payroll_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'FAIL: ' . $message . ' (expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true) . ')'
        );
    }
}

function payroll_test_close(float $expected, float $actual, string $message, float $tolerance = 0.011): void
{
    if (abs($expected - $actual) > $tolerance) {
        throw new RuntimeException(
            "FAIL: {$message} (expected {$expected}, got {$actual})"
        );
    }
}

function payroll_test_throws(callable $callback, string $messageFragment, string $message): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        payroll_test_assert(
            stripos($error->getMessage(), $messageFragment) !== false,
            $message . ' (unexpected message: ' . $error->getMessage() . ')'
        );
        return;
    }
    throw new RuntimeException('FAIL: ' . $message . ' (no exception was thrown)');
}

function payroll_test_server_connection(string $host, string $port, string $user, string $password): PDO
{
    return new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function payroll_test_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/** Execute the exact compatibility backfill shipped by the migration. */
function payroll_test_migration_backfill_sql(): string
{
    $path = dirname(__DIR__) . '/database/integrated_attendance_payroll_update.sql';
    $migration = file_get_contents($path);
    if ($migration === false) {
        throw new RuntimeException('FAIL: Could not read payroll migration: ' . $path);
    }

    $start = strpos($migration, 'UPDATE payroll_items pi');
    $end = $start === false ? false : strpos($migration, ';', $start);
    if ($start === false || $end === false) {
        throw new RuntimeException('FAIL: Could not locate payroll compatibility backfill in migration.');
    }

    return substr($migration, $start, ($end - $start) + 1);
}

/**
 * Remove the disposable schema without asking MariaDB to recursively tear
 * down a populated InnoDB database in one DROP DATABASE operation.
 *
 * @return null|string Null on success, otherwise a cleanup error message.
 */
function payroll_test_cleanup_database(
    ?PDO &$server,
    string $host,
    string $port,
    string $user,
    string $password,
    string $database
): ?string {
    // Never let a typo or changed environment turn test cleanup into a broad
    // destructive operation. Test schemas have a random, process-bound name.
    if (preg_match('/^ucchr_payroll_test_[1-9]\d*_[a-f0-9]{8}$/D', $database) !== 1) {
        return 'Refusing to clean an unexpected database name: ' . $database;
    }

    try {
        try {
            if (!$server instanceof PDO || $server->query('SELECT 1')->fetchColumn() === false) {
                $server = null;
            }
        } catch (Throwable) {
            $server = null;
        }
        if (!$server instanceof PDO) {
            $server = payroll_test_server_connection($host, $port, $user, $password);
        }

        $schemaExists = $server->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'
        );
        $schemaExists->execute([$database]);
        if ((int) $schemaExists->fetchColumn() === 0) {
            return null;
        }

        $objectsStmt = $server->prepare(
            'SELECT TABLE_NAME, TABLE_TYPE
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=?
             ORDER BY CASE WHEN TABLE_TYPE="VIEW" THEN 0 ELSE 1 END, TABLE_NAME'
        );
        $objectsStmt->execute([$database]);
        $objects = $objectsStmt->fetchAll();
        $quotedDatabase = payroll_test_quote_identifier($database);

        $server->exec('SET SESSION FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($objects as $object) {
                $objectName = (string) ($object['TABLE_NAME'] ?? '');
                $objectType = strtoupper((string) ($object['TABLE_TYPE'] ?? ''));
                if ($objectName === '') {
                    continue;
                }
                $qualifiedName = $quotedDatabase . '.' . payroll_test_quote_identifier($objectName);
                if ($objectType === 'VIEW') {
                    $server->exec('DROP VIEW IF EXISTS ' . $qualifiedName);
                } else {
                    $server->exec('DROP TABLE IF EXISTS ' . $qualifiedName);
                }
            }
        } finally {
            $server->exec('SET SESSION FOREIGN_KEY_CHECKS=1');
        }

        $remainingStmt = $server->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?'
        );
        $remainingStmt->execute([$database]);
        $remaining = (int) $remainingStmt->fetchColumn();
        if ($remaining !== 0) {
            return 'Disposable payroll database still contains ' . $remaining . ' object(s); it was not dropped.';
        }

        // At this point the schema is verified empty, so DROP DATABASE only
        // removes the disposable namespace and cannot cascade through tables.
        $server->exec('DROP DATABASE ' . $quotedDatabase);
        return null;
    } catch (Throwable $error) {
        return $error->getMessage();
    }
}

function payroll_test_create_schema(PDO $pdo): void
{
    $statements = [
        'CREATE TABLE users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            username VARCHAR(80) NOT NULL UNIQUE,
            email VARCHAR(160) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(60) NOT NULL DEFAULT "Administrator",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
        'CREATE TABLE departments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            code VARCHAR(20) NOT NULL UNIQUE
        ) ENGINE=InnoDB',
        'CREATE TABLE employees (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_no VARCHAR(30) NOT NULL UNIQUE,
            first_name VARCHAR(80) NOT NULL,
            middle_name VARCHAR(80) NULL,
            last_name VARCHAR(80) NOT NULL,
            department_id INT UNSIGNED NULL,
            position VARCHAR(120) NOT NULL,
            daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM("Active","Inactive","On leave") NOT NULL DEFAULT "Active",
            employment_type ENUM("Full-Time","Part-Time") NULL,
            pay_type ENUM("Monthly","Daily","Hourly") NOT NULL DEFAULT "Daily",
            basic_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_test_employee_department FOREIGN KEY (department_id) REFERENCES departments(id)
        ) ENGINE=InnoDB',
        'CREATE TABLE settings (`key` VARCHAR(80) PRIMARY KEY, `value` TEXT NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE default_work_schedules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            day_of_week VARCHAR(12) NOT NULL UNIQUE,
            schedule_type ENUM("Work","Off") NOT NULL,
            shift_start TIME NULL,
            shift_end TIME NULL,
            break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB',
        'CREATE TABLE work_schedules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            day_of_week VARCHAR(12) NOT NULL,
            schedule_type ENUM("Work","Off") NOT NULL,
            shift_start TIME NULL,
            shift_end TIME NULL,
            break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_test_employee_day (employee_id, day_of_week),
            CONSTRAINT fk_test_schedule_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB',
        'CREATE TABLE work_schedule_periods (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            work_schedule_id BIGINT UNSIGNED NOT NULL,
            period_order TINYINT UNSIGNED NOT NULL,
            period_start TIME NOT NULL,
            period_end TIME NOT NULL,
            UNIQUE KEY uq_test_period_order (work_schedule_id, period_order),
            CONSTRAINT fk_test_period_schedule FOREIGN KEY (work_schedule_id) REFERENCES work_schedules(id) ON DELETE CASCADE
         ) ENGINE=InnoDB',
        'CREATE TABLE employee_monthly_schedules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            schedule_month DATE NOT NULL,
            day_of_week VARCHAR(12) NOT NULL,
            schedule_type ENUM("Work","Off") NOT NULL,
            shift_start TIME NULL,
            shift_end TIME NULL,
            break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            schedule_periods LONGTEXT NULL,
            created_by INT UNSIGNED NULL,
            UNIQUE KEY uq_test_month_schedule (employee_id,schedule_month,day_of_week),
            CONSTRAINT fk_test_month_employee FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB',
        'CREATE TABLE holidays (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            holiday_name VARCHAR(160) NOT NULL,
            holiday_date DATE NOT NULL UNIQUE,
            holiday_type ENUM("Regular Holiday","Special Non-Working Day") NOT NULL,
            status ENUM("Active","Inactive") NOT NULL DEFAULT "Active"
        ) ENGINE=InnoDB',
        'CREATE TABLE leave_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            leave_type ENUM("Paid Leave","Unpaid Leave") NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            reason VARCHAR(1000) NOT NULL,
            status ENUM("Pending","Approved","Rejected","Cancelled") NOT NULL DEFAULT "Pending",
            CONSTRAINT fk_test_leave_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB',
        'CREATE TABLE attendance (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            scan_date DATE NOT NULL,
            time_in TIME NULL,
            time_out TIME NULL,
            expected_time_in TIME NULL,
            expected_time_out TIME NULL,
            schedule_type ENUM("Work","Off","Unscheduled") NULL,
            schedule_source VARCHAR(32) NULL,
            schedule_periods_snapshot LONGTEXT NULL,
            break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            employment_type_snapshot VARCHAR(20) NULL,
            legacy_single_pair TINYINT(1) NOT NULL DEFAULT 0,
            worked_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            regular_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            late_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            undertime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            potential_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            approved_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            day_classification VARCHAR(60) NOT NULL DEFAULT "Normal Work Day",
            holiday_id BIGINT UNSIGNED NULL,
            leave_request_id BIGINT UNSIGNED NULL,
            status VARCHAR(40) NOT NULL DEFAULT "PRESENT",
            source VARCHAR(40) NOT NULL DEFAULT "Biometric",
            notes VARCHAR(255) NULL,
            processed_at DATETIME NULL,
            processing_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_test_employee_scan_date (employee_id, scan_date),
            CONSTRAINT fk_test_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB',
        'CREATE TABLE attendance_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            fingerprint_id INT UNSIGNED NULL,
            attendance_date DATE NOT NULL,
            action ENUM("TIME_IN","TIME_OUT") NOT NULL,
            session_index TINYINT UNSIGNED NOT NULL DEFAULT 1,
            punch_sequence TINYINT UNSIGNED NOT NULL DEFAULT 1,
            scanned_at DATETIME(6) NOT NULL,
            device_id VARCHAR(80) NOT NULL,
            command_uuid VARCHAR(64) NULL,
            source VARCHAR(40) NOT NULL,
            processed_attendance_id BIGINT UNSIGNED NULL,
            punch_request_id VARCHAR(96) NULL,
            event_key CHAR(64) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_test_session_action (employee_id, attendance_date, session_index, action),
            UNIQUE KEY uq_test_device_request (device_id, punch_request_id),
            KEY idx_test_punch_sequence (employee_id, attendance_date, punch_sequence),
            CONSTRAINT fk_test_log_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
            CONSTRAINT fk_test_log_attendance FOREIGN KEY (processed_attendance_id) REFERENCES attendance(id) ON DELETE SET NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE overtime_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            attendance_id BIGINT UNSIGNED NULL,
            attendance_date DATE NOT NULL,
            potential_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            requested_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            approved_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM("Pending","Approved","Rejected","Cancelled") NOT NULL DEFAULT "Pending",
            request_source ENUM("Automatic","Employee","Administrator") NOT NULL DEFAULT "Employee",
            reason VARCHAR(1000) NULL,
            approved_by INT UNSIGNED NULL,
            approval_date DATETIME NULL,
            decision_note VARCHAR(500) NULL,
            UNIQUE KEY uq_test_overtime_employee_date (employee_id, attendance_date),
            CONSTRAINT fk_test_overtime_employee FOREIGN KEY (employee_id) REFERENCES employees(id),
            CONSTRAINT fk_test_overtime_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE employee_compensation_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            employment_type ENUM("Full-Time","Part-Time") NULL,
            pay_type ENUM("Monthly","Daily","Hourly") NOT NULL,
            basic_rate DECIMAL(12,2) NOT NULL,
            effective_from DATE NOT NULL,
            effective_to DATE NULL,
            change_reason VARCHAR(500) NULL,
            changed_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_test_comp_employee_effective (employee_id, effective_from),
            CONSTRAINT fk_test_comp_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
         ) ENGINE=InnoDB',
        'CREATE TABLE employee_deduction_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT UNSIGNED NOT NULL,
            deduction_date DATE NOT NULL,
            deduction_type ENUM("Cash Advance","Other") NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            description VARCHAR(500) NULL,
            status ENUM("Active","Cancelled") NOT NULL DEFAULT "Active",
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_test_deduction_employee FOREIGN KEY(employee_id) REFERENCES employees(id)
        ) ENGINE=InnoDB',
        'CREATE TABLE payroll_runs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            scope_employee_id INT UNSIGNED NULL,
            processed_by INT UNSIGNED NULL,
            processed_at DATETIME NOT NULL,
            status ENUM("Draft","For Review","Approved","Finalized","Paid","Released") NOT NULL DEFAULT "Draft",
            payment_method ENUM("Cash","Bank Transfer") NOT NULL DEFAULT "Cash",
            payment_status ENUM("Pending","Paid") NOT NULL DEFAULT "Pending",
            payment_updated_at DATETIME NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            finalized_by INT UNSIGNED NULL,
            finalized_at DATETIME NULL,
            paid_at DATETIME NULL,
            policy_snapshot LONGTEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_test_payroll_scope_period (scope_employee_id, period_start, period_end),
            KEY idx_test_payroll_period (period_start, period_end)
        ) ENGINE=InnoDB',
        'CREATE TABLE payroll_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payroll_run_id INT UNSIGNED NOT NULL,
            employee_id INT UNSIGNED NOT NULL,
            employment_type VARCHAR(20) NULL,
            pay_type VARCHAR(20) NOT NULL DEFAULT "Daily",
            basic_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
            days_worked DECIMAL(6,2) NOT NULL DEFAULT 0,
            regular_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            regular_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
            hourly_equivalent_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
            monthly_basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            regular_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            late_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            late_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            undertime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            undertime_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            half_day_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            absence_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            cash_advance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
            absence_days DECIMAL(6,2) NOT NULL DEFAULT 0,
            unpaid_leave_days DECIMAL(6,2) NOT NULL DEFAULT 0,
            approved_overtime_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            overtime_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
            ot_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
            overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            holiday_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
            holiday_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            rest_day_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
            rest_day_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_earnings DECIMAL(12,2) NOT NULL DEFAULT 0,
            gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            calculation_snapshot LONGTEXT NULL,
            UNIQUE KEY uq_test_payroll_employee (payroll_run_id, employee_id),
            CONSTRAINT fk_test_item_run FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
            CONSTRAINT fk_test_item_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
        ) ENGINE=InnoDB',
        'CREATE TABLE payroll_item_attendance (
            payroll_item_id BIGINT UNSIGNED NOT NULL,
            attendance_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (payroll_item_id, attendance_id),
            UNIQUE KEY uq_test_consumed_attendance (attendance_id),
            CONSTRAINT fk_test_link_item FOREIGN KEY (payroll_item_id) REFERENCES payroll_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_test_link_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id)
        ) ENGINE=InnoDB',
        'CREATE TABLE activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(255) NOT NULL,
            module VARCHAR(80) NOT NULL DEFAULT "System",
            record_id VARCHAR(80) NULL,
            description TEXT NULL,
            old_values LONGTEXT NULL,
            new_values LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB',
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}

function payroll_test_seed_policy(PDO $pdo): void
{
    $settings = [
        'grace_minutes' => '15',
        'break_duration' => '60',
        'regular_hours_per_day' => '8',
        'working_day_basis' => '30',
        'late_deduction_enabled' => '1',
        'undertime_deduction_enabled' => '1',
        'half_day_minimum_percent' => '50',
        'full_day_minimum_percent' => '75',
        'overtime_enabled' => '1',
        'overtime_requires_approval' => '1',
        'overtime_multiplier' => '1.25',
        'regular_holiday_worked_multiplier' => '2.00',
        'regular_holiday_overtime_multiplier' => '2.60',
        'special_day_worked_multiplier' => '1.30',
        'special_day_overtime_multiplier' => '1.69',
        'rest_day_multiplier' => '1.30',
        'regular_holiday_rest_day_multiplier' => '2.60',
        'special_day_rest_day_multiplier' => '1.50',
        'rounding_rule' => 'nearest_cent',
        'currency' => 'PHP',
    ];
    $stmt = $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?)');
    foreach ($settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }
    $schedule = $pdo->prepare(
        'INSERT INTO default_work_schedules
            (day_of_week,schedule_type,shift_start,shift_end,break_minutes)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $day) {
        $schedule->execute([$day, 'Work', '08:00:00', '17:00:00', 60]);
    }
    foreach (['Saturday', 'Sunday'] as $day) {
        $schedule->execute([$day, 'Off', null, null, 0]);
    }
}

function payroll_test_employee(PDO $pdo, string $number, string $payType, float $basicRate): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (employee_no,first_name,middle_name,last_name,department_id,position,daily_rate,
             status,employment_type,pay_type,basic_rate,created_at)
         VALUES (?, ?, NULL, ?, 1, "Test Role", ?, "Active", "Full-Time", ?, ?, "2025-01-01 00:00:00")'
    );
    $dailyRate = match ($payType) {
        'Monthly' => $basicRate / 22,
        'Hourly' => $basicRate * 8,
        default => $basicRate,
    };
    $stmt->execute([$number, 'Codex', $number, $dailyRate, $payType, $basicRate]);
    $employeeId = (int) $pdo->lastInsertId();
    if ($basicRate > 0) {
        $pdo->prepare(
            'INSERT INTO employee_compensation_history
                (employee_id,employment_type,pay_type,basic_rate,effective_from,change_reason)
             VALUES (? ,"Full-Time", ?, ?, "2025-01-01", "Test-approved compensation")'
        )->execute([$employeeId, $payType, $basicRate]);
    }
    return $employeeId;
}

function payroll_test_raw_day(PDO $pdo, int $employeeId, string $date, string $in, string $out): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO attendance_logs
            (employee_id,fingerprint_id,attendance_date,action,session_index,punch_sequence,
             scanned_at,device_id,source,punch_request_id,event_key)
         VALUES (?,NULL,?,?,?,?,?,"test-device","Test Fixture",?,?)'
    );
    $punches = [
        ['TIME_IN', $in, 1, 1],
        ['TIME_OUT', '12:00:00', 1, 2],
        ['TIME_IN', '13:00:00', 2, 3],
        ['TIME_OUT', $out, 2, 4],
    ];
    foreach ($punches as [$action, $time, $sessionIndex, $punchSequence]) {
        $punchRequestId = "payroll-test-{$employeeId}-{$date}-{$punchSequence}";
        $stmt->execute([
            $employeeId,
            $date,
            $action,
            $sessionIndex,
            $punchSequence,
            $date . ' ' . $time,
            $punchRequestId,
            hash('sha256', "payroll-test|{$employeeId}|{$date}|{$sessionIndex}|{$action}"),
        ]);
    }
}

function payroll_test_calc_row(int $id, string $date, string $status, array $overrides = []): array
{
    return array_replace([
        'id' => $id,
        'scan_date' => $date,
        'status' => $status,
        'day_classification' => 'Normal Work Day',
        'schedule_type' => 'Work',
        'expected_time_in' => '08:00:00',
        'expected_time_out' => '17:00:00',
        'break_minutes' => 60,
        'time_in' => '08:00:00',
        'time_out' => '17:00:00',
        'regular_minutes' => 480,
        'worked_minutes' => 480,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'potential_overtime_minutes' => 0,
        'approved_overtime_minutes' => 0,
        'ot_request_status' => null,
        'ot_request_approved_minutes' => 0,
        'ot_request_source' => null,
    ], $overrides);
}

$host = getenv('UCCHR_DB_HOST') ?: '127.0.0.1';
$port = getenv('UCCHR_DB_PORT') ?: '3306';
$user = getenv('UCCHR_DB_USER') ?: 'root';
$password = getenv('UCCHR_DB_PASS') ?: '';
$database = 'ucchr_payroll_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
$quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
$server = payroll_test_server_connection($host, $port, $user, $password);
$pdo = null;
$cleanupFailure = null;

try {
    $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    payroll_test_create_schema($pdo);
    payroll_test_seed_policy($pdo);
    $pdo->exec('INSERT INTO users (full_name,username,email,password_hash,role) VALUES
        ("Payroll Test Admin","payroll_test_admin","payroll-test@example.invalid","$2y$10$testtesttesttesttesttesttesttesttesttesttesttesttest","Administrator")');
    $pdo->exec('INSERT INTO departments (name,code) VALUES ("Test Department","TST")');

    require_once dirname(__DIR__) . '/includes/payroll_engine.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    $policy = payroll_load_policy($pdo);

    payroll_test_throws(
        fn() => payroll_effective_compensation(
            [
                'id' => 501,
                'employee_no' => 'MESSAGE-001',
                'employment_type' => 'Full-Time',
                'pay_type' => 'Daily',
                'basic_rate' => 650,
            ],
            [[
                'id' => 1,
                'employment_type' => null,
                'pay_type' => 'Daily',
                'basic_rate' => 650,
                'effective_from' => '2026-09-01',
                'effective_to' => null,
            ]],
            '2026-09-01'
        ),
        'Employment Type is missing or invalid',
        'Payroll must identify the exact invalid field in the dated compensation record'
    );

    payroll_test_same(
        'REST_DAY',
        payroll_normalize_status([
            'status' => 'INCOMPLETE',
            'schedule_type' => 'Off',
            'day_classification' => 'Rest Day',
        ]),
        'Historical unfinished Rest Days must not block payroll'
    );
    payroll_test_same(
        'INCOMPLETE',
        payroll_normalize_status([
            'status' => 'INCOMPLETE',
            'schedule_type' => 'Work',
            'day_classification' => 'Normal Work Day',
        ]),
        'A genuinely unfinished Work day must remain blocked'
    );

    // Pure rate conversion for all three supported compensation units.
    payroll_test_same(
        ['daily_rate' => 1000.0, 'hourly_rate' => 125.0],
        payroll_derive_rates('Monthly', 22000, 8, 22),
        'Monthly deduction equivalents must use scheduled workdays and scheduled daily hours'
    );
    payroll_test_same(
        ['daily_rate' => 800.0, 'hourly_rate' => 100.0],
        payroll_derive_rates('Daily', 800, 8, 22),
        'Daily basic must derive the hourly equivalent'
    );
    payroll_test_same(
        ['daily_rate' => 1200.0, 'hourly_rate' => 150.0],
        payroll_derive_rates('Hourly', 150, 8, 22),
        'Hourly basic must derive the daily equivalent'
    );
    payroll_test_close(
        22000,
        payroll_expected_monthly_amount('Monthly', 22000, 13, 3120),
        'Monthly expected salary must equal the configured monthly rate'
    );
    payroll_test_close(
        10400,
        payroll_expected_monthly_amount('Daily', 800, 13, 3120),
        'Daily expected salary must use scheduled workdays in the period'
    );
    payroll_test_close(
        13000,
        payroll_expected_monthly_amount('Hourly', 250, 13, 3120),
        'Hourly expected salary must use the actual scheduled hours in the month'
    );
    payroll_test_close(
        5400,
        payroll_expected_monthly_amount('Hourly', 300, 3, 1080),
        'An 18-hour weekly schedule at 300 per hour must produce 5,400 for that exact week'
    );
    payroll_test_close(
        900,
        payroll_expected_monthly_amount('Daily', 300, 3, 1080),
        'A three-day weekly schedule at 300 per day must produce 900 for that exact week'
    );

    $employeeBase = [
        'id' => 9001,
        'employee_no' => 'PURE',
        'first_name' => 'Pure',
        'middle_name' => '',
        'last_name' => 'Fixture',
        'department_name' => 'Tests',
        'position' => 'Fixture',
        'employee_status' => 'Inactive',
        'employment_type' => 'Full-Time',
        'daily_rate' => 0,
    ];

    // Database-backed schedule estimate: M/W/F at six hours per day is an
    // exact 18-hour week, not a weekly value multiplied by 30 calendar days.
    $scheduleEmployeeId = payroll_test_employee($pdo, 'SCHED-18', 'Hourly', 300);
    $pdo->prepare('UPDATE employees SET employment_type="Part-Time" WHERE id=?')->execute([$scheduleEmployeeId]);
    $scheduleInsert = $pdo->prepare(
        'INSERT INTO work_schedules
            (employee_id,day_of_week,schedule_type,shift_start,shift_end,break_minutes)
         VALUES (?,?,?,?,?,?)'
    );
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $dayName) {
        $isWorkday = in_array($dayName, ['Monday', 'Wednesday', 'Friday'], true);
        $scheduleInsert->execute([
            $scheduleEmployeeId,
            $dayName,
            $isWorkday ? 'Work' : 'Off',
            $isWorkday ? '08:00:00' : null,
            $isWorkday ? '15:00:00' : null,
            $isWorkday ? 60 : 0,
        ]);
    }
    $periodEstimate = payroll_expected_period_estimate(
        $pdo,
        $scheduleEmployeeId,
        'Hourly',
        300,
        new DateTimeImmutable('2026-01-05'),
        new DateTimeImmutable('2026-01-11')
    );
    payroll_test_same(3, $periodEstimate['scheduled_days'], 'Schedule resolver must find the three assigned workdays in the exact week');
    payroll_test_same(1080, $periodEstimate['scheduled_minutes'], 'Schedule resolver must total the assigned 18 weekly hours');
    payroll_test_close(5400, (float) $periodEstimate['amount'], 'Schedule resolver must calculate the exact weekly Hourly expectation');
    $pdo->prepare('UPDATE employees SET status="Inactive" WHERE id=?')->execute([$scheduleEmployeeId]);

    // Hourly basic follows the assigned 16 hours; unpaid time is itemized
    // once after gross, while verified hours remain available for audit.
    $hourly = payroll_calculate_employee(
        array_replace($employeeBase, ['pay_type' => 'Hourly', 'basic_rate' => 100]),
        [
            payroll_test_calc_row(1, '2026-01-05', 'LATE_AND_UNDERTIME', [
                'time_in' => '08:30:00',
                'time_out' => '16:00:00',
                'regular_minutes' => 390,
                'worked_minutes' => 390,
                'late_minutes' => 15,
                'undertime_minutes' => 60,
            ]),
            payroll_test_calc_row(2, '2026-01-06', 'ABSENT', [
                'time_in' => null,
                'time_out' => null,
                'regular_minutes' => 0,
                'worked_minutes' => 0,
            ]),
        ],
        [],
        $policy
    );
    payroll_test_close(1600, (float) $hourly['monthly_basic_salary'], 'Hourly basic salary must equal 16 scheduled hours times the 100 hourly rate');
    payroll_test_close(1600, (float) $hourly['regular_pay'], 'Stored regular pay must retain scheduled Hourly basic salary');
    payroll_test_close(925, (float) $hourly['total_deductions'], 'Hourly deductions must preserve the processed late grace');
    payroll_test_close(800, (float) $hourly['absence_deduction'], 'The Absent workday must be itemized under absence');
    payroll_test_close(25, (float) $hourly['late_deduction'], 'Late deduction uses the 15-minute attendance metric');
    payroll_test_close(100, (float) $hourly['undertime_deduction'], 'Undertime cannot exceed the remaining unpaid-minute budget');
    payroll_test_close(675, (float) $hourly['net_pay'], 'Hourly net must reconcile scheduled basic minus post-grace late, undertime, and absence');
    payroll_test_same(15, $hourly['late_minutes'], 'Hourly item must still preserve late-minute metrics');
    payroll_test_same(60, $hourly['undertime_minutes'], 'Hourly item must still preserve undertime metrics');
    payroll_test_close(1, (float) $hourly['absence_days'], 'Hourly item must still preserve absence metrics');

    $hourlyGrace = payroll_calculate_employee(
        array_replace($employeeBase, ['pay_type' => 'Hourly', 'basic_rate' => 100]),
        [payroll_test_calc_row(67, '2026-01-05', 'PRESENT', [
            'time_in' => '08:10:00', 'regular_minutes' => 470, 'worked_minutes' => 470,
            'late_minutes' => 0,
        ])],
        [],
        $policy
    );
    payroll_test_close(800, (float) $hourlyGrace['monthly_basic_salary'], 'Within-grace Hourly workday retains scheduled Basic Pay');
    payroll_test_close(0, (float) $hourlyGrace['total_deductions'], 'Within-grace raw overlap must not be mislabeled an absence');

    $hourlyHalfDay = payroll_calculate_employee(
        array_replace($employeeBase, ['pay_type' => 'Hourly', 'basic_rate' => 100]),
        [payroll_test_calc_row(68, '2026-01-05', 'HALF_DAY', [
            'regular_minutes' => 300, 'worked_minutes' => 300,
        ])],
        [],
        $policy
    );
    payroll_test_close(800, (float) $hourlyHalfDay['monthly_basic_salary'], 'Hourly Half-Day basic still uses the eight scheduled hours');
    payroll_test_close(400, (float) $hourlyHalfDay['absence_deduction'], 'Hourly Half-Day cannot receive more than half the scheduled pay');
    payroll_test_close(400, (float) $hourlyHalfDay['net_pay'], 'Hourly Half-Day net is one-half of the scheduled basic');

    $splitPeriods = json_encode([
        ['period_start' => '09:00:00', 'period_end' => '12:00:00'],
        ['period_start' => '13:00:00', 'period_end' => '14:00:00'],
    ], JSON_THROW_ON_ERROR);
    $partTimeSplit = payroll_calculate_employee(
        array_merge($employeeBase, [
            'employment_type' => 'Part-Time',
            'pay_type' => 'Hourly',
            'basic_rate' => 100,
        ]),
        [payroll_test_calc_row(69, '2026-01-05', 'PRESENT', [
            'expected_time_in' => '09:00:00',
            'expected_time_out' => '14:00:00',
            'schedule_periods_snapshot' => $splitPeriods,
            'break_minutes' => 60,
            'worked_minutes' => 240,
            'regular_minutes' => 240,
        ])],
        [],
        $policy
    );
    payroll_test_close(400, (float) $partTimeSplit['regular_pay'], 'Part-Time Hourly pay must equal four eligible hours times the hourly rate');
    payroll_test_close(4, (float) $partTimeSplit['regular_hours'], 'Part-Time split schedule must snapshot four regular hours');

    // Daily: attendance-derived Late and Undertime plus dated Cash Advance
    // are all visible and deducted exactly once.
    $daily = payroll_calculate_employee(
        $employeeBase + ['pay_type' => 'Daily', 'basic_rate' => 800],
        [
            payroll_test_calc_row(3, '2026-01-05', 'LATE_AND_UNDERTIME', [
                'late_minutes' => 60,
                'undertime_minutes' => 60,
            ]),
            payroll_test_calc_row(4, '2026-01-06', 'ABSENT', ['time_in' => null, 'time_out' => null]),
            payroll_test_calc_row(5, '2026-01-07', 'UNPAID_LEAVE', ['time_in' => null, 'time_out' => null]),
        ],
        [],
        $policy,
        [
            ['id' => 1, 'deduction_date' => '2026-01-05', 'deduction_type' => 'Cash Advance', 'amount' => 50, 'description' => 'Test advance'],
        ]
    );
    payroll_test_close(2400, (float) $daily['monthly_basic_salary'], 'Daily salary must use the three scheduled workdays in this payroll period');
    payroll_test_close(2400, (float) $daily['regular_pay'], 'Regular-pay compatibility field must store schedule-based Daily salary');
    payroll_test_close(100, (float) $daily['late_deduction'], 'Daily late deduction must be applied once');
    payroll_test_close(100, (float) $daily['undertime_deduction'], 'Daily undertime deduction must use the same frozen hourly equivalent');
    payroll_test_close(50, (float) $daily['cash_advance_deduction'], 'Cash Advance source must be visible');
    payroll_test_close(0, (float) $daily['other_deductions'], 'Current payroll must not invent an Other deduction');
    payroll_test_close(1600, (float) $daily['absence_deduction'], 'One Absent and one Unpaid Leave day must deduct two frozen Daily rates');
    payroll_test_close(1850, (float) $daily['total_deductions'], 'Every approved attendance and Cash Advance deduction must be totaled once');
    payroll_test_close(550, (float) $daily['net_pay'], 'Daily net pay must subtract absent and unpaid-leave day equivalents from scheduled base pay');
    $dailyComponentTotal = (float) $daily['late_deduction']
        + (float) $daily['undertime_deduction']
        + (float) $daily['half_day_deduction']
        + (float) $daily['absence_deduction']
        + (float) $daily['cash_advance_deduction']
        + (float) $daily['other_deductions'];
    payroll_test_close($dailyComponentTotal, (float) $daily['total_deductions'], 'Total Deductions must reconcile the frozen components');
    payroll_test_close(
        (float) $daily['gross_pay'] - $dailyComponentTotal,
        (float) $daily['net_pay'],
        'Net Pay must equal Gross Pay minus its frozen deduction components'
    );
    $dailySnapshot = json_decode((string) $daily['calculation_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    payroll_test_same(
        'gross_pay_minus_late_minus_undertime_minus_absence_unpaid_time_minus_cash_advance_equals_net_pay',
        (string) ($dailySnapshot['net_pay_calculation']['formula'] ?? ''),
        'Calculation snapshot must identify the exact Net Pay equation'
    );

    payroll_test_throws(
        fn() => payroll_calculate_employee(
            $employeeBase + ['pay_type' => 'Daily', 'basic_rate' => 800],
            [payroll_test_calc_row(71, '2026-01-08', 'PRESENT')],
            [],
            $policy,
            [[
                'id' => 71,
                'deduction_date' => '2026-01-08',
                'deduction_type' => 'Other',
                'amount' => 25,
                'description' => 'Legacy deduction must be reviewed',
            ]]
        ),
        'active unsupported Other deduction',
        'New payroll must reject an active legacy Other deduction'
    );

    payroll_test_throws(
        fn() => payroll_calculate_employee(
            $employeeBase + ['pay_type' => 'Daily', 'basic_rate' => 800],
            [payroll_test_calc_row(70, '2026-01-08', 'PRESENT')],
            [],
            $policy,
            [[
                'id' => 70,
                'deduction_date' => '2026-01-08',
                'deduction_type' => 'Cash Advance',
                'amount' => 900,
                'description' => 'Must not silently create a negative Net Pay',
            ]]
        ),
        'exceed Gross Pay',
        'Payroll must reject deductions above Gross Pay instead of silently flooring Net Pay to zero'
    );

    $halfDay = payroll_calculate_employee(
        $employeeBase + ['pay_type' => 'Daily', 'basic_rate' => 800],
        [payroll_test_calc_row(73, '2026-01-08', 'HALF_DAY', [
            'time_out' => '12:00:00',
            'regular_minutes' => 240,
            'worked_minutes' => 240,
            'late_minutes' => 0,
            'undertime_minutes' => 240,
        ])],
        [],
        $policy
    );
    payroll_test_close(0.5, (float) $halfDay['days_worked'], 'A Half-Day must snapshot exactly one-half attended day');
    payroll_test_close(0, (float) $halfDay['undertime_deduction'], 'A dedicated Half-Day component must prevent duplicate undertime deduction');
    payroll_test_close(0, (float) $halfDay['half_day_deduction'], 'New payroll must not use a separate Half-Day deduction category');
    payroll_test_close(400, (float) $halfDay['absence_deduction'], 'A Daily Half-Day must count its unpaid portion under Absence / unpaid time');
    payroll_test_close(400, (float) $halfDay['net_pay'], 'A Daily Half-Day must compensate only the eligible half-day amount for that scheduled day');

    // Flexible example: three six-hour scheduled days equal 18 scheduled hours
    // for the exact selected week. Payroll must not multiply that week by 30.
    $eighteenHourRows = [];
    foreach (['2026-01-05', '2026-01-07', '2026-01-09'] as $index => $workDate) {
        $eighteenHourRows[] = payroll_test_calc_row(72 + $index, $workDate, 'PRESENT', [
            'expected_time_in' => '08:00:00',
            'expected_time_out' => '15:00:00',
            'time_in' => '08:00:00',
            'time_out' => '15:00:00',
            'break_minutes' => 60,
            'worked_minutes' => 360,
            'regular_minutes' => 360,
        ]);
    }
    $eighteenHourHourly = payroll_calculate_employee(
        array_replace($employeeBase, ['employment_type' => 'Part-Time', 'pay_type' => 'Hourly', 'basic_rate' => 300]),
        $eighteenHourRows,
        [],
        $policy
    );
    payroll_test_same(3, $eighteenHourHourly['scheduled_days'], 'The exact week must contain three scheduled workdays');
    payroll_test_same(1080, $eighteenHourHourly['scheduled_minutes'], 'The exact week must contain 18 scheduled hours');
    payroll_test_close(5400, (float) $eighteenHourHourly['regular_pay'], 'Hourly payroll must pay 18 eligible hours at 300 per hour');

    $eighteenHourDaily = payroll_calculate_employee(
        array_replace($employeeBase, ['employment_type' => 'Part-Time', 'pay_type' => 'Daily', 'basic_rate' => 300]),
        $eighteenHourRows,
        [],
        $policy
    );
    payroll_test_close(900, (float) $eighteenHourDaily['regular_pay'], 'Daily payroll must pay three scheduled days at 300 per day');

    // The current Employee Records rate can differ from the effective rate
    // for an earlier unpaid period. Five validated sessions total 34 hours.
    $thirtyFourHourRows = [];
    foreach ([
        '2026-09-16' => 8,
        '2026-09-18' => 6,
        '2026-09-21' => 6,
        '2026-09-23' => 8,
        '2026-09-25' => 6,
    ] as $workDate => $hours) {
        $thirtyFourHourRows[] = payroll_test_calc_row(300 + count($thirtyFourHourRows), $workDate, 'PRESENT', [
            'expected_time_in' => '09:00:00',
            'expected_time_out' => $hours === 8 ? '17:00:00' : '15:00:00',
            'time_in' => '09:00:00',
            'time_out' => $hours === 8 ? '17:00:00' : '15:00:00',
            'break_minutes' => 0,
            'worked_minutes' => $hours * 60,
            'regular_minutes' => $hours * 60,
        ]);
    }
    $hourlyHistory = [[
        'id' => 101,
        'employment_type' => 'Part-Time',
        'pay_type' => 'Hourly',
        'basic_rate' => 300,
        'effective_from' => '2026-09-16',
        'effective_to' => '2026-09-27',
    ]];
    $hourlyEmployee = array_replace($employeeBase, [
        'employment_type' => 'Part-Time',
        'pay_type' => 'Hourly',
        'basic_rate' => 300,
        '_compensation_history_required' => true,
    ]);
    $thirtyFourHourPay = payroll_calculate_employee($hourlyEmployee, $thirtyFourHourRows, $hourlyHistory, $policy);
    payroll_test_same('Part-Time', $thirtyFourHourPay['employment_type'], 'Hourly calculation must use the Part-Time engine');
    payroll_test_close(300, (float) $thirtyFourHourPay['basic_rate'], 'The dated ₱300 rate must agree with the current Employee Records rate');
    payroll_test_close(34, (float) $thirtyFourHourPay['regular_hours'], 'Five completed sessions must total 34 eligible hours');
    payroll_test_close(10200, (float) $thirtyFourHourPay['monthly_basic_salary'], '34 scheduled hours × ₱300/hour must produce ₱10,200 basic pay');
    $noCompletedPunches = array_map(
        static fn(array $row): array => array_replace($row, [
            'status' => 'ABSENT', 'time_in' => null, 'time_out' => null,
            'worked_minutes' => 0, 'regular_minutes' => 0,
        ]),
        $thirtyFourHourRows
    );
    $zeroWorkedPay = payroll_calculate_employee($hourlyEmployee, $noCompletedPunches, $hourlyHistory, $policy);
    payroll_test_close(34, (float) $zeroWorkedPay['scheduled_hours'], 'Scheduled hours remain visible when no work is verified');
    payroll_test_close(10200, (float) $zeroWorkedPay['monthly_basic_salary'], 'Scheduled hours establish the gross Part-Time Hourly basic pay');
    payroll_test_close(10200, (float) $zeroWorkedPay['absence_deduction'], 'Unverified hours must be deducted when all scheduled dates are absent');
    payroll_test_close(0, (float) $zeroWorkedPay['net_pay'], 'An entirely absent period must not result in positive net pay');
    payroll_test_same(
        '34.00 scheduled hours × ₱300.00/hour = ₱10,200.00',
        ucchr_payroll_basic_salary_formula($zeroWorkedPay),
        'The displayed Hourly formula must reconcile exactly with scheduled Basic Pay'
    );

    $twoRates = payroll_calculate_employee(
        $hourlyEmployee,
        array_slice($thirtyFourHourRows, 0, 2),
        [
            ['id' => 301, 'employment_type' => 'Part-Time', 'pay_type' => 'Hourly', 'basic_rate' => 250,
                'effective_from' => '2026-09-16', 'effective_to' => '2026-09-17'],
            ['id' => 302, 'employment_type' => 'Part-Time', 'pay_type' => 'Hourly', 'basic_rate' => 300,
                'effective_from' => '2026-09-18', 'effective_to' => null],
        ],
        $policy
    );
    payroll_test_close(3800, (float) $twoRates['monthly_basic_salary'], 'A period with a rate change must use each workday’s approved rate');
    payroll_test_same(
        '8.00 scheduled hours × ₱250.00/hour + 6.00 scheduled hours × ₱300.00/hour = ₱3,800.00',
        ucchr_payroll_basic_salary_formula($twoRates),
        'A mixed-rate formula must show each effective rate rather than implying one current rate'
    );

    // Monthly compensation is prorated to the calendar days in this period;
    // the scheduled workday basis still determines unpaid-day equivalents.
    $monthlyRows = [];
    $monthlyStart = new DateTimeImmutable('2026-01-05');
    for ($index = 0; $index < 22; $index++) {
        $status = $index === 0 ? 'ABSENT' : ($index === 1 ? 'UNPAID_LEAVE' : 'PRESENT');
        $overrides = in_array($status, ['ABSENT', 'UNPAID_LEAVE'], true)
            ? ['time_in' => null, 'time_out' => null, 'regular_minutes' => 0, 'worked_minutes' => 0]
            : [];
        $monthlyRows[] = payroll_test_calc_row(
            100 + $index,
            $monthlyStart->modify('+' . $index . ' days')->format('Y-m-d'),
            $status,
            $overrides
        );
    }
    $monthly = payroll_calculate_employee(
        $employeeBase + ['pay_type' => 'Monthly', 'basic_rate' => 22000],
        $monthlyRows,
        [],
        $policy
    );
    payroll_test_close(round(22000 * 22 / 31, 2), (float) $monthly['regular_pay'], 'Monthly basic must be prorated to 22 January calendar days');
    payroll_test_close(2000, (float) $monthly['absence_deduction'], 'Monthly absence and unpaid leave must use the monthly salary divided by 22 scheduled workdays');
    payroll_test_close(2000, (float) $monthly['total_deductions'], 'Monthly unpaid-day deductions must be totaled once');
    payroll_test_close(round(22000 * 22 / 31, 2) - 2000, (float) $monthly['net_pay'], 'Monthly net pay must subtract schedule-based absent and unpaid-leave equivalents');

    $partTimeDaily = payroll_calculate_employee(
        array_merge($employeeBase, ['employment_type' => 'Part-Time', 'pay_type' => 'Daily', 'basic_rate' => 800]),
        [payroll_test_calc_row(70, '2026-01-05', 'PRESENT')],
        [],
        $policy
    );
    payroll_test_close(
        800,
        (float) $partTimeDaily['regular_pay'],
        'Part-Time Daily pay must use the scheduled workdays in the selected period'
    );
    $partTimeMonthly = payroll_calculate_employee(
        array_merge($employeeBase, ['employment_type' => 'Part-Time', 'pay_type' => 'Monthly', 'basic_rate' => 22000]),
        [payroll_test_calc_row(71, '2026-01-05', 'PRESENT')],
        [],
        $policy
    );
    payroll_test_close(
        round(22000 / 31, 2),
        (float) $partTimeMonthly['regular_pay'],
        'Part-Time Monthly pay must prorate the configured monthly rate for the selected day'
    );

    $otPendingRow = payroll_test_calc_row(8, '2026-01-05', 'PRESENT', [
        'worked_minutes' => 540,
        'regular_minutes' => 480,
        'potential_overtime_minutes' => 60,
        'approved_overtime_minutes' => 60,
        'ot_request_status' => 'Pending',
        'ot_request_approved_minutes' => 60,
    ]);
    $pendingOt = payroll_calculate_employee(
        $employeeBase + ['pay_type' => 'Daily', 'basic_rate' => 800],
        [$otPendingRow],
        [],
        $policy
    );
    payroll_test_close(0, (float) $pendingOt['overtime_pay'], 'Pending overtime must never be paid');
    $otPendingRow['ot_request_status'] = 'Approved';
    $otPendingRow['ot_request_source'] = 'Employee';
    $approvedOt = payroll_calculate_employee(
        $employeeBase + ['pay_type' => 'Daily', 'basic_rate' => 800],
        [$otPendingRow],
        [],
        $policy
    );
    payroll_test_close(125, (float) $approvedOt['overtime_pay'], 'Approved one-hour overtime must use the configured 1.25 multiplier');
    $otPendingRow['ot_request_source'] = 'Automatic';
    $automaticOt = payroll_calculate_employee(
        $employeeBase + ['pay_type' => 'Daily', 'basic_rate' => 800],
        [$otPendingRow],
        [],
        $policy
    );
    payroll_test_close(0, (float) $automaticOt['overtime_pay'], 'Automatically sourced overtime must never be paid');

    // A rate effective in the middle of a period must be applied to its own
    // dates, never copied backwards from the last employee display value.
    $changedRate = payroll_calculate_employee(
        $employeeBase + ['pay_type' => 'Daily', 'basic_rate' => 650],
        [
            payroll_test_calc_row(800, '2026-01-05', 'PRESENT'),
            payroll_test_calc_row(801, '2026-01-06', 'PRESENT'),
        ],
        [
            ['id' => 1, 'employment_type' => 'Full-Time', 'pay_type' => 'Daily', 'basic_rate' => 600, 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-05'],
            ['id' => 2, 'employment_type' => 'Full-Time', 'pay_type' => 'Daily', 'basic_rate' => 650, 'effective_from' => '2026-01-06', 'effective_to' => null],
        ],
        $policy
    );
    payroll_test_close(1250, (float) $changedRate['regular_pay'], 'Full-Time Daily base must use each date’s effective rate');

    $changedMonthlyRate = payroll_calculate_employee(
        $employeeBase + ['pay_type' => 'Monthly', 'basic_rate' => 62000],
        [
            payroll_test_calc_row(806, '2026-01-05', 'PRESENT'),
            payroll_test_calc_row(807, '2026-01-06', 'PRESENT'),
        ],
        [
            ['id' => 3, 'employment_type' => 'Full-Time', 'pay_type' => 'Monthly', 'basic_rate' => 31000, 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-05'],
            ['id' => 4, 'employment_type' => 'Full-Time', 'pay_type' => 'Monthly', 'basic_rate' => 62000, 'effective_from' => '2026-01-06', 'effective_to' => null],
        ],
        $policy,
        [],
        ['2026-01' => 22]
    );
    payroll_test_close(3000, (float) $changedMonthlyRate['regular_pay'], 'Monthly rate changes must prorate each calendar date using its effective rate');
    $monthlyRateSnapshot = json_decode((string) $changedMonthlyRate['calculation_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    payroll_test_same('2026-01-06', $monthlyRateSnapshot['representative_compensation']['effective_from'], 'Frozen snapshot must retain the approved rate effective date');

    $partTimeAttendance = [
        payroll_test_calc_row(802, '2026-01-05', 'PRESENT'),
        payroll_test_calc_row(803, '2026-01-06', 'HALF_DAY', [
            'time_out' => '12:00:00', 'regular_minutes' => 240,
            'worked_minutes' => 240, 'undertime_minutes' => 240,
        ]),
        payroll_test_calc_row(804, '2026-01-07', 'ABSENT', [
            'time_in' => null, 'time_out' => null,
            'regular_minutes' => 0, 'worked_minutes' => 0,
        ]),
    ];
    $partTimeDailyPay = payroll_calculate_employee(
        array_merge($employeeBase, ['employment_type' => 'Part-Time', 'pay_type' => 'Daily', 'basic_rate' => 300]),
        $partTimeAttendance,
        [],
        $policy
    );
    payroll_test_close(900, (float) $partTimeDailyPay['regular_pay'], 'Part-Time Daily basic must cover all three scheduled workdays');
    payroll_test_close(0, (float) $partTimeDailyPay['half_day_deduction'], 'Part-Time half-day must not be deducted twice');
    payroll_test_close(450, (float) $partTimeDailyPay['absence_deduction'], 'Half-Day and Absent Part-Time time must be itemized after scheduled basic pay');
    payroll_test_close(450, (float) $partTimeDailyPay['net_pay'], 'Part-Time Daily net must preserve the 1.5 payable-day outcome');

    payroll_test_throws(
        fn() => payroll_calculate_employee(
            $employeeBase + ['_compensation_history_required' => true, 'pay_type' => 'Daily', 'basic_rate' => 650],
            [payroll_test_calc_row(805, '2026-01-05', 'PRESENT')],
            [],
            $policy
        ),
        'no HR-approved compensation effective',
        'Production payroll must reject a missing dated compensation record'
    );

    // Integrated generation with real attendance processing, approved OT,
    // and historical compensation for an active employee.
    $employeeId = payroll_test_employee($pdo, 'INT-001', 'Hourly', 150);
    $stmt = $pdo->prepare(
        'INSERT INTO employee_compensation_history
            (employee_id,employment_type,pay_type,basic_rate,effective_from,change_reason)
         VALUES (?,"Full-Time","Daily",800,"2026-01-01","Integration history")'
    );
    $stmt->execute([$employeeId]);
    payroll_test_raw_day($pdo, $employeeId, '2026-01-05', '08:20:00', '18:00:00');
    $processed = attendance_process_period($pdo, '2026-01-05', '2026-01-05', new DateTimeImmutable('2026-02-01'), false);
    payroll_test_same(1, (int) $processed['processed'], 'Fixture attendance must process before approval');
    $stmt = $pdo->prepare(
        'INSERT INTO overtime_requests
            (employee_id,attendance_id,attendance_date,potential_minutes,requested_minutes,
             approved_minutes,status,request_source,reason,approved_by,approval_date)
         SELECT employee_id,id,scan_date,potential_overtime_minutes,60,
                60,"Approved","Employee","Integration request",1,NOW()
         FROM attendance
         WHERE employee_id=? AND scan_date="2026-01-05"'
    );
    $stmt->execute([$employeeId]);
    $runId = payroll_generate_run($pdo, '2026-01-05', '2026-01-05', 1, 'Synthetic integration run');
    $run = $pdo->query('SELECT * FROM payroll_runs WHERE id=' . $runId)->fetch();
    payroll_test_same('Draft', $run['status'], 'New integrated payroll must remain Draft');
    $itemStmt = $pdo->prepare('SELECT * FROM payroll_items WHERE payroll_run_id=? AND employee_id=?');
    $itemStmt->execute([$runId, $employeeId]);
    $item = $itemStmt->fetch();
    payroll_test_assert((bool) $item, 'Active employee with historical compensation must remain in payroll');
    payroll_test_same('Daily', $item['pay_type'], 'Effective history must override the employee current pay type');
    payroll_test_close(800, (float) $item['basic_rate'], 'Effective historical basic rate must be snapshotted');
    payroll_test_same(5, (int) $item['late_minutes'], 'Attendance processor grace must flow into payroll');
    payroll_test_close(8.33, (float) $item['late_deduction'], 'Five payable late minutes must be deducted once');
    payroll_test_same(60, (int) $item['approved_overtime_minutes'], 'Approved OT minutes must be snapshotted');
    payroll_test_close(125, (float) $item['overtime_pay'], 'Approved historical-rate OT must use settings');
    $calculationSnapshot = json_decode((string) $item['calculation_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    payroll_test_same('Codex INT-001', $calculationSnapshot['employee']['name'], 'Employee name must be snapshotted');
    payroll_test_same('Test Department', $calculationSnapshot['employee']['department'], 'Department must be snapshotted');
    $policySnapshot = json_decode((string) $run['policy_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    payroll_test_same('Synthetic integration run', $policySnapshot['notes'], 'Run notes must survive in the policy snapshot');
    payroll_test_assert(
        preg_match('/^[a-f0-9]{64}$/', (string) ($policySnapshot['source_fingerprint']['hash'] ?? '')) === 1,
        'Draft must store a deterministic source-data fingerprint'
    );
    $linkStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM payroll_item_attendance pia
         JOIN payroll_items pi ON pi.id=pia.payroll_item_id WHERE pi.payroll_run_id=?'
    );
    $linkStmt->execute([$runId]);
    payroll_test_same(1, (int) $linkStmt->fetchColumn(), 'Every consumed daily attendance row must be owned');

    $runCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM payroll_runs')->fetchColumn();
    $auditCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
    payroll_test_throws(
        fn() => payroll_generate_run($pdo, '2026-01-05', '2026-01-05', 1),
        'overlaps run',
        'Overlapping payroll periods must be rejected'
    );
    payroll_test_throws(
        fn() => payroll_preview_employee($pdo, '2026-01-05', '2026-01-07', $employeeId),
        'no earlier than 2026-01-06',
        'Overlap guidance must state the earliest date after the existing payroll run'
    );
    payroll_test_same($runCountBefore, (int) $pdo->query('SELECT COUNT(*) FROM payroll_runs')->fetchColumn(), 'Overlap failure must roll back the run');
    payroll_test_same($auditCountBefore, (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(), 'Overlap failure must roll back audit writes');

    // Draft recalculation takes a fresh compensation snapshot.
    $pdo->prepare('UPDATE employee_compensation_history SET basic_rate=900 WHERE employee_id=?')->execute([$employeeId]);
    payroll_recalculate_draft($pdo, $runId, 1);
    $itemStmt->execute([$runId, $employeeId]);
    $recalculated = $itemStmt->fetch();
    payroll_test_close(900, (float) $recalculated['basic_rate'], 'Draft recalculation must refresh effective compensation');

    $pdo->prepare('UPDATE employee_compensation_history SET basic_rate=925 WHERE employee_id=?')->execute([$employeeId]);
    payroll_test_throws(
        fn() => payroll_transition_run($pdo, $runId, 'For Review', 1),
        'source data changed',
        'A Draft with changed source data must not enter review'
    );
    payroll_recalculate_draft($pdo, $runId, 1);

    payroll_test_throws(
        fn() => payroll_transition_run($pdo, $runId, 'Approved', 1),
        'only advance',
        'Lifecycle must not skip For Review'
    );
    payroll_transition_run($pdo, $runId, 'For Review', 1, 'Submit for review');
    payroll_test_throws(
        fn() => payroll_recalculate_draft($pdo, $runId, 1),
        'only a Draft',
        'For Review payroll must be calculation-immutable'
    );
    payroll_return_to_draft($pdo, $runId, 1, 'Correct source attendance');
    payroll_test_same('Draft', (string) $pdo->query('SELECT status FROM payroll_runs WHERE id=' . $runId)->fetchColumn(), 'For Review payroll must support an audited return to Draft');
    payroll_recalculate_draft($pdo, $runId, 1);
    payroll_transition_run($pdo, $runId, 'For Review', 1);
    payroll_transition_run($pdo, $runId, 'Approved', 1);
    payroll_return_to_draft($pdo, $runId, 1, 'Correct approved calculation');
    $returnedRun = $pdo->query('SELECT * FROM payroll_runs WHERE id=' . $runId)->fetch();
    payroll_test_same('Draft', $returnedRun['status'], 'Approved payroll must support a controlled return to Draft');
    payroll_test_same(null, $returnedRun['reviewed_at'], 'Return to Draft must clear review metadata');
    payroll_test_same(null, $returnedRun['approved_at'], 'Return to Draft must clear approval metadata');
    payroll_recalculate_draft($pdo, $runId, 1);
    $itemBeforeLifecycle = $pdo->query('SELECT * FROM payroll_items WHERE payroll_run_id=' . $runId . ' LIMIT 1')->fetch();
    payroll_transition_run($pdo, $runId, 'For Review', 1);
    payroll_transition_run($pdo, $runId, 'Approved', 1);
    payroll_transition_run($pdo, $runId, 'Finalized', 1);
    payroll_transition_run($pdo, $runId, 'Paid', 1);
    $itemAfterLifecycle = $pdo->query('SELECT * FROM payroll_items WHERE id=' . (int) $itemBeforeLifecycle['id'])->fetch();
    payroll_test_same($itemBeforeLifecycle, $itemAfterLifecycle, 'Lifecycle transitions must not mutate payroll item snapshots');
    payroll_test_throws(
        fn() => payroll_transition_run($pdo, $runId, 'Paid', 1),
        'immutable',
        'Paid payroll must be immutable'
    );
    payroll_test_throws(
        fn() => payroll_recalculate_draft($pdo, $runId, 1),
        'only a Draft',
        'Paid payroll must reject recalculation'
    );

    // Rerunning the additive migration must never rewrite an integrated
    // snapshot just because zero regular pay or deductions are legitimate.
    // A Released status alone is not a legacy marker: integrated Released
    // imports can also carry both immutable snapshots.
    $pdo->exec('INSERT INTO payroll_runs
        (period_start,period_end,processed_by,processed_at,status,policy_snapshot)
        VALUES ("2025-10-01","2025-10-01",1,NOW(),"Released","{\"version\":\"integration-test\"}")');
    $modernReleasedRunId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare(
        'INSERT INTO payroll_items
            (payroll_run_id,employee_id,employment_type,pay_type,basic_rate,days_worked,
             regular_pay,gross_pay,total_deductions,net_pay,calculation_snapshot)
         VALUES (?, ?, "Full-Time", "Daily", 900, 0, 0, 0, 0, 0,
                 "{\"version\":\"integration-test\",\"case\":\"valid-zero-pay\"}")'
    );
    $stmt->execute([$modernReleasedRunId, $employeeId]);
    $modernReleasedItemId = (int) $pdo->lastInsertId();
    $modernReleasedBefore = $pdo->query(
        'SELECT * FROM payroll_items WHERE id=' . $modernReleasedItemId
    )->fetch();

    // Legacy Released remains readable and immutable.
    $pdo->exec('INSERT INTO payroll_runs
        (period_start,period_end,processed_by,processed_at,status)
        VALUES ("2025-12-01","2025-12-01",1,NOW(),"Released")');
    $releasedRunId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare(
        'INSERT INTO payroll_items (payroll_run_id,employee_id,days_worked,gross_pay,net_pay)
         VALUES (?,?,1,800,800)'
    );
    $stmt->execute([$releasedRunId, $employeeId]);
    $legacyReleasedItemId = (int) $pdo->lastInsertId();
    payroll_test_throws(
        fn() => payroll_transition_run($pdo, $releasedRunId, 'Paid', 1),
        'immutable',
        'Legacy Released payroll must stay immutable'
    );
    payroll_test_throws(
        fn() => payroll_delete_draft($pdo, $releasedRunId, 1, 'Do not delete historical payroll'),
        'Only an unpaid Draft',
        'Released payroll must not be deletable'
    );

    $pdo->prepare(
        'UPDATE employees
         SET employment_type="Part-Time", pay_type="Monthly", basic_rate=22000
         WHERE id=?'
    )->execute([$employeeId]);
    $pdo->exec(payroll_test_migration_backfill_sql());

    $modernReleasedAfter = $pdo->query(
        'SELECT * FROM payroll_items WHERE id=' . $modernReleasedItemId
    )->fetch();
    payroll_test_same(
        $modernReleasedBefore,
        $modernReleasedAfter,
        'Migration rerun must preserve integrated Released snapshots with valid zero pay and deductions'
    );

    $legacyReleased = $pdo->query(
        'SELECT * FROM payroll_items WHERE id=' . $legacyReleasedItemId
    )->fetch();
    payroll_test_same('Part-Time', $legacyReleased['employment_type'], 'Legacy item must receive employment type backfill');
    payroll_test_same('Monthly', $legacyReleased['pay_type'], 'Legacy item must receive pay type backfill');
    payroll_test_close(22000, (float) $legacyReleased['basic_rate'], 'Legacy item must receive current basic rate backfill');
    payroll_test_close(800, (float) $legacyReleased['regular_pay'], 'Legacy item must derive regular pay from historic gross and overtime');
    payroll_test_close(0, (float) $legacyReleased['total_deductions'], 'Zero legacy deductions must remain valid');

    // A second migration pass after master-data changes must not reinterpret a
    // successfully backfilled historical item merely because deductions are 0.
    $pdo->prepare('UPDATE employees SET pay_type="Hourly", basic_rate=999 WHERE id=?')
        ->execute([$employeeId]);
    $pdo->exec(payroll_test_migration_backfill_sql());
    $legacyReleasedAfterRerun = $pdo->query(
        'SELECT * FROM payroll_items WHERE id=' . $legacyReleasedItemId
    )->fetch();
    payroll_test_same(
        $legacyReleased,
        $legacyReleasedAfterRerun,
        'Migration rerun must preserve a completed legacy backfill with zero deductions'
    );

    // A calculation error after attendance processing must roll back that
    // projection, the run, links, and audit atomically.
    $badEmployeeId = payroll_test_employee($pdo, 'BAD-001', 'Daily', 0);
    payroll_test_raw_day($pdo, $badEmployeeId, '2026-02-02', '08:00:00', '17:00:00');
    $runsBeforeBad = (int) $pdo->query('SELECT COUNT(*) FROM payroll_runs')->fetchColumn();
    $auditsBeforeBad = (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
    $cashAdvancesBeforeBad = (int) $pdo->query('SELECT COUNT(*) FROM employee_deduction_entries')->fetchColumn();
    payroll_test_throws(
        fn() => payroll_generate_run(
            $pdo,
            '2026-02-02',
            '2026-02-02',
            1,
            null,
            $badEmployeeId,
            'Cash',
            99.95
        ),
        'no HR-approved compensation effective',
        'Invalid compensation must stop payroll'
    );
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id=? AND scan_date="2026-02-02"');
    $stmt->execute([$badEmployeeId]);
    payroll_test_same(0, (int) $stmt->fetchColumn(), 'Failed generation must roll back attendance projection changes');
    payroll_test_same($runsBeforeBad, (int) $pdo->query('SELECT COUNT(*) FROM payroll_runs')->fetchColumn(), 'Failed generation must roll back payroll run');
    payroll_test_same($auditsBeforeBad, (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn(), 'Failed generation must roll back audit');
    payroll_test_same(
        $cashAdvancesBeforeBad,
        (int) $pdo->query('SELECT COUNT(*) FROM employee_deduction_entries')->fetchColumn(),
        'Failed generation must roll back its Cash Advance source row'
    );
    $pdo->prepare('UPDATE employees SET status="Inactive" WHERE id=?')->execute([$badEmployeeId]);
    $pdo->prepare('UPDATE employees SET status="Inactive" WHERE id=?')->execute([$employeeId]);

    // Employee-scoped generation permits two employees to use the same pay
    // period without mixing their attendance, compensation, or payslip data.
    $scopeEmployeeA = payroll_test_employee($pdo, 'SCOPE-A', 'Hourly', 125);
    $scopeEmployeeB = payroll_test_employee($pdo, 'SCOPE-B', 'Daily', 800);
    $pdo->prepare('UPDATE employees SET employment_type="Part-Time" WHERE id=?')->execute([$scopeEmployeeB]);
    $pdo->prepare('UPDATE employee_compensation_history SET employment_type="Part-Time" WHERE employee_id=?')->execute([$scopeEmployeeB]);
    $pdo->prepare(
        'INSERT INTO work_schedules
            (employee_id,day_of_week,schedule_type,shift_start,shift_end,break_minutes)
         VALUES (? ,"Monday","Work","08:00:00","17:00:00",60)'
    )->execute([$scopeEmployeeB]);
    $partTimeScheduleId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO work_schedule_periods (work_schedule_id,period_order,period_start,period_end)
         VALUES (?,1,"08:00:00","12:00:00"),(?,2,"13:00:00","17:00:00")'
    )->execute([$partTimeScheduleId, $partTimeScheduleId]);
    payroll_test_raw_day($pdo, $scopeEmployeeA, '2026-04-06', '08:00:00', '17:00:00');
    payroll_test_raw_day($pdo, $scopeEmployeeB, '2026-04-06', '08:00:00', '17:00:00');

    $preview = payroll_preview_employee(
        $pdo,
        '2026-04-06',
        '2026-04-06',
        $scopeEmployeeA,
        125.25
    );
    payroll_test_close(125.25, (float) $preview['cash_advance_deduction'], 'Preview must include the proposed Cash Advance');
    payroll_test_same(
        0,
        (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE employee_id=' . $scopeEmployeeA)->fetchColumn(),
        'Read-only preview must roll back its attendance projection'
    );
    payroll_test_same(
        0,
        (int) $pdo->query('SELECT COUNT(*) FROM employee_deduction_entries WHERE employee_id=' . $scopeEmployeeA)->fetchColumn(),
        'Read-only preview must not store the proposed Cash Advance'
    );

    $mixedPreview = payroll_preview_batch($pdo, '2026-04-06', '2026-04-06');
    payroll_test_assert($mixedPreview['ready'] >= 2, 'Mixed payroll preview must validate both selected employees: ' . json_encode(array_map(static fn(array $entry): array => [$entry['employee_no'], $entry['status'], $entry['reason']], $mixedPreview['employees'])));
    payroll_test_same(0, $mixedPreview['needs_attention'], 'Mixed preview must report every ready employee');
    payroll_test_assert($mixedPreview['full_time'] >= 1 && $mixedPreview['part_time'] >= 1, 'Mixed preview must include both employment types');
    payroll_test_same(
        0,
        (int) $pdo->query('SELECT COUNT(*) FROM attendance WHERE employee_id IN (' . $scopeEmployeeA . ',' . $scopeEmployeeB . ')')->fetchColumn(),
        'Mixed preview must roll back all attendance projections'
    );

    $scopeRunA = payroll_generate_run(
        $pdo,
        '2026-04-06',
        '2026-04-06',
        1,
        'Employee-scoped Cash payroll',
        $scopeEmployeeA,
        'Cash',
        125.25
    );
    $scopeRunARecord = $pdo->query('SELECT * FROM payroll_runs WHERE id=' . $scopeRunA)->fetch();
    payroll_test_same($scopeEmployeeA, (int) $scopeRunARecord['scope_employee_id'], 'Selected employee must be stored on the run');
    payroll_test_same('Cash', $scopeRunARecord['payment_method'], 'Cash payment method must be stored');
    payroll_test_same('Pending', $scopeRunARecord['payment_status'], 'New payroll must start Pending');
    $scopeAItems = $pdo->query(
        'SELECT employee_id, cash_advance_deduction, calculation_snapshot
         FROM payroll_items WHERE payroll_run_id=' . $scopeRunA
    )->fetchAll();
    payroll_test_same(1, count($scopeAItems), 'Employee-scoped payroll must create exactly one item');
    payroll_test_same($scopeEmployeeA, (int) $scopeAItems[0]['employee_id'], 'Employee-scoped payroll must not include another employee');
    payroll_test_close(125.25, (float) $scopeAItems[0]['cash_advance_deduction'], 'Generate Payroll Cash Advance must be deducted exactly once');
    payroll_test_close(
        (float) $preview['net_pay'],
        (float) $pdo->query('SELECT net_pay FROM payroll_items WHERE payroll_run_id=' . $scopeRunA)->fetchColumn(),
        'Validated preview and frozen Draft must use the same salary calculation'
    );
    $scopeCashAdvanceStmt = $pdo->prepare(
        'SELECT id, amount, status FROM employee_deduction_entries
         WHERE employee_id=? AND deduction_date="2026-04-06" AND deduction_type="Cash Advance"'
    );
    $scopeCashAdvanceStmt->execute([$scopeEmployeeA]);
    $scopeCashAdvanceRows = $scopeCashAdvanceStmt->fetchAll();
    payroll_test_same(1, count($scopeCashAdvanceRows), 'Generate Payroll must create one Cash Advance source row');
    payroll_test_close(125.25, (float) $scopeCashAdvanceRows[0]['amount'], 'Cash Advance source amount must match the submitted amount');
    payroll_test_same('Active', $scopeCashAdvanceRows[0]['status'], 'Generated Cash Advance source must remain active for Draft recalculation');
    $scopeRunPolicy = json_decode((string) $scopeRunARecord['policy_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    payroll_test_close(125.25, (float) ($scopeRunPolicy['cash_advance']['amount'] ?? -1), 'Run snapshot must retain the submitted Cash Advance');
    payroll_test_same(
        (int) $scopeCashAdvanceRows[0]['id'],
        (int) ($scopeRunPolicy['cash_advance']['source_entry_id'] ?? 0),
        'Run snapshot must retain the Cash Advance source id'
    );

    payroll_recalculate_draft($pdo, $scopeRunA, 1);
    payroll_test_same(
        1,
        (int) $pdo->query(
            'SELECT COUNT(*) FROM employee_deduction_entries
             WHERE employee_id=' . $scopeEmployeeA . ' AND deduction_type="Cash Advance"'
        )->fetchColumn(),
        'Draft recalculation must not duplicate the Cash Advance source row'
    );
    payroll_test_close(
        125.25,
        (float) $pdo->query(
            'SELECT cash_advance_deduction FROM payroll_items WHERE payroll_run_id=' . $scopeRunA
        )->fetchColumn(),
        'Draft recalculation must preserve the Cash Advance deduction'
    );

    // Unrelated employee changes must not invalidate the first employee's
    // calculation snapshot or prevent its review transition.
    $pdo->prepare('UPDATE employees SET basic_rate=825 WHERE id=?')->execute([$scopeEmployeeB]);
    payroll_transition_run($pdo, $scopeRunA, 'For Review', 1);
    payroll_return_to_draft($pdo, $scopeRunA, 1, 'Correct calculation');
    payroll_test_throws(
        fn() => payroll_delete_draft($pdo, $scopeRunA, 1, 'Erase prior review'),
        'previously entered review',
        'A reviewed payroll returned to Draft must not be erased'
    );
    payroll_recalculate_draft($pdo, $scopeRunA, 1);
    payroll_transition_run($pdo, $scopeRunA, 'For Review', 1);

    $scopeRunB = payroll_generate_run(
        $pdo,
        '2026-04-06',
        '2026-04-06',
        1,
        'Employee-scoped Bank payroll',
        $scopeEmployeeB,
        'Bank Transfer'
    );
    $scopeRunBRecord = $pdo->query('SELECT * FROM payroll_runs WHERE id=' . $scopeRunB)->fetch();
    payroll_test_same($scopeEmployeeB, (int) $scopeRunBRecord['scope_employee_id'], 'Second employee must use an independent same-period run');
    payroll_test_same('Bank Transfer', $scopeRunBRecord['payment_method'], 'Bank Transfer payment method must be stored');
    $scopeBItems = $pdo->query('SELECT employee_id FROM payroll_items WHERE payroll_run_id=' . $scopeRunB)->fetchAll();
    payroll_test_same(1, count($scopeBItems), 'Second employee payroll must create exactly one item');
    payroll_test_same($scopeEmployeeB, (int) $scopeBItems[0]['employee_id'], 'Second payroll must retain employee ownership');

    payroll_update_payment_method($pdo, $scopeRunB, 'Cash', 1);
    payroll_test_same('Cash', (string) $pdo->query('SELECT payment_method FROM payroll_runs WHERE id=' . $scopeRunB)->fetchColumn(), 'Payment method update must persist');
    payroll_transition_run($pdo, $scopeRunB, 'For Review', 1);
    payroll_transition_run($pdo, $scopeRunB, 'Approved', 1);
    payroll_transition_run($pdo, $scopeRunB, 'Finalized', 1);
    payroll_transition_run($pdo, $scopeRunB, 'Paid', 1);
    $scopeRunBStatus = $pdo->query('SELECT status,payment_status FROM payroll_runs WHERE id=' . $scopeRunB)->fetch();
    payroll_test_same('Paid', $scopeRunBStatus['status'], 'Paid generation workflow must complete the lifecycle');
    payroll_test_same('Paid', $scopeRunBStatus['payment_status'], 'Paid lifecycle must synchronize payment status');
    payroll_test_throws(
        fn() => payroll_delete_draft($pdo, $scopeRunB, 1, 'Try to erase paid history'),
        'Only an unpaid Draft',
        'Paid payroll must not be deletable'
    );

    payroll_test_raw_day($pdo, $scopeEmployeeA, '2026-04-20', '08:00:00', '17:00:00');
    $directPaidRun = payroll_generate_run(
        $pdo, '2026-04-20', '2026-04-20', 1,
        'HR confirmed payment was already made', $scopeEmployeeA,
        'Bank Transfer', 0.0, 'Paid'
    );
    $directPaidRecord = $pdo->query(
        'SELECT status,payment_status,payment_method,paid_at,approved_at,finalized_at,policy_snapshot
         FROM payroll_runs WHERE id=' . $directPaidRun
    )->fetch();
    payroll_test_same('Paid', $directPaidRecord['status'], 'Selecting Paid must create an official Paid run immediately');
    payroll_test_same('Paid', $directPaidRecord['payment_status'], 'Payment status must agree with the run state');
    payroll_test_same('Bank Transfer', $directPaidRecord['payment_method'], 'Direct Paid must preserve the chosen method');
    payroll_test_assert($directPaidRecord['paid_at'] !== null && $directPaidRecord['approved_at'] !== null
        && $directPaidRecord['finalized_at'] !== null, 'Direct Paid must record payment and authorization timestamps');
    $directPaidSnapshot = json_decode((string) $directPaidRecord['policy_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    payroll_test_same('Paid', $directPaidSnapshot['payment']['status'], 'Frozen payment snapshot must say Paid');
    payroll_test_same(1, (int) $pdo->query('SELECT COUNT(*) FROM activity_logs WHERE module="Payroll" AND record_id="' . $directPaidRun . '" AND action="Generated paid payroll"')->fetchColumn(), 'Direct Paid must leave an audit entry');
    $paidSourceCheck = payroll_validate_run_source($pdo, $pdo->query('SELECT * FROM payroll_runs WHERE id=' . $directPaidRun)->fetch());
    payroll_test_same(true, $paidSourceCheck['matches'], 'Direct Paid payroll must retain a valid frozen source fingerprint');
    payroll_test_throws(
        fn() => payroll_delete_draft($pdo, $directPaidRun, 1, 'Try to erase direct payment'),
        'Only an unpaid Draft',
        'Direct Paid payroll must be immutable'
    );
    payroll_test_throws(
        fn() => payroll_generate_run($pdo, '2026-04-27', '2026-04-27', 1,
            'Incorrect paid zero-pay attempt', $scopeEmployeeA, 'Cash', 0.0, 'Paid'),
        'has no Net Pay',
        'A fully absent zero-pay period cannot be released as Paid'
    );
    payroll_test_same(0, (int) $pdo->query('SELECT COUNT(*) FROM payroll_runs WHERE scope_employee_id=' . $scopeEmployeeA . ' AND period_start="2026-04-27"')->fetchColumn(), 'A rejected Paid attempt must leave no partial run');

    payroll_test_raw_day($pdo, $scopeEmployeeA, '2026-04-13', '08:00:00', '17:00:00');
    $deleteCandidate = payroll_generate_run($pdo, '2026-04-13', '2026-04-13', 1, null, $scopeEmployeeA, 'Cash', 25.50);
    $deleteSnapshot = json_decode(
        (string) $pdo->query('SELECT policy_snapshot FROM payroll_runs WHERE id=' . $deleteCandidate)->fetchColumn(),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $draftCashSourceId = (int) $deleteSnapshot['cash_advance']['source_entry_id'];
    payroll_recalculate_draft($pdo, $deleteCandidate, 1);
    $recalculatedSnapshot = json_decode(
        (string) $pdo->query('SELECT policy_snapshot FROM payroll_runs WHERE id=' . $deleteCandidate)->fetchColumn(),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    payroll_test_same($draftCashSourceId, (int) $recalculatedSnapshot['cash_advance']['source_entry_id'], 'Recalculation must preserve the auto-created Cash Advance source id');
    $deletedDraft = payroll_delete_draft($pdo, $deleteCandidate, 1, 'Correct the approved rate and rerun');
    payroll_test_same(1, $deletedDraft['items'], 'Draft deletion must report the removed payroll item');
    payroll_test_same(true, $deletedDraft['cash_advance_removed'], 'Draft deletion must remove its own Cash Advance source');
    payroll_test_same(0, (int) $pdo->query('SELECT COUNT(*) FROM payroll_runs WHERE id=' . $deleteCandidate)->fetchColumn(), 'Draft run must be deleted');
    payroll_test_same(0, (int) $pdo->query('SELECT COUNT(*) FROM employee_deduction_entries WHERE id=' . $draftCashSourceId)->fetchColumn(), 'Its generated Cash Advance must not be charged twice');
    payroll_test_same(0, (int) $pdo->query('SELECT COUNT(*) FROM payroll_item_attendance pia JOIN payroll_items pi ON pi.id=pia.payroll_item_id WHERE pi.payroll_run_id=' . $deleteCandidate)->fetchColumn(), 'Draft attendance ownership must be released');
    $attendanceKept = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE employee_id=? AND scan_date="2026-04-13"');
    $attendanceKept->execute([$scopeEmployeeA]);
    payroll_test_same(1, (int) $attendanceKept->fetchColumn(), 'Validated attendance must survive Draft deletion');
    payroll_test_same(1, (int) $pdo->query('SELECT COUNT(*) FROM activity_logs WHERE module="Payroll" AND record_id="' . $deleteCandidate . '" AND action="Deleted payroll draft"')->fetchColumn(), 'Draft deletion must leave one audit record');
    $replacementDraft = payroll_generate_run($pdo, '2026-04-13', '2026-04-13', 1, 'Replacement after deletion', $scopeEmployeeA);
    payroll_test_same('Draft', (string) $pdo->query('SELECT status FROM payroll_runs WHERE id=' . $replacementDraft)->fetchColumn(), 'The released period must support a new Draft');

    // The same run can safely contain both employment types while each item
    // retains its own rate and separate calculation path.
    payroll_test_raw_day($pdo, $scopeEmployeeA, '2026-05-04', '08:00:00', '17:00:00');
    payroll_test_raw_day($pdo, $scopeEmployeeB, '2026-05-04', '08:00:00', '17:00:00');
    $batchPreview = payroll_preview_batch($pdo, '2026-05-04', '2026-05-04');
    payroll_test_same(2, $batchPreview['ready'], 'Both Full-Time and Part-Time employees must validate for a mixed run');
    payroll_test_same(0, $batchPreview['needs_attention'], 'Mixed run must have no hidden validation failure');
    $mixedRun = payroll_generate_run($pdo, '2026-05-04', '2026-05-04', 1, 'Mixed payroll acceptance');
    $mixedItems = $pdo->query(
        'SELECT employee_id,employment_type,pay_type,monthly_basic_salary,net_pay
         FROM payroll_items WHERE payroll_run_id=' . $mixedRun . ' ORDER BY employee_id'
    )->fetchAll();
    payroll_test_same(2, count($mixedItems), 'Mixed run must create exactly two employee items');
    payroll_test_same('Full-Time', $mixedItems[0]['employment_type'], 'First mixed item must use Full-Time engine');
    payroll_test_same('Part-Time', $mixedItems[1]['employment_type'], 'Second mixed item must use Part-Time engine');
    payroll_test_close(1000, (float) $mixedItems[0]['monthly_basic_salary'], 'Full-Time hourly pay must use eligible hours');
    payroll_test_close(800, (float) $mixedItems[1]['monthly_basic_salary'], 'Part-Time Daily pay must use validated day');
    payroll_test_throws(
        fn() => payroll_generate_run($pdo, '2026-05-04', '2026-05-04', 1),
        'overlaps run',
        'Duplicate mixed payroll for the same period must be blocked'
    );

    // Dates before a selected employee's registration are outside their
    // employment and must be skipped, not misreported as open attendance.
    $newHireId = payroll_test_employee($pdo, 'NEW-HIRE', 'Daily', 650);
    $pdo->prepare('UPDATE employees SET created_at="2026-05-05 09:00:00" WHERE id=?')
        ->execute([$newHireId]);
    payroll_test_raw_day($pdo, $newHireId, '2026-05-05', '08:00:00', '17:00:00');
    $newHireProcessing = payroll_process_attendance_scope(
        $pdo,
        '2026-05-01',
        '2026-05-05',
        $newHireId
    );
    payroll_test_same(5, (int) $newHireProcessing['days'], 'Selected period must retain its inclusive day count');
    payroll_test_same(4, (int) $newHireProcessing['skipped'], 'Pre-employment dates must be skipped');
    payroll_test_same(0, (int) $newHireProcessing['deferred'], 'Pre-employment dates must not be called open attendance');
    payroll_test_same(1, (int) $newHireProcessing['processed'], 'The first eligible attendance day must still process');
    $newHireRun = payroll_generate_run(
        $pdo,
        '2026-05-01',
        '2026-05-05',
        1,
        'New-hire period boundary regression',
        $newHireId,
        'Cash'
    );
    payroll_test_same(
        1,
        (int) $pdo->query('SELECT COUNT(*) FROM payroll_items WHERE payroll_run_id=' . $newHireRun)->fetchColumn(),
        'A selected new hire must generate payroll without pre-employment absence rows'
    );

    // A unique attendance ownership link is enforced even if historical data
    // contains a run whose nominal dates do not overlap the attendance it owns.
    $ownedEmployeeId = payroll_test_employee($pdo, 'OWN-001', 'Daily', 700);
    payroll_test_raw_day($pdo, $ownedEmployeeId, '2026-03-02', '08:00:00', '17:00:00');
    attendance_process_period($pdo, '2026-03-02', '2026-03-02', new DateTimeImmutable('2026-04-01'), false);
    $pdo->prepare('UPDATE employees SET status="Inactive" WHERE id=?')->execute([$ownedEmployeeId]);
    $pdo->exec('INSERT INTO payroll_runs
        (period_start,period_end,processed_by,processed_at,status)
        VALUES ("2026-03-10","2026-03-10",1,NOW(),"Draft")');
    $rogueRunId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare(
        'INSERT INTO payroll_items (payroll_run_id,employee_id,days_worked,gross_pay,net_pay)
         VALUES (?,?,1,700,700)'
    );
    $stmt->execute([$rogueRunId, $ownedEmployeeId]);
    $rogueItemId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT id FROM attendance WHERE employee_id=? AND scan_date="2026-03-02"');
    $stmt->execute([$ownedEmployeeId]);
    $ownedAttendanceId = (int) $stmt->fetchColumn();
    $pdo->prepare('INSERT INTO payroll_item_attendance (payroll_item_id,attendance_id) VALUES (?,?)')
        ->execute([$rogueItemId, $ownedAttendanceId]);
    payroll_test_throws(
        fn() => payroll_generate_run($pdo, '2026-03-02', '2026-03-02', 1),
        'already owned',
        'Attendance linked to any other payroll run must be rejected'
    );
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM payroll_runs WHERE period_start="2026-03-02" AND period_end="2026-03-02"');
    $stmt->execute();
    payroll_test_same(0, (int) $stmt->fetchColumn(), 'Ownership failure must not leave a payroll run');
} finally {
    // Release the schema-bound connection before deleting individual objects.
    // Explicitly release the statement variables most likely to still hold a
    // driver reference, then let the server-level connection perform cleanup.
    $stmt = null;
    $itemStmt = null;
    $linkStmt = null;
    $pdo = null;
    gc_collect_cycles();
    $cleanupFailure = payroll_test_cleanup_database(
        $server,
        $host,
        $port,
        $user,
        $password,
        $database
    );
}

if ($cleanupFailure !== null) {
    throw new RuntimeException('FAIL: Disposable payroll database cleanup failed: ' . $cleanupFailure);
}

echo "Payroll engine tests passed (isolated database {$database}).\n";
