<?php

declare(strict_types=1);

function attendance_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function attendance_it_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'FAIL: ' . $message . ' (expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true) . ')'
        );
    }
}

/** Execute a procedure-free project SQL file one complete statement at a time. */
function attendance_it_run_sql_file(
    PDO $pdo,
    string $path,
    bool $skipDatabaseDirectives = false
): void {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Could not read migration: ' . $path);
    }
    $statement = '';
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $statement .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) {
            $sql = trim($statement);
            if ($sql !== '') {
                // The bootstrap dump names the normal application database.
                // Integration tests are already connected to a random isolated
                // database, so never allow that dump to switch schemas.
                if (
                    $skipDatabaseDirectives
                    && preg_match('/^(?:CREATE\s+DATABASE|USE)\b/i', $sql)
                ) {
                    $statement = '';
                    continue;
                }
                if (preg_match('/^(?:SELECT|EXECUTE)\b/i', $sql)) {
                    $result = $pdo->query($sql);
                    if ($result instanceof PDOStatement) {
                        $result->fetchAll();
                        $result->closeCursor();
                    }
                } else {
                    $pdo->exec($sql);
                }
            }
            $statement = '';
        }
    }
    if (trim($statement) !== '') {
        throw new RuntimeException('Migration ended with an unterminated SQL statement.');
    }
}

function attendance_it_server_connection(
    string $host,
    string $port,
    string $user,
    string $password
): PDO {
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

function attendance_it_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Empty the disposable schema object-by-object before dropping its namespace.
 * This avoids a MariaDB 10.4 Windows crash observed when DROP DATABASE is
 * asked to recursively tear down a populated InnoDB test database.
 *
 * @return null|string Null on success, otherwise a cleanup error message.
 */
function attendance_it_cleanup_database(
    ?PDO &$server,
    string $host,
    string $port,
    string $user,
    string $password,
    string $database
): ?string {
    if (preg_match('/^ucchr_attendance_it_[1-9]\d*_[a-f0-9]{8}$/D', $database) !== 1) {
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
            $server = attendance_it_server_connection($host, $port, $user, $password);
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
        $quotedDatabase = attendance_it_quote_identifier($database);

        $server->exec('SET SESSION FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($objects as $object) {
                $objectName = (string) ($object['TABLE_NAME'] ?? '');
                $objectType = strtoupper((string) ($object['TABLE_TYPE'] ?? ''));
                if ($objectName === '') {
                    continue;
                }
                $qualified = $quotedDatabase . '.' . attendance_it_quote_identifier($objectName);
                if ($objectType === 'VIEW') {
                    $server->exec('DROP VIEW IF EXISTS ' . $qualified);
                } else {
                    $server->exec('DROP TABLE IF EXISTS ' . $qualified);
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
            return 'Disposable attendance database still contains '
                . $remaining . ' object(s); it was not dropped.';
        }

        $server->exec('DROP DATABASE ' . $quotedDatabase);
        return null;
    } catch (Throwable $error) {
        return $error->getMessage();
    }
}

function attendance_it_employee(PDO $pdo, string $employeeNo): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (employee_no,first_name,last_name,position,daily_rate,status,created_at,
             employment_type,pay_type,basic_rate)
         VALUES (?,"Attendance","Fixture","Test Employee",800,"Active",
                 "2025-01-01 00:00:00","Full-Time","Daily",800)'
    );
    $stmt->execute([$employeeNo]);
    return (int) $pdo->lastInsertId();
}

function attendance_it_append(
    PDO $pdo,
    int $employeeId,
    string $date,
    string $action,
    string $time,
    int $sessionIndex = 1
): array {
    return attendance_append_accepted_log(
        $pdo,
        $employeeId,
        null,
        $date,
        $action,
        new DateTimeImmutable($date . ' ' . $time),
        'attendance-it-device',
        null,
        'Integration Test',
        $sessionIndex
    );
}

