<?php

declare(strict_types=1);

// Browser responses must never expose stack traces, SQL details, local paths,
// or credentials. Errors remain available in the PHP server log.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

/**
 * Recognize HTTPS at the browser without trusting arbitrary proxy headers.
 * A protected Quick Tunnel connects to this local PHP server over HTTP, but
 * only its loopback connection and trycloudflare.com host may assert HTTPS.
 */
function ucchr_request_is_https(array $server): bool
{
    if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
        return true;
    }

    $peer = (string) ($server['REMOTE_ADDR'] ?? '');
    $host = (string) ($server['HTTP_HOST'] ?? '');
    $proto = strtolower(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')));

    return in_array($peer, ['127.0.0.1', '::1'], true)
        && preg_match('/^[a-z0-9-]+\.trycloudflare\.com(?::443)?$/i', $host) === 1
        && $proto === 'https';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    // XAMPP's configured C:\xampp\tmp may be unavailable when the built-in
    // PHP server runs as a normal Windows user. The user temp directory is
    // writable and keeps login/CSRF/reset sessions working in both modes.
    $sessionPath = sys_get_temp_dir();
    if ($sessionPath !== '' && is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $sessionName = defined('UCCHR_SESSION_NAME')
        ? (string) constant('UCCHR_SESSION_NAME')
        : 'UCCHR_ADMIN_SESSION';
    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sessionName)) {
        session_name($sessionName);
    }
    $secureCookie = ucchr_request_is_https($_SERVER);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Render a dependency-free, stroke-style SVG from the system icon set.
 *
 * Icon markup is selected only from this fixed map. The optional CSS class and
 * accessible label are sanitized before output, so pages can safely reuse the
 * same visual language without loading a third-party font or script.
 */
