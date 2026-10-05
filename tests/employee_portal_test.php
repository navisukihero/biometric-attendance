<?php

declare(strict_types=1);

// Keep this regression test isolated from both the administrator session and
// the real employee-portal session cookie.
define('UCCHR_SESSION_NAME', 'UCCHR_EMPLOYEE_TEST_SESSION');

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/employee_auth.php';
require __DIR__ . '/../includes/overtime_requests.php';

function portal_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function portal_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true) . ')'
        );
    }
}

/**
 * PDO wrapper that maps nested transactions to MySQL savepoints.
 *
 * Employee account helpers intentionally own their short transactions. The
 * outer test transaction must still be able to roll back every mutation made
 * to the selected real account, so inner begin/commit calls become savepoints.
 */
final class EmployeePortalTestPdo extends PDO
{
    private int $transactionDepth = 0;

    public function beginTransaction(): bool
    {
        if ($this->transactionDepth === 0) {
            $started = parent::beginTransaction();
            if ($started) {
                $this->transactionDepth = 1;
            }
            return $started;
        }

        $nextDepth = $this->transactionDepth + 1;
        $this->exec('SAVEPOINT employee_portal_test_' . $nextDepth);
        $this->transactionDepth = $nextDepth;
        return true;
    }

    public function commit(): bool
    {
        if ($this->transactionDepth < 1) {
            throw new PDOException('There is no active transaction.');
        }
        if ($this->transactionDepth === 1) {
            $committed = parent::commit();
            if ($committed) {
                $this->transactionDepth = 0;
            }
            return $committed;
        }

        $this->exec('RELEASE SAVEPOINT employee_portal_test_' . $this->transactionDepth);
        $this->transactionDepth--;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->transactionDepth < 1) {
            throw new PDOException('There is no active transaction.');
        }
        if ($this->transactionDepth === 1) {
            $rolledBack = parent::rollBack();
            if ($rolledBack) {
                $this->transactionDepth = 0;
            }
            return $rolledBack;
        }

        $savepoint = 'employee_portal_test_' . $this->transactionDepth;
        $this->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        $this->exec('RELEASE SAVEPOINT ' . $savepoint);
        $this->transactionDepth--;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }
}