$projectRoot = dirname(__DIR__);
$bootstrap = $projectRoot . '/database/ucchr.sql';
$migration = $projectRoot . '/database/integrated_attendance_payroll_update.sql';
$sessionMigration = $projectRoot . '/database/multi_session_attendance_update.sql';

$host = getenv('UCCHR_DB_HOST') ?: '127.0.0.1';
$port = getenv('UCCHR_DB_PORT') ?: '3306';
$user = getenv('UCCHR_DB_USER') ?: 'root';
$password = getenv('UCCHR_DB_PASS') ?: '';
$database = 'ucchr_attendance_it_' . getmypid() . '_' . bin2hex(random_bytes(4));
$quotedDatabase = attendance_it_quote_identifier($database);

$server = attendance_it_server_connection($host, $port, $user, $password);
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
    // Build the isolated database from the repository's deterministic
    // bootstrap instead of CREATE TABLE ... LIKE against live InnoDB tables.
    // The latter can crash MariaDB 10.4 on Windows after prior tablespace
    // recovery; this path never reads or mutates the live application schema.
    attendance_it_run_sql_file($pdo, $bootstrap, true);
    attendance_it_run_sql_file($pdo, $migration);
    attendance_it_run_sql_file($pdo, $sessionMigration);
    attendance_it_run_sql_file($pdo, $sessionMigration);
    require_once $projectRoot . '/includes/attendance_processing.php';
    require_once $projectRoot . '/api/device/attendance_service.php';
    if (!attendance_processing_schema_ready($pdo)) {
        $diagnostic = [];
        foreach (['attendance', 'attendance_logs', 'overtime_requests', 'holidays', 'leave_requests'] as $table) {
            $diagnostic[$table] = array_keys(attendance_table_columns($pdo, $table));
        }
        $diagnostic['_database'] = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $diagnostic['_tables'] = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        throw new RuntimeException(
            'FAIL: Migrated clone must satisfy attendance schema: ' . json_encode($diagnostic)
        );
    }

    $setting = $pdo->prepare(
        'INSERT INTO settings (`key`,`value`) VALUES (?,?)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)'
    );
    foreach (['grace_minutes' => '15', 'break_duration' => '60'] as $key => $value) {
        $setting->execute([$key, $value]);
    }
    $schedule = $pdo->prepare(
        'INSERT INTO default_work_schedules
            (day_of_week,schedule_type,shift_start,shift_end,break_minutes)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE schedule_type=VALUES(schedule_type),
             shift_start=VALUES(shift_start), shift_end=VALUES(shift_end),
             break_minutes=VALUES(break_minutes)'
    );
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $day) {
        $schedule->execute([$day, 'Work', '08:00:00', '17:00:00', 60]);
    }
    foreach (['Saturday', 'Sunday'] as $day) {
        $schedule->execute([$day, 'Off', null, null, 0]);
    }

    // The device-facing AUTO state follows the two Full-Time sessions and a
    // stable punch request id makes a lost-response retry harmless.
    $sessionEmployeeId = attendance_it_employee($pdo, 'ATT-IT-SESSION');
    $sessionEmployee = $pdo->query('SELECT * FROM employees WHERE id=' . $sessionEmployeeId)->fetch();
    $morningIn = record_biometric_attendance(
        $pdo,
        $sessionEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-20 07:55:00'),
        'session-day-p1'
    );
    attendance_it_same('TIME_IN', $morningIn['action'], 'First workday scan must be Morning Time In');
    attendance_it_same('MORNING', $morningIn['sessionLabel'], 'First Full-Time session must be Morning');
    attendance_it_same(4, (int) $morningIn['requiredPunches'], 'Full-Time split shift must require four punches');
    $earlyRepeat = record_biometric_attendance(
        $pdo,
        $sessionEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-20 08:05:00'),
        'session-day-early-repeat'
    );
    attendance_it_same('ALREADY_IN', $earlyRepeat['action'], 'Early physical rescan must not create a lunch Time Out');
    $morningOut = record_biometric_attendance(
        $pdo,
        $sessionEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-20 12:00:00'),
        'session-day-p2'
    );
    attendance_it_same('TIME_OUT', $morningOut['action'], 'Lunch boundary must record Morning Time Out');
    attendance_it_same('INCOMPLETE', $morningOut['status'], 'Morning completion remains pending for Afternoon');
    attendance_it_same('BETWEEN_SESSIONS', $morningOut['attendanceState'], 'Lunch gap state must be explicit');
    $lunchRepeat = record_biometric_attendance(
        $pdo,
        $sessionEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-20 12:05:00'),
        'session-day-lunch-repeat'
    );
    attendance_it_same('NOT_YET_ELIGIBLE', $lunchRepeat['action'], 'Lunch-gap scan must not create an early Afternoon Time In');
    $afternoonIn = record_biometric_attendance(
        $pdo,
        $sessionEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-20 13:00:00'),
        'session-day-p3'
    );
    attendance_it_same('TIME_IN', $afternoonIn['action'], 'Second session must record Afternoon Time In');
    attendance_it_same('AFTERNOON', $afternoonIn['sessionLabel'], 'Second Full-Time session must be Afternoon');
    $afternoonOut = record_biometric_attendance(
        $pdo,
        $sessionEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-20 17:00:00'),
        'session-day-p4'
    );
    attendance_it_same('TIME_OUT', $afternoonOut['action'], 'Final scan must record Afternoon Time Out');
    attendance_it_same('PRESENT', $afternoonOut['status'], 'All four schedule punches must classify Present');
    attendance_it_same('COMPLETE', $afternoonOut['attendanceState'], 'Four accepted punches must complete the day');
    $safeRetry = record_biometric_attendance(
        $pdo,
        $sessionEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-20 17:01:00'),
        'session-day-p4'
    );
    attendance_it_same('ALREADY_RECORDED', $safeRetry['action'], 'Retrying the same physical punch id must be idempotent');
    $sessionLogCount = $pdo->query(
        'SELECT COUNT(*) FROM attendance_logs WHERE employee_id=' . $sessionEmployeeId
            . ' AND attendance_date="2026-04-20"'
    )->fetchColumn();
    attendance_it_same(4, (int) $sessionLogCount, 'A complete Full-Time day must keep exactly four raw punches');

    $afternoonOnlyId = attendance_it_employee($pdo, 'ATT-IT-PM');
    $afternoonOnlyEmployee = $pdo->query('SELECT * FROM employees WHERE id=' . $afternoonOnlyId)->fetch();
    record_biometric_attendance(
        $pdo,
        $afternoonOnlyEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-22 13:00:00'),
        'pm-only-p1'
    );
    $afternoonOnlyOut = record_biometric_attendance(
        $pdo,
        $afternoonOnlyEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-22 17:00:00'),
        'pm-only-p2'
    );
    attendance_it_same('HALF_DAY', $afternoonOnlyOut['status'], 'A complete on-time Afternoon session alone must be Half-Day');

    $lateArrivalId = attendance_it_employee($pdo, 'ATT-IT-1400');
    $lateArrivalEmployee = $pdo->query('SELECT * FROM employees WHERE id=' . $lateArrivalId)->fetch();
    record_biometric_attendance(
        $pdo,
        $lateArrivalEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-23 14:00:00'),
        'late-p1'
    );
    $lateArrivalOut = record_biometric_attendance(
        $pdo,
        $lateArrivalEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-23 17:00:00'),
        'late-p2'
    );
    attendance_it_same('ABSENT', $lateArrivalOut['status'], 'A first arrival at 14:00 must classify Absent');

    $gapEnvelopeId = attendance_it_employee($pdo, 'ATT-IT-GAP');
    $gapEnvelopeEmployee = $pdo->query('SELECT * FROM employees WHERE id=' . $gapEnvelopeId)->fetch();
    record_biometric_attendance(
        $pdo,
        $gapEnvelopeEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-24 10:23:00'),
        'gap-p1'
    );
    $gapEnvelopeOut = record_biometric_attendance(
        $pdo,
        $gapEnvelopeEmployee,
        'AUTO',
        'attendance-it-device',
        null,
        new DateTimeImmutable('2026-04-24 20:59:00'),
        'gap-p2'
    );
    attendance_it_same('ABSENT', $gapEnvelopeOut['status'], 'One 10:23-20:59 pair must not fill an unpunched Afternoon session');

    // Raw business-event idempotency and break/grace/undertime projection.
    $metricsEmployee = attendance_it_employee($pdo, 'ATT-IT-001');
    $firstIn = attendance_it_append($pdo, $metricsEmployee, '2026-04-06', 'TIME_IN', '08:16:00');
    $retryIn = attendance_it_append($pdo, $metricsEmployee, '2026-04-06', 'TIME_IN', '08:16:00');
    attendance_it_same(true, $firstIn['inserted'], 'First accepted Time In must append a raw event');
    attendance_it_same(false, $retryIn['inserted'], 'Retry of the same daily Time In must be idempotent');
    attendance_it_same((int) $firstIn['row']['id'], (int) $retryIn['row']['id'], 'Retry must return the canonical raw event');
    attendance_it_append($pdo, $metricsEmployee, '2026-04-06', 'TIME_OUT', '12:00:00', 1);
    attendance_it_append($pdo, $metricsEmployee, '2026-04-06', 'TIME_IN', '13:00:00', 2);
    attendance_it_append($pdo, $metricsEmployee, '2026-04-06', 'TIME_OUT', '16:30:00', 2);
    $row = attendance_process_employee_date(
        $pdo,
        $metricsEmployee,
        '2026-04-06',
        new DateTimeImmutable('2026-04-07 00:00:00'),
        false
    );
    attendance_it_assert(is_array($row), 'Completed raw day must produce daily attendance');
    attendance_it_same('PRESENT', $row['status'], 'Completed full-coverage work must be Present while late and undertime remain separate metrics');
    attendance_it_same(60, (int) $row['break_minutes'], 'Frozen schedule must retain configured break');
    attendance_it_same(434, (int) $row['worked_minutes'], 'Worked minutes must subtract the overlapped 60-minute break');
    attendance_it_same(434, (int) $row['regular_minutes'], 'Regular minutes must be break-aware within schedule');
    attendance_it_same(1, (int) $row['late_minutes'], '08:16 must be one payable late minute after 15-minute grace');
    attendance_it_same(30, (int) $row['undertime_minutes'], '16:30 departure must produce 30 undertime minutes');
    $sameRow = attendance_process_employee_date(
        $pdo,
        $metricsEmployee,
        '2026-04-06',
        new DateTimeImmutable('2026-04-07 00:00:00'),
        false
    );
    attendance_it_same((int) $row['id'], (int) $sameRow['id'], 'Daily projection reprocessing must be idempotent');
    $count = $pdo->prepare('SELECT COUNT(*) FROM attendance_logs WHERE employee_id=? AND attendance_date="2026-04-06" AND action="TIME_IN"');
    $count->execute([$metricsEmployee]);
    attendance_it_same(2, (int) $count->fetchColumn(), 'Four-punch day must keep one idempotent Time In per session');

    // Open workday absence is deferred unless explicitly forced.
    $absenceEmployee = attendance_it_employee($pdo, 'ATT-IT-002');
    $deferred = attendance_process_employee_date(
        $pdo,
        $absenceEmployee,
        '2026-04-07',
        new DateTimeImmutable('2026-04-07 12:00:00'),
        false
    );
    attendance_it_same(null, $deferred, 'Open workday without scans must defer absence');
    $forced = attendance_process_employee_date(
        $pdo,
        $absenceEmployee,
        '2026-04-07',
        new DateTimeImmutable('2026-04-07 12:00:00'),
        true
    );
    attendance_it_same('ABSENT', $forced['status'], 'Forced processing must close the scanless workday as absent');

    $missingOutEmployee = attendance_it_employee($pdo, 'ATT-IT-011');
    attendance_it_append($pdo, $missingOutEmployee, '2026-04-07', 'TIME_IN', '08:00:00');
    $pendingMissingOut = attendance_process_employee_date(
        $pdo,
        $missingOutEmployee,
        '2026-04-07',
        new DateTimeImmutable('2026-04-07 12:00:00'),
        false
    );
    attendance_it_same('INCOMPLETE', $pendingMissingOut['status'], 'Time In alone must remain pending before Expected Out');
    $dueOpenResult = attendance_finalize_due_open_rows(
        $pdo,
        new DateTimeImmutable('2026-04-07 18:00:00')
    );
    attendance_it_same(1, (int) $dueOpenResult['finalized'], 'Due-open finalizer must close the missing Time Out after Expected Out');
    $closedMissingOutStmt = $pdo->prepare(
        'SELECT status FROM attendance WHERE employee_id=? AND scan_date="2026-04-07"'
    );
    $closedMissingOutStmt->execute([$missingOutEmployee]);
    attendance_it_same('ABSENT', (string) $closedMissingOutStmt->fetchColumn(), 'A workday missing Time Out must automatically become Absent');

    // Approved paid and unpaid leave are projected without scans.
    $paidLeaveEmployee = attendance_it_employee($pdo, 'ATT-IT-003');
    $leave = $pdo->prepare(
        'INSERT INTO leave_requests
            (employee_id,leave_type,start_date,end_date,reason,status)
         VALUES (?,?,?,?,"Integration leave","Approved")'
    );
    $leave->execute([$paidLeaveEmployee, 'Paid Leave', '2026-04-08', '2026-04-08']);
    $paidLeave = attendance_process_employee_date(
        $pdo,
        $paidLeaveEmployee,
        '2026-04-08',
        new DateTimeImmutable('2026-04-09 00:00:00')
    );
    attendance_it_same('PAID_LEAVE', $paidLeave['status'], 'Approved paid leave must project PAID_LEAVE');
    attendance_it_same('Paid Leave', $paidLeave['day_classification'], 'Paid leave classification must be frozen');

    $unpaidLeaveEmployee = attendance_it_employee($pdo, 'ATT-IT-004');
    $leave->execute([$unpaidLeaveEmployee, 'Unpaid Leave', '2026-04-08', '2026-04-08']);
    $unpaidLeave = attendance_process_employee_date(
        $pdo,
        $unpaidLeaveEmployee,
        '2026-04-08',
        new DateTimeImmutable('2026-04-09 00:00:00')
    );
    attendance_it_same('UNPAID_LEAVE', $unpaidLeave['status'], 'Approved unpaid leave must project UNPAID_LEAVE');

    // Batch processing must include employees currently marked On leave, but
    // it must not create payroll-relevant rows for inactive employees.
    $batchLeaveEmployee = attendance_it_employee($pdo, 'ATT-IT-008');
    $pdo->prepare('UPDATE employees SET status="On leave" WHERE id=?')
        ->execute([$batchLeaveEmployee]);
    $leave->execute([$batchLeaveEmployee, 'Paid Leave', '2026-04-14', '2026-04-14']);

    $batchUnpaidLeaveEmployee = attendance_it_employee($pdo, 'ATT-IT-009');
    $pdo->prepare('UPDATE employees SET status="On leave" WHERE id=?')
        ->execute([$batchUnpaidLeaveEmployee]);
    $leave->execute([$batchUnpaidLeaveEmployee, 'Unpaid Leave', '2026-04-14', '2026-04-14']);

    $inactiveLeaveEmployee = attendance_it_employee($pdo, 'ATT-IT-010');
    $pdo->prepare('UPDATE employees SET status="Inactive" WHERE id=?')
        ->execute([$inactiveLeaveEmployee]);
    $leave->execute([$inactiveLeaveEmployee, 'Paid Leave', '2026-04-14', '2026-04-14']);

    attendance_process_date(
        $pdo,
        '2026-04-14',
        new DateTimeImmutable('2026-04-15 00:00:00')
    );
    $batchLeave = $pdo->prepare(
        'SELECT status,leave_request_id FROM attendance WHERE employee_id=? AND scan_date="2026-04-14"'
    );
    $batchLeave->execute([$batchLeaveEmployee]);
    $batchLeaveRow = $batchLeave->fetch();
    attendance_it_assert(is_array($batchLeaveRow), 'Batch processing must create the On leave employee daily row');
    attendance_it_same('PAID_LEAVE', $batchLeaveRow['status'], 'Batch On leave row must retain approved paid-leave classification');
    attendance_it_assert((int) $batchLeaveRow['leave_request_id'] > 0, 'Batch On leave row must link its approved leave request');

    $batchLeave->execute([$batchUnpaidLeaveEmployee]);
    $batchUnpaidLeaveRow = $batchLeave->fetch();
    attendance_it_assert(is_array($batchUnpaidLeaveRow), 'Batch processing must create the On leave employee unpaid-leave row');
    attendance_it_same('UNPAID_LEAVE', $batchUnpaidLeaveRow['status'], 'Batch On leave row must retain approved unpaid-leave classification');
    attendance_it_assert((int) $batchUnpaidLeaveRow['leave_request_id'] > 0, 'Batch unpaid-leave row must link its approved leave request');

    $inactiveBatchCount = $pdo->prepare(
        'SELECT COUNT(*) FROM attendance WHERE employee_id=? AND scan_date="2026-04-14"'
    );
    $inactiveBatchCount->execute([$inactiveLeaveEmployee]);
    attendance_it_same(0, (int) $inactiveBatchCount->fetchColumn(), 'Batch processing must exclude inactive employees');

    // Regular holiday without scans and worked rest day with scans.
    $holidayEmployee = attendance_it_employee($pdo, 'ATT-IT-005');
    $pdo->exec(
        'INSERT INTO holidays (holiday_name,holiday_date,holiday_type,status)
         VALUES ("Integration Holiday","2026-04-09","Regular Holiday","Active")'
    );
    $holiday = attendance_process_employee_date(
        $pdo,
        $holidayEmployee,
        '2026-04-09',
        new DateTimeImmutable('2026-04-10 00:00:00')
    );
    attendance_it_same('REGULAR_HOLIDAY', $holiday['status'], 'Scanless regular holiday must be classified');
    attendance_it_same('Regular Holiday', $holiday['day_classification'], 'Holiday type must be frozen');

    $restEmployee = attendance_it_employee($pdo, 'ATT-IT-006');
    attendance_it_append($pdo, $restEmployee, '2026-04-12', 'TIME_IN', '08:00:00');
    attendance_it_append($pdo, $restEmployee, '2026-04-12', 'TIME_OUT', '12:00:00');
    $restWork = attendance_process_employee_date(
        $pdo,
        $restEmployee,
        '2026-04-12',
        new DateTimeImmutable('2026-04-13 00:00:00')
    );
    attendance_it_same('REST_DAY_WORK', $restWork['status'], 'Completed scans on an Off schedule must be rest-day work');
    attendance_it_same(240, (int) $restWork['worked_minutes'], 'Rest-day work must not deduct a work-schedule break');
    attendance_it_same(0, (int) $restWork['potential_overtime_minutes'], 'Off schedule has no expected-out overtime boundary');

    // Staying beyond Expected Out must not create an overtime request. A manual
    // employee request remains unpaid while Pending, is capped when Approved,
    // and must clear after a later Rejected decision.
    $overtimeEmployee = attendance_it_employee($pdo, 'ATT-IT-007');
    attendance_it_append($pdo, $overtimeEmployee, '2026-04-13', 'TIME_IN', '08:00:00');
    attendance_it_append($pdo, $overtimeEmployee, '2026-04-13', 'TIME_OUT', '12:00:00', 1);
    attendance_it_append($pdo, $overtimeEmployee, '2026-04-13', 'TIME_IN', '13:00:00', 2);
    attendance_it_append($pdo, $overtimeEmployee, '2026-04-13', 'TIME_OUT', '18:00:00', 2);
    $pendingOt = attendance_process_employee_date(
        $pdo,
        $overtimeEmployee,
        '2026-04-13',
        new DateTimeImmutable('2026-04-14 00:00:00')
    );
    attendance_it_same(60, (int) $pendingOt['potential_overtime_minutes'], 'One hour after Expected Out must be potential OT');
    attendance_it_same(0, (int) $pendingOt['approved_overtime_minutes'], 'Unrequested OT must not become approved attendance');
    $requestCount = $pdo->prepare(
        'SELECT COUNT(*) FROM overtime_requests WHERE employee_id=? AND attendance_date="2026-04-13"'
    );
    $requestCount->execute([$overtimeEmployee]);
    attendance_it_same(0, (int) $requestCount->fetchColumn(), 'Attendance processing must never create an overtime request');
    $pdo->prepare(
        'INSERT INTO overtime_requests
            (employee_id,attendance_id,attendance_date,potential_minutes,requested_minutes,
             approved_minutes,status,request_source,reason)
         VALUES (?,?,"2026-04-13",60,60,0,"Pending","Employee","Integration request")'
    )->execute([$overtimeEmployee, (int) $pendingOt['id']]);
    $manualPendingOt = attendance_process_employee_date(
        $pdo,
        $overtimeEmployee,
        '2026-04-13',
        new DateTimeImmutable('2026-04-14 00:00:00')
    );
    attendance_it_same(0, (int) $manualPendingOt['approved_overtime_minutes'], 'Pending employee OT must remain unpaid');
    $ot = $pdo->prepare(
        'UPDATE overtime_requests
         SET status=?, approved_minutes=?
         WHERE employee_id=? AND attendance_date="2026-04-13" AND request_source="Employee"'
    );
    $ot->execute(['Approved', 90, $overtimeEmployee]);
    $approvedOt = attendance_process_employee_date(
        $pdo,
        $overtimeEmployee,
        '2026-04-13',
        new DateTimeImmutable('2026-04-14 00:00:00')
    );
    attendance_it_same(60, (int) $approvedOt['approved_overtime_minutes'], 'Approved OT must cap at calculated potential');
    $ot->execute(['Rejected', 60, $overtimeEmployee]);
    $rejectedOt = attendance_process_employee_date(
        $pdo,
        $overtimeEmployee,
        '2026-04-13',
        new DateTimeImmutable('2026-04-14 00:00:00')
    );
    attendance_it_same(0, (int) $rejectedOt['approved_overtime_minutes'], 'Rejected OT must clear previously approved attendance minutes');
    $request = $pdo->prepare('SELECT status,approved_minutes,request_source FROM overtime_requests WHERE employee_id=? AND attendance_date="2026-04-13"');
    $request->execute([$overtimeEmployee]);
    $request = $request->fetch();
    attendance_it_same('Rejected', $request['status'], 'Rejected request status must remain authoritative');
    attendance_it_same(0, (int) $request['approved_minutes'], 'Rejected request must retain zero approved minutes');
    attendance_it_same('Employee', $request['request_source'], 'Only the employee-submitted request may be synchronized');
} finally {
    $count = null;
    $request = null;
    $setting = null;
    $schedule = null;
    $leave = null;
    $batchLeave = null;
    $inactiveBatchCount = null;
    $ot = null;
    $pdo = null;
    gc_collect_cycles();
    $cleanupFailure = attendance_it_cleanup_database(
        $server,
        $host,
        $port,
        $user,
        $password,
        $database
    );
}

if ($cleanupFailure !== null) {
    throw new RuntimeException(
        'FAIL: Disposable attendance database cleanup failed: ' . $cleanupFailure
    );
}

echo "Attendance processing integration tests passed (isolated migrated database {$database}).\n";