function ui_icon(string $name, string $class = '', ?string $label = null): string
{
    static $aliases = [
        'users' => 'employees',
        'calendar' => 'schedule',
        'wallet' => 'payroll',
        'document' => 'payslip',
        'report' => 'reports',
        'arrow' => 'arrow-right',
        'scan' => 'scan-frame',
        'view' => 'eye',
    ];
    static $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="4" rx="1.5"/><rect x="14" y="11" width="7" height="10" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
        'attendance' => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.2 2.2 4.8-5.1"/>',
        'fingerprint' => '<path d="M12 11a2 2 0 0 0-2 2c0 2.6-.4 4.8-1.4 6.8"/><path d="M8 16.8c-.2-1.1-.2-2.4-.2-3.8a4.2 4.2 0 0 1 8.4 0c0 2.8-.4 5.1-1.2 7"/><path d="M5.2 16.4C5.1 15.3 5 14.2 5 13a7 7 0 0 1 13.2-3.2"/><path d="M18.8 12.2c.1 2.2-.1 4.1-.6 5.8"/><path d="M8.3 6.8A7 7 0 0 1 12 5"/>',
        'employees' => '<path d="M16 20v-1.6a3.4 3.4 0 0 0-3.4-3.4H7.4A3.4 3.4 0 0 0 4 18.4V20"/><circle cx="10" cy="8" r="3.3"/><path d="M15.5 5.2a3.2 3.2 0 0 1 0 6.2M20 20v-1.6a3.4 3.4 0 0 0-2.6-3.3"/>',
        'employee-add' => '<circle cx="9" cy="8" r="3.3"/><path d="M3.5 20v-1.6A3.4 3.4 0 0 1 6.9 15h4.2a3.4 3.4 0 0 1 3.4 3.4V20M18 8v6M15 11h6"/>',
        'id-card' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><circle cx="8" cy="11" r="2.3"/><path d="M4.8 16c.7-1.5 1.7-2.3 3.2-2.3s2.5.8 3.2 2.3M14 10h4M14 14h4"/>',
        'schedule' => '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M8 2v5M16 2v5M3 9h18M7 13h2M12 13h2M17 13h.01M7 17h2M12 17h2M17 17h.01"/>',
        'leave' => '<rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path d="M8 2v5M16 2v5M3 9h18M8 15h8"/>',
        'overtime' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2M16.5 6.5l1.4 1.4 2.6-2.8"/>',
        'holiday' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/><path d="m12 12.5.9 1.9 2.1.3-1.5 1.5.4 2.1-1.9-1-1.9 1 .4-2.1-1.5-1.5 2.1-.3.9-1.9Z"/>',
        'payroll' => '<rect x="4" y="2.5" width="16" height="19" rx="2"/><path d="M7.5 6h9M8 10.5h2M14 10.5h2M8 14.5h2M14 14.5h2M8 18.5h2M14 18.5h2"/>',
        'payslip' => '<path d="M6 2.5v19l3-2 3 2 3-2 3 2v-19l-3 2-3-2-3 2-3-2Z"/><path d="M9 9h6M9 13h6M9 17h4"/>',
        'deduction' => '<circle cx="12" cy="12" r="9"/><path d="M7.5 12h9"/>',
        'payroll-settings' => '<path d="M4 6h7M15 6h5M4 12h3M11 12h9M4 18h9M17 18h3"/><circle cx="13" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="15" cy="18" r="2"/>',
        'reports' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/><path d="m4 7 6-4 6 6 5-5"/>',
        'audit' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'settings' => '<circle cx="12" cy="12" r="3.5"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'activity' => '<path d="M3 12h4l2.5-7 5 14 2.5-7h4"/>',
        'terminal' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3M13 15h4"/>',
        'scan-frame' => '<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M8 12h8M12 8v8"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="M4 20h4l11-11-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/>',
        'archive' => '<path d="M4 7v13h16V7M3 3h18v4H3zM9 11h6"/>',
        'trash' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 14h10l1-14M10 11v6M14 11v6"/>',
        'save' => '<path d="M4 3h13l3 3v15H4zM8 3v6h8V3M8 21v-7h8v7"/>',
        'print' => '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7" rx="1"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 21h16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8L3 5Z"/>',
        'refresh' => '<path d="M20 7v5h-5M4 17v-5h5"/><path d="M18.5 9A7 7 0 0 0 6 6.5L4 9M5.5 15A7 7 0 0 0 18 17.5l2-2.5"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16.5 8"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'eye' => '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.1 0l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1M14 11a5 5 0 0 0-7.1 0l-2 2A5 5 0 0 0 12 20.1l1.1-1.1"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'shield' => '<path d="M12 2 4 5v6c0 5 3.4 9.2 8 11 4.6-1.8 8-6 8-11V5l-8-3Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/>',
        'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l2 2M14 9l2 2"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'login' => '<path d="M15 3h5v18h-5M10 17l5-5-5-5M15 12H3"/>',
        'camera' => '<path d="M4 7h3l1.5-2h7L17 7h3a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"/><circle cx="12" cy="13" r="4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'wifi' => '<path d="M3 9a14 14 0 0 1 18 0M6 13a9 9 0 0 1 12 0M9.5 16.5a4 4 0 0 1 5 0M12 20h.01"/>',
        'code' => '<path d="m8 9-4 3 4 3M16 9l4 3-4 3M14 5l-4 14"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.3 2.5 3.5 5.5 3.5 9s-1.2 6.5-3.5 9c-2.3-2.5-3.5-5.5-3.5-9S9.7 5.5 12 3Z"/>',
        'earnings' => '<circle cx="12" cy="12" r="9"/><path d="M16 8.5c-.8-1-2-1.5-3.5-1.5-2 0-3.5 1-3.5 2.5 0 3.5 7 1.5 7 5 0 1.5-1.5 2.5-3.5 2.5-1.6 0-3-.6-4-1.7M12 5v14"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'warning' => '<path d="M12 3 2.5 20h19L12 3Z"/><path d="M12 9v5M12 17h.01"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'logout' => '<path d="M10 17l5-5-5-5M15 12H3M15 4h5v16h-5"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    ];

    $name = $aliases[$name] ?? $name;
    $drawing = $icons[$name] ?? $icons['info'];
    $extraClass = trim((string) preg_replace('/[^A-Za-z0-9 _-]/', '', $class));
    $classes = trim('ui-icon ' . $extraClass);
    $accessibility = $label === null || trim($label) === ''
        ? ' aria-hidden="true"'
        : ' role="img" aria-label="' . e(trim($label)) . '"';

    return '<svg class="' . e($classes) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"' . $accessibility . '>'
        . $drawing
        . '</svg>';
}

function ucchr_password_hash_is_valid(?string $passwordHash): bool
{
    $passwordHash = (string) $passwordHash;
    if ($passwordHash === '' || strlen($passwordHash) > 255) {
        return false;
    }

    $info = password_get_info($passwordHash);
    return (string) ($info['algoName'] ?? 'unknown') !== 'unknown';
}

