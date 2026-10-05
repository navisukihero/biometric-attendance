<?php

declare(strict_types=1);

function nav_link(string $page, string $label, string $icon, string $activePage): string
{
    $active = $page === $activePage ? ' active' : '';
    return '<a class="nav-link' . $active . '" href="app.php?page=' . e($page) . '"><span class="nav-icon" aria-hidden="true">' . navigation_icon($icon) . '</span><span>' . e($label) . '</span></a>';
}

/**
 * Render the administrator navigation's local, dependency-free SVG icons.
 * Only icon keys declared here can be rendered; link labels and routes remain
 * escaped and are handled separately by nav_link().
 */
function navigation_icon(string $name): string
{
    return ui_icon($name, 'nav-svg');
}

function render_header(PDO $pdo, string $page): void
{
    $user = current_user($pdo);
    [$title, $subtitle] = page_meta($page);
    $displayName = $user['full_name'] ?? 'HR Admin';
    $email = $user['email'] ?? 'admin@ucchr.edu.ph';
    $terminalOnline = false;
    $terminalLabel = 'Terminal unavailable';
    $terminalTitle = 'Biometric terminal status is unavailable.';

    try {
        $device = $pdo->query(
            'SELECT device_id, last_seen,
                    TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_ago
             FROM device_status
             ORDER BY last_seen DESC
             LIMIT 1'
        )->fetch();

        if ($device) {
            $secondsAgo = max(0, (int) ($device['seconds_ago'] ?? 0));
            $terminalOnline = $secondsAgo <= 20;
            $terminalLabel = $terminalOnline ? 'Terminal Online' : 'Terminal Offline';

            if ($secondsAgo < 60) {
                $relativeLastSeen = $secondsAgo . ' second' . ($secondsAgo === 1 ? '' : 's') . ' ago';
            } elseif ($secondsAgo < 3600) {
                $minutesAgo = (int) floor($secondsAgo / 60);
                $relativeLastSeen = $minutesAgo . ' minute' . ($minutesAgo === 1 ? '' : 's') . ' ago';
            } elseif ($secondsAgo < 86400) {
                $hoursAgo = (int) floor($secondsAgo / 3600);
                $relativeLastSeen = $hoursAgo . ' hour' . ($hoursAgo === 1 ? '' : 's') . ' ago';
            } else {
                $daysAgo = (int) floor($secondsAgo / 86400);
                $relativeLastSeen = $daysAgo . ' day' . ($daysAgo === 1 ? '' : 's') . ' ago';
            }

            $lastSeenAt = strtotime((string) ($device['last_seen'] ?? ''));
            $lastSeenDetail = $lastSeenAt === false
                ? $relativeLastSeen
                : $relativeLastSeen . ' (' . date('M j, Y, g:i:s A', $lastSeenAt) . ')';
            $deviceId = trim((string) ($device['device_id'] ?? ''));
            $terminalTitle = ($deviceId !== '' ? $deviceId . ' · ' : '')
                . ($terminalOnline ? 'Online' : 'Offline')
                . ' · Last heartbeat ' . $lastSeenDetail . '.';
        } else {
            $terminalLabel = 'Terminal Offline';
            $terminalTitle = 'Biometric terminal offline · No ESP32 heartbeat has been received yet.';
        }
    } catch (Throwable) {
        $terminalTitle = 'Biometric terminal status unavailable · Open the terminal for connection details.';
    }
?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#a8172d">
        <?= pwa_head_tags() ?>
        <title><?= e($title) ?> | UCCHR</title>
        <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
        <link rel="stylesheet" href="assets/css/app.css">
        <link rel="stylesheet" href="assets/css/mobile.css">
    </head>

    <body class="app-body page-<?= e($page) ?>">
        <div class="app-shell">
            <aside class="sidebar admin-sidebar" id="sidebar">
                <div class="brand-block">
                    <a href="app.php?page=dashboard" class="brand-mark logo-link"><img class="sidebar-logo" src="assets/images/ucclogo.jpg" alt="UCC Logo"> UCCHR</a>
                    <strong>Ubay Community College</strong>
                    <small>HR System</small>
                </div>
                <nav class="navigation" aria-label="Primary navigation">
                    <p class="nav-heading">Main</p>
                    <?= nav_link('dashboard', 'Dashboard', 'dashboard', $page) ?>
                    <?= nav_link('attendance', 'Attendance Log', 'fingerprint', $page) ?>
                    <p class="nav-heading">People</p>
                    <?= nav_link('employees', 'Employee Records', 'employees', in_array($page, ['employee_new', 'employee_edit'], true) ? 'employees' : $page) ?>
                    <?= nav_link('schedule', 'Work Schedule', 'schedule', $page) ?>
                    <?= nav_link('leave', 'Leave Management', 'leave', $page) ?>
                    <?= nav_link('overtime', 'Overtime Approvals', 'overtime', $page) ?>
                    <?= nav_link('holidays', 'Holiday Management', 'holiday', $page) ?>
                    <p class="nav-heading">Payroll</p>
                    <?= nav_link('payroll', 'Run Payroll', 'payroll', $page) ?>
                    <?= nav_link('payslips', 'Pay Slips', 'payslip', $page) ?>
                    <?= nav_link('payroll_settings', 'Payroll Settings', 'payroll-settings', $page) ?>
                    <p class="nav-heading">Reports</p>
                    <?= nav_link('reports', 'Reports', 'reports', $page) ?>
                    <?= nav_link('audit_logs', 'Audit Logs', 'audit', $page) ?>
                    <?= nav_link('settings', 'Settings', 'settings', $page) ?>
                </nav>
                <div class="sidebar-user">
                    <a href="app.php?page=profile" class="user-summary <?= $page === 'profile' ? 'active' : '' ?>">
                        <span class="avatar avatar-soft">HR</span>
                        <span><strong><?= e($displayName) ?></strong><small><?= e($email) ?></small></span>
                    </a>
                    <form method="post" action="logout.php" class="admin-logout-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <button class="logout-link admin-logout-button" type="submit"><?= ui_icon('logout', 'button-icon') ?> <span>Logout</span></button>
                    </form>
                </div>
            </aside>
            <div class="app-main">
                <header class="topbar">
                    <button class="menu-toggle" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false"><?= ui_icon('menu') ?></button>
                    <div>
                        <h1><?= e($title) ?></h1>
                        <p><?= e($subtitle) ?></p>
                    </div>
                    <div class="topbar-right">
                        <?= pwa_install_button() ?>
                        <a
                            class="terminal-status <?= $terminalOnline ? 'terminal-status-online' : 'terminal-status-offline' ?>"
                            href="biometric.php"
                            title="<?= e($terminalTitle) ?>"><span class="terminal-status-indicator" aria-hidden="true"></span><span><?= e($terminalLabel) ?></span></a>
                        <time datetime="<?= date('Y-m-d') ?>"><?= date('F j, Y') ?></time>
                    </div>
                </header>
                <main class="content-wrap">
                    <?= flash_html() ?>
                <?php
            }

            function render_footer(): void
            {
                ?>
                </main>
            </div>
        </div>
        <script src="assets/js/app.js"></script>
        <script src="assets/js/pwa.js" defer></script>
    </body>

    </html>
<?php
            }
