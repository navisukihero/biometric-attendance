<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/attendance_schedule.php';

const EMPLOYEE_PORTAL_IDLE_TIMEOUT = 28800;
const EMPLOYEE_PORTAL_MAX_LOGIN_ATTEMPTS = 5;

function employee_account_by_id(PDO $pdo, int $accountId): array
{
    $stmt = $pdo->prepare(
        'SELECT ea.id AS account_id, ea.employee_id, ea.username, ea.password_hash,
                ea.account_status, ea.must_change_password, ea.failed_attempts,
                ea.locked_until, ea.last_login, ea.password_changed_at,
                ea.reset_requested_at,
                e.employee_no, e.first_name, e.middle_name, e.last_name,
                e.date_of_birth, e.gender, e.civil_status, e.position,
                e.employment_type, e.pay_type, e.basic_rate, e.daily_rate,
                e.contact_number, e.email,
                e.status AS employment_status, e.fingerprint_status,
                e.fingerprint_code, e.created_at AS employee_created_at,
                d.name AS department
         FROM employee_accounts ea
         JOIN employees e ON e.id=ea.employee_id
         LEFT JOIN departments d ON d.id=e.department_id
         WHERE ea.id=?
         LIMIT 1'
    );
    $stmt->execute([$accountId]);
    return $stmt->fetch() ?: [];
}

function employee_clear_auth_session(): void
{
    unset(
        $_SESSION['employee_account_id'],
        $_SESSION['employee_auth_password_fingerprint'],
        $_SESSION['employee_last_activity'],
        $_SESSION['csrf_token']
    );
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function employee_require_login(PDO $pdo): array
{
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $accountId = (int) ($_SESSION['employee_account_id'] ?? 0);
    $lastActivity = (int) ($_SESSION['employee_last_activity'] ?? 0);
    if (
        $accountId < 1
        || ($lastActivity > 0 && time() - $lastActivity > EMPLOYEE_PORTAL_IDLE_TIMEOUT)
    ) {
        employee_clear_auth_session();
        set_flash('error', $accountId > 0
            ? 'Your employee session expired. Please log in again.'
            : 'Please log in to open your employee portal.');
        redirect('employee-login.php');
    }

    $account = employee_account_by_id($pdo, $accountId);
    $passwordHash = (string) ($account['password_hash'] ?? '');
    $sessionFingerprint = (string) ($_SESSION['employee_auth_password_fingerprint'] ?? '');
    $currentFingerprint = hash('sha256', $passwordHash);

    if (
        !$account
        || (string) ($account['account_status'] ?? '') !== 'Active'
        || (string) ($account['employment_status'] ?? '') === 'Inactive'
        || !ucchr_password_hash_is_valid($passwordHash)
        || $sessionFingerprint === ''
        || !hash_equals($sessionFingerprint, $currentFingerprint)
    ) {
        employee_clear_auth_session();
        set_flash('error', 'Your employee access changed. Please log in again or contact HR/Admin.');
        redirect('employee-login.php');
    }

    $_SESSION['employee_last_activity'] = time();
    return $account;
}

function employee_attempt_login(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your Employee ID and password.'];
    }

    $stmt = $pdo->prepare(
        'SELECT ea.id, ea.password_hash, ea.account_status, ea.must_change_password,
                ea.failed_attempts, ea.locked_until,
                CASE WHEN ea.locked_until IS NOT NULL AND ea.locked_until>NOW() THEN 1 ELSE 0 END AS is_locked,
                e.status AS employment_status
         FROM employee_accounts ea
         JOIN employees e ON e.id=ea.employee_id
         WHERE UPPER(ea.username)=UPPER(?)
         LIMIT 1'
    );
    $stmt->execute([$username]);
    $account = $stmt->fetch();
    $genericError = 'Invalid Employee ID or password.';
    if (
        !$account
        || (string) $account['account_status'] !== 'Active'
        || (string) $account['employment_status'] === 'Inactive'
        || !ucchr_password_hash_is_valid((string) $account['password_hash'])
    ) {
        return ['ok' => false, 'error' => $genericError];
    }

    if ((int) ($account['is_locked'] ?? 0) === 1) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Wait five minutes, then try again.'];
    }

    if (!ucchr_password_verify($password, (string) $account['password_hash'])) {
        $attempts = (int) $account['failed_attempts'] + 1;
        if ($attempts >= EMPLOYEE_PORTAL_MAX_LOGIN_ATTEMPTS) {
            $pdo->prepare(
                'UPDATE employee_accounts
                 SET failed_attempts=0, locked_until=DATE_ADD(NOW(), INTERVAL 5 MINUTE)
                 WHERE id=?'
            )->execute([(int) $account['id']]);
            return ['ok' => false, 'error' => 'Too many failed attempts. Wait five minutes, then try again.'];
        }
        $pdo->prepare(
            'UPDATE employee_accounts SET failed_attempts=?, locked_until=NULL WHERE id=?'
        )->execute([$attempts, (int) $account['id']]);
        return ['ok' => false, 'error' => $genericError];
    }

    $passwordHash = (string) $account['password_hash'];
    if (password_needs_rehash($passwordHash, PASSWORD_DEFAULT)) {
        $passwordHash = ucchr_password_hash($password);
        $pdo->prepare(
            'UPDATE employee_accounts SET password_hash=?, password_changed_at=NOW() WHERE id=?'
        )->execute([$passwordHash, (int) $account['id']]);
    }

    $pdo->prepare(
        'UPDATE employee_accounts
         SET failed_attempts=0, locked_until=NULL, last_login=NOW()
         WHERE id=?'
    )->execute([(int) $account['id']]);

    session_regenerate_id(true);
    $_SESSION['employee_account_id'] = (int) $account['id'];
    $_SESSION['employee_auth_password_fingerprint'] = hash('sha256', $passwordHash);
    $_SESSION['employee_last_activity'] = time();

    return [
        'ok' => true,
        'must_change_password' => (bool) $account['must_change_password'],
    ];
}