function ucchr_password_verify(string $password, ?string $passwordHash): bool
{
    return $password !== ''
        && strlen($password) <= 72
        && ucchr_password_hash_is_valid($passwordHash)
        && password_verify($password, (string) $passwordHash);
}

function ucchr_password_hash(string $password): string
{
    $passwordBytes = strlen($password);
    if ($passwordBytes < 8 || $passwordBytes > 72) {
        throw new InvalidArgumentException('Passwords must contain between 8 and 72 characters.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || !ucchr_password_hash_is_valid($passwordHash)) {
        throw new RuntimeException('Unable to create a secure password hash.');
    }
    return $passwordHash;
}

/** Shared installation metadata; all URLs stay within this installation. */
function pwa_head_tags(): string
{
    return '<link rel="manifest" href="manifest.webmanifest" type="application/manifest+json">' . "\n"
        . '<link rel="apple-touch-icon" href="assets/images/pwa-icon-192.png">' . "\n"
        . '<meta name="application-name" content="UCCHR">' . "\n"
        . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
        . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
        . '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n"
        . '<meta name="apple-mobile-web-app-title" content="UCCHR">';
}

/** Installation is offered only when supported by the current browser. */
function pwa_install_button(string $label = 'Install App', string $extraClass = ''): string
{
    $safeClass = preg_replace('/[^A-Za-z0-9 _-]/', '', $extraClass) ?? '';
    $classes = trim('btn btn-outline btn-mini pwa-install-button ' . $safeClass);
    return '<button class="' . e($classes) . '" type="button" aria-label="' . e($label) . '" data-pwa-install hidden>'
        . ui_icon('download', 'button-icon') . '<span>' . e($label) . '</span></button>';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_token_is_valid(mixed $sessionToken, mixed $submittedToken): bool
{
    return is_string($sessionToken)
        && $sessionToken !== ''
        && is_string($submittedToken)
        && $submittedToken !== ''
        && hash_equals($sessionToken, $submittedToken);
}

function verify_csrf(): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    $submittedToken = $_POST['csrf_token'] ?? null;
    if (!csrf_token_is_valid($sessionToken, $submittedToken)) {
        http_response_code(419);
        exit('The form session expired. Please go back and try again.');
    }
}

const UCCHR_ADMIN_IDLE_TIMEOUT = 28800;

/**
 * Validate an administrator session without redirecting. This is used by
 * JSON/terminal endpoints that must return an HTTP authorization response.
 */
function admin_session_is_valid(PDO $pdo): bool
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if (
        $userId < 1
        || ($lastActivity > 0 && time() - $lastActivity > UCCHR_ADMIN_IDLE_TIMEOUT)
    ) {
        unset(
            $_SESSION['user_id'],
            $_SESSION['auth_password_fingerprint'],
            $_SESSION['admin_last_activity'],
            $_SESSION['csrf_token']
        );
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id, password_hash, role FROM users WHERE id=? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $passwordHash = (string) ($user['password_hash'] ?? '');
    $sessionFingerprint = (string) ($_SESSION['auth_password_fingerprint'] ?? '');
    $currentFingerprint = $passwordHash !== '' ? hash('sha256', $passwordHash) : '';
    if (
        !$user
        || (string) ($user['role'] ?? '') !== 'Administrator'
        || !ucchr_password_hash_is_valid($passwordHash)
        || $sessionFingerprint === ''
        || !hash_equals($sessionFingerprint, $currentFingerprint)
    ) {
        unset(
            $_SESSION['user_id'],
            $_SESSION['auth_password_fingerprint'],
            $_SESSION['admin_last_activity'],
            $_SESSION['csrf_token']
        );
        return false;
    }

    $_SESSION['admin_last_activity'] = time();
    return true;
}

function require_login(?PDO $pdo = null): void
{
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if (
        $userId < 1
        || ($lastActivity > 0 && time() - $lastActivity > UCCHR_ADMIN_IDLE_TIMEOUT)
    ) {
        $expired = $userId > 0;
        unset(
            $_SESSION['user_id'],
            $_SESSION['auth_password_fingerprint'],
            $_SESSION['admin_last_activity'],
            $_SESSION['csrf_token']
        );
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        if ($expired) {
            set_flash('error', 'Your administrator session expired. Please log in again.');
        }
        header('Location: index.php');
        exit;
    }

    if ($pdo !== null) {
        $stmt = $pdo->prepare('SELECT password_hash, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || (string) ($user['role'] ?? '') !== 'Administrator') {
            unset(
                $_SESSION['user_id'],
                $_SESSION['auth_password_fingerprint'],
                $_SESSION['admin_last_activity'],
                $_SESSION['csrf_token']
            );
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
        }

        $passwordHash = (string) $user['password_hash'];

        $currentFingerprint = hash('sha256', (string) $passwordHash);
        $sessionFingerprint = (string) ($_SESSION['auth_password_fingerprint'] ?? '');
        if ($sessionFingerprint === '' || !hash_equals($sessionFingerprint, $currentFingerprint)) {
            unset(
                $_SESSION['user_id'],
                $_SESSION['auth_password_fingerprint'],
                $_SESSION['admin_last_activity'],
                $_SESSION['csrf_token']
            );
            session_regenerate_id(true);
            set_flash('error', 'Your password changed. Log in again with the new password.');
            header('Location: index.php');
            exit;
        }
        $_SESSION['auth_password_fingerprint'] = $currentFingerprint;
    }
    $_SESSION['admin_last_activity'] = time();
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_html(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="flash flash-' . e($flash['type']) . '" role="status" aria-live="polite">' . e($flash['message']) . '<button type="button" data-dismiss aria-label="Dismiss message">&times;</button></div>';
}

function money(float|int|string $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/** The single deduction equation used by payroll, review sheets, and payslips. */
function ucchr_payroll_net_formula(): string
{
    return 'Gross Pay − Late − Undertime − Absence / Unpaid Time − Cash Advance = Net Pay';
}

/** Render the frozen values of the payroll deduction equation. */
function ucchr_payroll_net_calculation(array $item): string
{
    $calculation = money($item['gross_pay'] ?? 0)
        . ' − ' . money($item['late_deduction'] ?? 0)
        . ' − ' . money($item['undertime_deduction'] ?? 0)
        . ' − ' . money((float) ($item['half_day_deduction'] ?? 0) + (float) ($item['absence_deduction'] ?? 0))
        . ' − ' . money($item['cash_advance_deduction'] ?? 0);
    // Keep imported historical slips mathematically accurate without allowing
    // new "Other" deductions in the current payroll policy.
    if ((float) ($item['other_deductions'] ?? 0) > 0) {
        $calculation .= ' − ' . money($item['other_deductions']);
    }
    return $calculation . ' = ' . money($item['net_pay'] ?? 0);
}

/**
 * Human-readable Late/Undertime duration. Payroll and attendance records keep
 * using the original integer minute values; this helper changes display only.
 */
function ucchr_minutes_label(int $minutes): string
{
    $minutes = max(0, $minutes);
    if ($minutes < 60) {
        return $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;
    $label = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
    if ($remainingMinutes > 0) {
        $label .= ' ' . $remainingMinutes . ' '
            . ($remainingMinutes === 1 ? 'minute' : 'minutes');
    }
    return $label;
}

/** Describe the server-side basic salary formula stored with a payroll item. */
function ucchr_payroll_basic_salary_formula(array $item): string
{
    $payType = in_array((string) ($item['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true)
        ? (string) $item['pay_type']
        : 'Daily';
    $snapshot = json_decode((string) ($item['calculation_snapshot'] ?? ''), true);
    $basic = is_array($snapshot) && is_array($snapshot['basic_salary'] ?? null)
        ? $snapshot['basic_salary']
        : [];

    if ($payType === 'Hourly') {
        if (($basic['formula'] ?? '') === 'scheduled_hours_x_effective_hourly_rate_minus_unpaid_time') {
            $hoursByRate = [];
            foreach ((array) ($snapshot['attendance'] ?? []) as $day) {
                if (($day['schedule_type'] ?? '') !== 'Work') {
                    continue;
                }
                $minutes = max(0, (int) ($day['scheduled_minutes'] ?? 0));
                $rate = (float) ($day['compensation']['basic_rate'] ?? 0);
                if ($minutes < 1 || $rate <= 0) {
                    continue;
                }
                $key = number_format($rate, 4, '.', '');
                $hoursByRate[$key] = ($hoursByRate[$key] ?? 0) + $minutes;
            }
            if ($hoursByRate) {
                $terms = [];
                foreach ($hoursByRate as $rate => $minutes) {
                    $terms[] = number_format($minutes / 60, 2) . ' scheduled hours × ' . money((float) $rate) . '/hour';
                }
                return implode(' + ', $terms) . ' = ' . money($item['monthly_basic_salary'] ?? 0);
            }
        }
        return number_format((float) ($item['regular_hours'] ?? 0), 2)
            . ' eligible hours × ' . money($item['basic_rate'] ?? 0) . '/hour';
    }
    if ($payType === 'Monthly') {
        return ($basic['formula'] ?? '') === 'effective_monthly_rate_prorated_by_calendar_days_with_schedule_deductions'
            ? 'Effective monthly rate prorated to the payroll period = ' . money($item['monthly_basic_salary'] ?? 0)
            : 'Configured monthly rate with schedule-based attendance deductions';
    }
    if (($basic['formula'] ?? '') === 'daily_rate_x_30') {
        return 'Daily rate × 30 days (legacy frozen calculation)';
    }
    if (($basic['formula'] ?? '') === 'validated_payable_days_x_effective_daily_rate') {
        return 'Validated full/half-days × effective daily rate (Part-Time)';
    }
    if (($basic['formula'] ?? '') === 'scheduled_workdays_x_effective_daily_rate_minus_unpaid_time') {
        $daysByRate = [];
        foreach ((array) ($snapshot['attendance'] ?? []) as $day) {
            if (($day['schedule_type'] ?? '') !== 'Work' || (int) ($day['scheduled_minutes'] ?? 0) < 1) {
                continue;
            }
            $rate = (float) ($day['compensation']['basic_rate'] ?? 0);
            if ($rate <= 0) {
                continue;
            }
            $key = number_format($rate, 4, '.', '');
            $daysByRate[$key] = ($daysByRate[$key] ?? 0) + 1;
        }
        if ($daysByRate) {
            $terms = [];
            foreach ($daysByRate as $rate => $days) {
                $terms[] = $days . ' scheduled workday' . ($days === 1 ? '' : 's')
                    . ' × ' . money((float) $rate) . '/day';
            }
            return implode(' + ', $terms) . ' = ' . money($item['monthly_basic_salary'] ?? 0);
        }
        return 'Assigned workdays × effective daily rate; unpaid time itemized below';
    }
    $scheduledDays = max(0, (int) ($basic['scheduled_days'] ?? 0));
    return $scheduledDays > 0
        ? $scheduledDays . ' scheduled workday' . ($scheduledDays === 1 ? '' : 's') . ' × daily rate'
        : 'Scheduled workdays × daily rate';
}

function initials(string $first, string $last): string
{
    return strtoupper(substr(trim($first), 0, 1) . substr(trim($last), 0, 1));
}

function log_activity(PDO $pdo, int $userId, string $action): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$userId, $action]);
}

function current_user(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id'] ?? 0]);
    return $stmt->fetch() ?: [];
}

function page_meta(string $page): array
{
    $map = [
        'dashboard' => ['Dashboard', 'Overview of ' . date('F Y')],
        'attendance' => ['Attendance Log', 'Daily biometric time records'],
        'employees' => ['Employee Records', 'Manage personnel information'],
        'employee_new' => ['Employee Records', 'Add a new employee'],
        'employee_edit' => ['Employee Records', 'Update employee information'],
        'schedule' => ['Work Schedule', 'Weekly employee shifts'],
        'leave' => ['Leave Management', 'Review employee leave requests'],
        'overtime' => ['Overtime Approvals', 'Review employee-submitted overtime requests'],
        'holidays' => ['Holiday Management', 'Maintain the official holiday calendar'],
        'payroll' => ['Run Payroll', 'Generate, review, and track employee payroll'],
        'payslips' => ['Pay Slips', 'Employee payroll statements'],
        'payroll_settings' => ['Payroll Settings', 'Configure attendance and payroll policies'],
        'reports' => ['Reports', 'Overview of ' . date('F Y')],
        'audit_logs' => ['Audit Logs', 'Review important system activity'],
        'profile' => ['My Profile', 'Manage your account'],
        'settings' => ['Settings', 'System preferences and configuration'],
    ];
    return $map[$page] ?? ['UCC HR System', ''];
}