// config/database.php remains the single source for connection settings. Use
// a savepoint-aware connection solely so the whole test stays rollback-only.
$pdo = null;
$pdo = new EmployeePortalTestPdo(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$projectRoot = realpath(__DIR__ . '/..');
portal_assert(is_string($projectRoot), 'Project root must resolve');

$pdo->beginTransaction();

try {
    // The migration must backfill one portal account for every existing
    // employee, including employees registered before this feature existed.
    $missingAccounts = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM employees e
         LEFT JOIN employee_accounts ea ON ea.employee_id=e.id
         WHERE ea.id IS NULL'
    )->fetchColumn();
    portal_assert_same(0, $missingAccounts, 'Every employee must have exactly one portal account');

    $duplicateAccounts = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
             SELECT employee_id FROM employee_accounts GROUP BY employee_id HAVING COUNT(*)<>1
         ) duplicate_employee_accounts'
    )->fetchColumn();
    portal_assert_same(0, $duplicateAccounts, 'Employee portal accounts must remain one-to-one');

    // Exercise an existing employee/account without leaving test credentials
    // or tokens behind. The final outer rollback restores its original state.
    $account = $pdo->query(
        'SELECT ea.id AS account_id, ea.employee_id, ea.username
         FROM employee_accounts ea
         JOIN employees e ON e.id=ea.employee_id
         ORDER BY ea.id
         LIMIT 1
         FOR UPDATE'
    )->fetch();
    portal_assert(is_array($account), 'At least one existing employee account is required');
    $accountId = (int) $account['account_id'];
    $employeeId = (int) $account['employee_id'];
    $username = (string) $account['username'];

    $pdo->prepare('UPDATE employees SET status="Active" WHERE id=?')->execute([$employeeId]);
    $pdo->prepare(
        'UPDATE employee_accounts
         SET password_hash=NULL, account_status="Pending", must_change_password=1,
             failed_attempts=0, locked_until=NULL, reset_requested_at=NULL
         WHERE id=?'
    )->execute([$accountId]);
    $pdo->prepare(
        'UPDATE employee_account_tokens SET used_at=NOW()
         WHERE employee_account_id=? AND used_at IS NULL'
    )->execute([$accountId]);

    $password = 'Portal-Test-' . bin2hex(random_bytes(8));
    $issued = employee_issue_account_token($pdo, $employeeId, 'Activation');
    $rawToken = (string) ($issued['token'] ?? '');
    portal_assert((bool) preg_match('/^[a-f0-9]{64}$/', $rawToken), 'Activation token must have 256-bit hexadecimal entropy');
    portal_assert_same('Activation', (string) $issued['purpose'], 'Pending account must receive an activation token');

    $tokenStmt = $pdo->prepare(
        'SELECT token_hash FROM employee_account_tokens
         WHERE employee_account_id=? AND used_at IS NULL
         ORDER BY id DESC LIMIT 1'
    );
    $tokenStmt->execute([$accountId]);
    $storedTokenHash = (string) $tokenStmt->fetchColumn();
    portal_assert_same(hash('sha256', $rawToken), $storedTokenHash, 'Database must store the SHA-256 token digest');
    portal_assert(!hash_equals($rawToken, $storedTokenHash), 'Raw activation token must never be stored');

    $consumed = employee_consume_account_token($pdo, $rawToken, $password);
    portal_assert_same($accountId, (int) $consumed['employee_account_id'], 'Activation must update the selected employee account');
    $activatedStmt = $pdo->prepare(
        'SELECT password_hash, account_status, must_change_password
         FROM employee_accounts WHERE id=?'
    );
    $activatedStmt->execute([$accountId]);
    $activated = $activatedStmt->fetch();
    portal_assert(is_array($activated), 'Activated account must still exist');
    portal_assert_same('Active', (string) $activated['account_status'], 'Consuming activation token must activate the account');
    portal_assert_same(0, (int) $activated['must_change_password'], 'Self-created password must not be marked temporary');
    portal_assert(ucchr_password_hash_is_valid((string) $activated['password_hash']), 'Employee password must use a supported secure hash');
    portal_assert(ucchr_password_verify($password, (string) $activated['password_hash']), 'New employee password must verify');
    portal_assert((string) $activated['password_hash'] !== $password, 'Employee password must never be stored as plaintext');
    portal_assert(employee_token_record($pdo, $rawToken) === [], 'Consumed activation token must be single-use');

    portal_assert_same(90, ucchr_overtime_hours_to_minutes('1.5'), 'Overtime hours must convert to whole payroll minutes');
    portal_assert_same('1h 30m', ucchr_overtime_minutes_label(90), 'Overtime duration labels must stay employee-friendly');
    $invalidOvertimeHoursRejected = false;
    try {
        ucchr_overtime_hours_to_minutes('25');
    } catch (InvalidArgumentException) {
        $invalidOvertimeHoursRejected = true;
    }
    portal_assert($invalidOvertimeHoursRejected, 'Overtime requests above 24 hours must be rejected');

    $reuseRejected = false;
    try {
        employee_consume_account_token($pdo, $rawToken, $password . '-again');
    } catch (RuntimeException) {
        $reuseRejected = true;
    }
    portal_assert($reuseRejected, 'A consumed activation token must not be accepted twice');

    $wrongLogin = employee_attempt_login($pdo, $username, $password . '-wrong');
    portal_assert_same(false, (bool) ($wrongLogin['ok'] ?? false), 'Wrong employee password must be rejected');
    $correctLogin = employee_attempt_login($pdo, $username, $password);
    portal_assert_same(true, (bool) ($correctLogin['ok'] ?? false), 'Correct employee credentials must authenticate');
    portal_assert_same($accountId, (int) ($_SESSION['employee_account_id'] ?? 0), 'Login session must identify the employee account');
    portal_assert(!isset($_SESSION['user_id']), 'Employee login must not create an administrator session');

    // An explicit employee rest day must win over a working organization
    // default; otherwise attendance would display the wrong expected times.
    $pdo->prepare(
        'INSERT INTO default_work_schedules (day_of_week, schedule_type, shift_start, shift_end)
         VALUES ("Tuesday", "Work", "08:00:00", "17:00:00")
         ON DUPLICATE KEY UPDATE schedule_type="Work", shift_start="08:00:00", shift_end="17:00:00"'
    )->execute();
    $pdo->prepare(
        'INSERT INTO work_schedules (employee_id, day_of_week, schedule_type, shift_start, shift_end)
         VALUES (?, "Tuesday", "Off", NULL, NULL)
         ON DUPLICATE KEY UPDATE schedule_type="Off", shift_start=NULL, shift_end=NULL'
    )->execute([$employeeId]);
    $week = employee_weekly_schedule($pdo, $employeeId);
    portal_assert_same(7, count($week), 'Employee weekly schedule must always contain seven days');
    $tuesday = null;
    foreach ($week as $day) {
        if (($day['day_of_week'] ?? '') === 'Tuesday') {
            $tuesday = $day;
            break;
        }
    }
    portal_assert(is_array($tuesday), 'Weekly schedule must include Tuesday');
    portal_assert_same('Off', (string) $tuesday['schedule_type'], 'Explicit employee Off day must not fall back to the default Work day');
    portal_assert_same(null, $tuesday['shift_start'], 'Explicit Off day must not inherit Expected In');
    portal_assert_same(null, $tuesday['shift_end'], 'Explicit Off day must not inherit Expected Out');
    portal_assert_same('Employee assignment', (string) $tuesday['source'], 'Explicit Off day must retain its employee-assignment source');

    // Static route/query guards protect against future regressions that could
    // expose another employee's attendance, payroll, or administrator pages.
    $attendanceSource = file_get_contents($projectRoot . '/employee_pages/attendance.php');
    $dtrSource = file_get_contents($projectRoot . '/employee_pages/dtr.php');
    $dtrHelperSource = file_get_contents($projectRoot . '/includes/employee_dtr.php');
    $dtrPrintSource = file_get_contents($projectRoot . '/employee-dtr-print.php');
    $payslipsSource = file_get_contents($projectRoot . '/employee_pages/payslips.php');
    $singlePayslipSource = file_get_contents($projectRoot . '/employee-payslip.php');
    $portalSource = file_get_contents($projectRoot . '/employee-portal.php');
    $employeeLayoutSource = file_get_contents($projectRoot . '/includes/employee_layout.php');
    $employeeActionsSource = file_get_contents($projectRoot . '/includes/employee_actions.php');
    $overtimeHelperSource = file_get_contents($projectRoot . '/includes/overtime_requests.php');
    $employeeOvertimePrintSource = file_get_contents($projectRoot . '/employee-overtime-print.php');
    $adminOvertimePrintSource = file_get_contents($projectRoot . '/overtime-request-print.php');
    $overtimeDocumentSource = file_get_contents($projectRoot . '/includes/overtime_request_document.php');
    $adminOvertimeActionsSource = file_get_contents($projectRoot . '/includes/integrated_admin_actions.php');
    $leaveSource = file_get_contents($projectRoot . '/employee_pages/leave.php');
    $overtimeSource = file_get_contents($projectRoot . '/employee_pages/overtime.php');
    $holidaysSource = file_get_contents($projectRoot . '/employee_pages/holidays.php');
    $freshSchema = file_get_contents($projectRoot . '/database/ucchr.sql');
    portal_assert(is_string($attendanceSource), 'Attendance page source must be readable');
    portal_assert(is_string($dtrSource), 'DTR page source must be readable');
    portal_assert(is_string($dtrHelperSource), 'DTR helper source must be readable');
    portal_assert(is_string($dtrPrintSource), 'Printable DTR route source must be readable');
    portal_assert(is_string($payslipsSource), 'Payslips page source must be readable');
    portal_assert(is_string($singlePayslipSource), 'Employee payslip route source must be readable');
    portal_assert(is_string($portalSource), 'Employee portal route source must be readable');
    portal_assert(is_string($employeeLayoutSource), 'Employee portal layout source must be readable');
    portal_assert(is_string($employeeActionsSource), 'Employee action source must be readable');
    portal_assert(is_string($overtimeHelperSource), 'Overtime request helper source must be readable');
    portal_assert(is_string($employeeOvertimePrintSource), 'Employee overtime print route must be readable');
    portal_assert(is_string($adminOvertimePrintSource), 'Admin overtime print route must be readable');
    portal_assert(is_string($overtimeDocumentSource), 'Official overtime form template must be readable');
    portal_assert(is_string($adminOvertimeActionsSource), 'Admin overtime action source must be readable');
    portal_assert(is_string($leaveSource), 'Employee leave page source must be readable');
    portal_assert(is_string($overtimeSource), 'Employee overtime page source must be readable');
    portal_assert(is_string($holidaysSource), 'Employee holiday page source must be readable');
    portal_assert(is_string($freshSchema), 'Fresh database schema must be readable');

    $normalizeSql = static fn(string $source): string => preg_replace('/\s+/', ' ', $source) ?? $source;
    $attendanceSql = $normalizeSql($attendanceSource);
    $payslipsSql = $normalizeSql($payslipsSource);
    $singlePayslipSql = $normalizeSql($singlePayslipSource);
    portal_assert(str_contains($attendanceSql, 'WHERE employee_id=?'), 'Attendance query must scope rows to the logged-in employee_id');
    portal_assert(
        str_contains($normalizeSql($dtrHelperSource), 'WHERE employee_id=? AND scan_date BETWEEN ? AND ?'),
        'DTR attendance query must use the authenticated employee_id and selected dates'
    );
    portal_assert(
        str_contains($dtrPrintSource, 'employee_require_login($pdo)')
            && !str_contains($dtrPrintSource, "\$_GET['employee_id']")
            && !str_contains($dtrSource, "\$_GET['employee_id']"),
        'DTR preview and print routes must never accept another employee identity'
    );
    portal_assert(str_contains($payslipsSql, 'WHERE pi.employee_id=?'), 'Payslip list must scope rows to the logged-in employee_id');
    portal_assert(
        str_contains($singlePayslipSql, 'WHERE pi.id=? AND pi.employee_id=? AND pr.status IN ("Approved", "Finalized", "Paid", "Released")'),
        'Printable payslip must require its id, logged-in employee_id, and an approved official status'
    );

    preg_match('/\$allowedPages\s*=\s*\[([^\]]*)\]/s', $portalSource, $allowlistMatch);
    portal_assert(isset($allowlistMatch[1]), 'Employee portal must define an explicit page allowlist');
    preg_match_all('/[\'\"]([a-z_-]+)[\'\"]/', (string) $allowlistMatch[1], $pageMatches);
    $allowedPages = $pageMatches[1] ?? [];
    portal_assert_same(
        ['dashboard', 'attendance', 'dtr', 'schedule', 'payslips', 'leave', 'overtime', 'holidays', 'profile', 'security'],
        $allowedPages,
        'Employee portal allowlist must contain only approved employee self-service pages'
    );
    foreach (['employees', 'employee_new', 'employee_edit', 'payroll', 'settings', 'activity'] as $adminPage) {
        portal_assert(!in_array($adminPage, $allowedPages, true), 'Employee allowlist must exclude administrator page: ' . $adminPage);
    }
    foreach ($allowedPages as $allowedPage) {
        portal_assert(
            is_file($projectRoot . '/employee_pages/' . $allowedPage . '.php'),
            'Every employee allowlist entry must have a page file: employee_pages/' . $allowedPage . '.php'
        );
    }
    portal_assert(!str_contains($portalSource, "includes/actions.php"), 'Employee portal must never load administrator actions');
    portal_assert(str_contains($portalSource, "includes/employee_actions.php"), 'Employee portal must use its restricted employee action handler');

    // New self-service pages may create requests, but they must never accept a
    // posted employee identity. The authenticated employee_id is the only
    // ownership source, and all employee-specific reads/updates stay scoped.
    $normalizedLeave = $normalizeSql($leaveSource);
    $normalizedOvertime = $normalizeSql($overtimeSource);
    $normalizedEmployeeActions = $normalizeSql($employeeActionsSource);
    $normalizedOvertimeHelper = $normalizeSql($overtimeHelperSource);
    portal_assert(
        str_contains($normalizedLeave, 'FROM leave_requests WHERE employee_id=?'),
        'Leave history must be scoped to the logged-in employee'
    );
    portal_assert(
        str_contains($normalizedOvertime, 'FROM overtime_requests WHERE employee_id=?'),
        'Overtime history must be scoped to the logged-in employee'
    );
    portal_assert(
        str_contains($normalizedOvertime, 'WHERE a.employee_id=?'),
        'Potential-overtime choices must be scoped to the logged-in employee'
    );
    portal_assert(
        !str_contains($employeeActionsSource, "\$_POST['employee_id']")
            && !str_contains($employeeActionsSource, '\$_POST["employee_id"]'),
        'Employee actions must never trust a posted employee_id'
    );
    portal_assert(
        str_contains($employeeActionsSource, "\$employee['employee_id']"),
        'Employee actions must derive ownership from the authenticated employee session'
    );
    portal_assert(
        str_contains($normalizedEmployeeActions, 'WHERE id=? AND employee_id=? LIMIT 1 FOR UPDATE'),
        'Leave cancellation must verify request ownership under a row lock'
    );
    portal_assert(
        str_contains($normalizedOvertimeHelper, 'WHERE a.employee_id=?')
            && str_contains($normalizedOvertimeHelper, 'a.potential_overtime_minutes>=?')
            && str_contains($normalizedOvertimeHelper, 'LIMIT 1 FOR UPDATE'),
        'Automatic overtime linkage must verify employee ownership, sufficient potential time, and lock the attendance row'
    );
    portal_assert(
        str_contains($overtimeSource, 'name="requested_hours"')
            && str_contains($overtimeSource, 'name="reason"')
            && !str_contains($overtimeSource, 'name="attendance_date"')
            && !str_contains($overtimeSource, 'name="requested_minutes"'),
        'Employee overtime submission must ask only for requested hours and a reason'
    );
    portal_assert(
        str_contains($employeeOvertimePrintSource, 'employee_require_login($pdo)')
            && str_contains($employeeOvertimePrintSource, "(int) \$employee['employee_id']")
            && !str_contains($employeeOvertimePrintSource, "\$_GET['employee_id']"),
        'Employee overtime documents must be scoped to the authenticated employee and never a posted or queried identity'
    );
    portal_assert(
        str_contains($adminOvertimePrintSource, 'require_login($pdo)')
            && str_contains($overtimeDocumentSource, 'Date &amp; Time Requested')
            && str_contains($overtimeDocumentSource, 'Approval / Rejection Date &amp; Time'),
        'The official overtime form must be authenticated and show request/decision timestamps'
    );
    portal_assert(
        str_contains($adminOvertimeActionsSource, 'approved_by=?, approval_date=NOW()'),
        'Admin overtime decisions must record the approving user and exact decision time'
    );
    portal_assert(
        substr_count($leaveSource, 'name="csrf_token"') >= 2
            && substr_count($overtimeSource, 'name="csrf_token"') >= 1,
        'Every employee self-service POST form must carry a CSRF token'
    );
    portal_assert(
        str_contains($holidaysSource, 'WHERE status="Active" AND holiday_date BETWEEN ? AND ?')
            && !str_contains($holidaysSource, 'method="post"'),
        'Employee holiday calendar must remain an active-only, view-only page'
    );
    foreach (['dtr', 'leave', 'overtime', 'holidays'] as $selfServicePage) {
        portal_assert(
            str_contains($employeeLayoutSource, "employee_nav_link('{$selfServicePage}'"),
            'Employee navigation must expose the approved self-service page: ' . $selfServicePage
        );
    }

    portal_assert(
        (bool) preg_match('/CREATE\s+TABLE\s+employee_accounts\s*\(/i', $freshSchema),
        'Fresh schema must create employee_accounts'
    );
    portal_assert(
        (bool) preg_match('/CREATE\s+TABLE\s+employee_account_tokens\s*\(/i', $freshSchema),
        'Fresh schema must create employee_account_tokens'
    );

    employee_clear_auth_session();
    echo "employee_portal_test: PASS\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