function employee_ensure_account(PDO $pdo, int $employeeId, ?int $createdBy = null): array
{
    $stmt = $pdo->prepare(
        'SELECT ea.*
         FROM employee_accounts ea
         WHERE ea.employee_id=?
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$employeeId]);
    $account = $stmt->fetch();
    if ($account) {
        return $account;
    }

    $employeeStmt = $pdo->prepare(
        'SELECT employee_no FROM employees WHERE id=? LIMIT 1 FOR UPDATE'
    );
    $employeeStmt->execute([$employeeId]);
    $employeeNo = $employeeStmt->fetchColumn();
    if ($employeeNo === false) {
        throw new InvalidArgumentException('The employee record could not be found.');
    }

    $pdo->prepare(
        'INSERT INTO employee_accounts
            (employee_id, username, password_hash, account_status, created_by, created_at, updated_at)
         VALUES (?, ?, NULL, "Pending", ?, NOW(), NOW())'
    )->execute([$employeeId, (string) $employeeNo, $createdBy]);

    $stmt->execute([$employeeId]);
    return $stmt->fetch() ?: [];
}

function employee_issue_account_token(
    PDO $pdo,
    int $employeeId,
    string $purpose,
    ?int $createdBy = null
): array {
    if (!in_array($purpose, ['Activation', 'Reset'], true)) {
        throw new InvalidArgumentException('Unsupported employee account link type.');
    }

    $pdo->beginTransaction();
    try {
        $account = employee_ensure_account($pdo, $employeeId, $createdBy);
        if (!$account) {
            throw new RuntimeException('The employee account could not be created.');
        }
        if ((string) $account['account_status'] === 'Disabled') {
            throw new RuntimeException('Enable employee portal access before issuing a setup/reset link.');
        }
        if ($purpose === 'Activation' && ucchr_password_hash_is_valid((string) $account['password_hash'])) {
            $purpose = 'Reset';
        }

        $accountId = (int) $account['id'];
        $pdo->prepare(
            'UPDATE employee_account_tokens
             SET used_at=NOW()
             WHERE employee_account_id=? AND used_at IS NULL'
        )->execute([$accountId]);

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $pdo->prepare(
            'INSERT INTO employee_account_tokens
                (employee_account_id, purpose, token_hash, expires_at, created_by, created_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?, NOW())'
        )->execute([$accountId, $purpose, $tokenHash, $createdBy]);
        $expiryStmt = $pdo->prepare(
            'SELECT expires_at FROM employee_account_tokens WHERE token_hash=? LIMIT 1'
        );
        $expiryStmt->execute([$tokenHash]);
        $expiresAt = (string) $expiryStmt->fetchColumn();
        $pdo->prepare(
            'UPDATE employee_accounts SET reset_requested_at=NULL WHERE id=?'
        )->execute([$accountId]);
        $pdo->commit();

        return [
            'token' => $rawToken,
            'purpose' => $purpose,
            'expires_at' => $expiresAt,
            'username' => (string) $account['username'],
            'account_id' => $accountId,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function employee_token_record(PDO $pdo, string $rawToken, bool $forUpdate = false): array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return [];
    }
    $sql =
        'SELECT t.id AS token_id, t.employee_account_id, t.purpose, t.expires_at,
                ea.username, ea.account_status, e.employee_no, e.first_name, e.last_name,
                e.status AS employment_status
         FROM employee_account_tokens t
         JOIN employee_accounts ea ON ea.id=t.employee_account_id
         JOIN employees e ON e.id=ea.employee_id
         WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at>NOW()
         LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([hash('sha256', $rawToken)]);
    return $stmt->fetch() ?: [];
}

function employee_consume_account_token(PDO $pdo, string $rawToken, string $password): array
{
    $passwordHash = ucchr_password_hash($password);
    $pdo->beginTransaction();
    try {
        $token = employee_token_record($pdo, $rawToken, true);
        if (
            !$token
            || (string) $token['employment_status'] === 'Inactive'
            || (string) $token['account_status'] === 'Disabled'
        ) {
            throw new RuntimeException('This employee setup/reset link is invalid or expired.');
        }

        $accountId = (int) $token['employee_account_id'];
        $pdo->prepare(
            'UPDATE employee_accounts
             SET password_hash=?, account_status="Active", must_change_password=0,
                 failed_attempts=0, locked_until=NULL, reset_requested_at=NULL,
                 password_changed_at=NOW(), updated_at=NOW()
             WHERE id=?'
        )->execute([$passwordHash, $accountId]);
        $pdo->prepare(
            'UPDATE employee_account_tokens
             SET used_at=NOW()
             WHERE employee_account_id=? AND used_at IS NULL'
        )->execute([$accountId]);
        $pdo->commit();
        return $token;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function employee_set_temporary_password(
    PDO $pdo,
    int $employeeId,
    string $password,
    ?int $createdBy = null
): array {
    $passwordHash = ucchr_password_hash($password);
    $pdo->beginTransaction();
    try {
        $account = employee_ensure_account($pdo, $employeeId, $createdBy);
        $accountId = (int) ($account['id'] ?? 0);
        if ($accountId < 1) {
            throw new RuntimeException('The employee account could not be created.');
        }
        if ((string) $account['account_status'] === 'Disabled') {
            throw new RuntimeException('Enable employee portal access before assigning a temporary password.');
        }
        $pdo->prepare(
            'UPDATE employee_accounts
             SET password_hash=?, account_status="Active", must_change_password=1,
                 failed_attempts=0, locked_until=NULL, reset_requested_at=NULL,
                 password_changed_at=NOW(), updated_at=NOW()
             WHERE id=?'
        )->execute([$passwordHash, $accountId]);
        $pdo->prepare(
            'UPDATE employee_account_tokens SET used_at=NOW()
             WHERE employee_account_id=? AND used_at IS NULL'
        )->execute([$accountId]);
        $pdo->commit();
        return ['account_id' => $accountId, 'username' => (string) $account['username']];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function employee_set_account_status(PDO $pdo, int $employeeId, string $status): void
{
    if (!in_array($status, ['Pending', 'Active', 'Disabled'], true)) {
        throw new InvalidArgumentException('Unsupported employee account status.');
    }
    if ($status === 'Active') {
        $stmt = $pdo->prepare(
            'UPDATE employee_accounts
             SET account_status="Active", failed_attempts=0, locked_until=NULL
             WHERE employee_id=? AND password_hash IS NOT NULL'
        );
        $stmt->execute([$employeeId]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Create a password or setup link before enabling this account.');
        }
        return;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE employee_accounts SET account_status=? WHERE employee_id=?'
        )->execute([$status, $employeeId]);
        if ($status === 'Disabled') {
            $pdo->prepare(
                'UPDATE employee_account_tokens t
                 JOIN employee_accounts ea ON ea.id=t.employee_account_id
                 SET t.used_at=NOW()
                 WHERE ea.employee_id=? AND t.used_at IS NULL'
            )->execute([$employeeId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function employee_request_password_reset(PDO $pdo, string $employeeNo, string $email): void
{
    $employeeNo = trim($employeeNo);
    $email = trim($email);
    if ($employeeNo === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $stmt = $pdo->prepare(
        'UPDATE employee_accounts ea
         JOIN employees e ON e.id=ea.employee_id
         SET ea.reset_requested_at=NOW()
         WHERE UPPER(ea.username)=UPPER(?)
           AND LOWER(e.email)=LOWER(?)
           AND ea.account_status="Active"
           AND e.status<>"Inactive"'
    );
    $stmt->execute([$employeeNo, $email]);
}

function employee_portal_base_url(): string
{
    $configured = trim((string) (getenv('UCCHR_APP_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $secure = ucchr_request_is_https($_SERVER);
    $scheme = $secure ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost:8080');
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = 'localhost:8080';
    }
    $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $directory = $directory === '/' || $directory === '.' ? '' : '/' . trim($directory, '/');
    return $scheme . '://' . $host . $directory;
}

function employee_account_link(string $rawToken): string
{
    return employee_portal_base_url() . '/employee-account.php?token=' . rawurlencode($rawToken);
}

function employee_weekly_schedule(PDO $pdo, int $employeeId): array
{
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    // SELECT * keeps this helper compatible with installations both before
    // and after the additive break_minutes migration.
    $defaultRows = $pdo->query('SELECT * FROM default_work_schedules')->fetchAll();
    $defaults = [];
    foreach ($defaultRows as $row) {
        $defaults[(string) $row['day_of_week']] = $row;
    }

    $stmt = $pdo->prepare('SELECT * FROM work_schedules WHERE employee_id=?');
    $stmt->execute([$employeeId]);
    $assignments = [];
    foreach ($stmt->fetchAll() as $row) {
        $assignments[(string) $row['day_of_week']] = $row;
    }

    $periodsBySchedule = [];
    if (attendance_has_period_schedule_schema($pdo)) {
        $periodStmt = $pdo->prepare(
            'SELECT wsp.work_schedule_id, wsp.period_start, wsp.period_end
             FROM work_schedule_periods wsp
             JOIN work_schedules ws ON ws.id=wsp.work_schedule_id
             WHERE ws.employee_id=?
             ORDER BY wsp.work_schedule_id, wsp.period_order, wsp.id'
        );
        $periodStmt->execute([$employeeId]);
        foreach ($periodStmt->fetchAll() as $period) {
            $periodsBySchedule[(int) $period['work_schedule_id']][] = $period;
        }
    }

    $result = [];
    foreach ($days as $day) {
        $assigned = $assignments[$day] ?? null;
        $fallback = $defaults[$day] ?? [
            'schedule_type' => $day === 'Sunday' ? 'Off' : 'Work',
            'shift_start' => $day === 'Sunday' ? null : '08:00:00',
            'shift_end' => $day === 'Sunday' ? null : '17:00:00',
            'break_minutes' => 0,
        ];
        $selected = $assigned ?: $fallback;
        $result[] = [
            'day_of_week' => $day,
            'schedule_type' => (string) $selected['schedule_type'],
            'shift_start' => $selected['shift_start'] ?: null,
            'shift_end' => $selected['shift_end'] ?: null,
            'break_minutes' => max(0, (int) ($selected['break_minutes'] ?? 0)),
            'periods' => $assigned
                ? ($periodsBySchedule[(int) ($assigned['id'] ?? 0)] ?? [])
                : [],
            'source' => $assigned ? 'Employee assignment' : 'Organization schedule',
        ];
    }
    return $result;
}
