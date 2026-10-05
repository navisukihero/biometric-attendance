<?php

declare(strict_types=1);

/**
 * Build a navigation link for the employee-only portal.
 */
function employee_nav_link(string $page, string $label, string $icon, string $activePage): string
{
    $active = $page === $activePage ? ' active' : '';

    return '<a class="nav-link' . $active . '" href="employee-portal.php?page=' . e($page) . '">' .
        '<span class="nav-icon" aria-hidden="true">' . ui_icon($icon, 'nav-svg') . '</span>' .
        '<span>' . e($label) . '</span></a>';
}

/**
 * Render the employee portal shell. This navigation intentionally contains no
 * HR, payroll-management, biometric-terminal, edit, or delete entry points.
 */
function render_employee_header(array $employee, string $page): void
{
    $meta = [
        'dashboard' => ['My Overview', 'A private summary of your work records'],
        'attendance' => ['My Attendance', 'Time In, Time Out, late, and overtime records'],
        'dtr' => ['My Daily Time Record', 'Generate and print your official biometric attendance summary'],
        'schedule' => ['My Work Schedule', 'Your assigned work days and expected hours'],
        'payslips' => ['My Payslips', 'View your released payroll records'],
        'leave' => ['My Leave Requests', 'Submit and track your paid or unpaid leave requests'],
        'overtime' => ['My Overtime Requests', 'Submit and track manual overtime requests'],
        'holidays' => ['Holiday Calendar', 'View active regular and special non-working holidays'],
        'profile' => ['My Profile', 'Your employee and account information'],
        'security' => ['Password & Security', 'Keep your employee account secure'],
    ];
    [$title, $subtitle] = $meta[$page] ?? $meta['dashboard'];

    $middleName = trim((string) ($employee['middle_name'] ?? ''));
    $displayName = trim(
        (string) ($employee['first_name'] ?? '') . ' ' .
            ($middleName !== '' ? $middleName . ' ' : '') .
            (string) ($employee['last_name'] ?? '')
    );
    $employeeNo = (string) ($employee['employee_no'] ?? 'Employee');
    $initials = strtoupper(
        substr((string) ($employee['first_name'] ?? 'E'), 0, 1) .
            substr((string) ($employee['last_name'] ?? ''), 0, 1)
    );
?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#a8172d">
        <?= pwa_head_tags() ?>
        <meta name="robots" content="noindex,nofollow">
        <title><?= e($title) ?> | UCCHR Employee Portal</title>
        <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
        <link rel="stylesheet" href="assets/css/app.css">
        <link rel="stylesheet" href="assets/css/mobile.css">
    </head>

    <body class="app-body employee-portal-body">
        <div class="app-shell employee-app-shell">
            <aside class="sidebar employee-sidebar" id="sidebar">
                <div class="brand-block">
                    <a href="employee-portal.php?page=dashboard" class="brand-mark logo-link">
                        <img class="sidebar-logo" src="assets/images/ucclogo.jpg" alt="UCC Logo">
                        UCCHR
                    </a>
                    <strong>Employee Portal</strong>
                    <small>Personal records · View only</small>
                </div>
                <nav class="navigation" aria-label="Employee portal navigation">
                    <p class="nav-heading">My Records</p>
                    <?= employee_nav_link('dashboard', 'Overview', 'dashboard', $page) ?>
                    <?= employee_nav_link('attendance', 'Attendance', 'attendance', $page) ?>
                    <?= employee_nav_link('dtr', 'Daily Time Record', 'clock', $page) ?>
                    <?= employee_nav_link('schedule', 'Work Schedule', 'schedule', $page) ?>
                    <?= employee_nav_link('payslips', 'Payslips', 'payslip', $page) ?>
                    <?= employee_nav_link('holidays', 'Holiday Calendar', 'holiday', $page) ?>
                    <p class="nav-heading">My Requests</p>
                    <?= employee_nav_link('leave', 'Leave Requests', 'leave', $page) ?>
                    <?= employee_nav_link('overtime', 'Overtime Requests', 'overtime', $page) ?>
                    <p class="nav-heading">My Account</p>
                    <?= employee_nav_link('profile', 'Profile', 'profile', $page) ?>
                    <?= employee_nav_link('security', 'Password & Security', 'shield', $page) ?>
                </nav>
                <div class="sidebar-user">
                    <a href="employee-portal.php?page=profile" class="user-summary <?= $page === 'profile' ? 'active' : '' ?>">
                        <span class="avatar avatar-soft"><?= e($initials ?: 'EP') ?></span>
                        <span>
                            <strong><?= e($displayName ?: $employeeNo) ?></strong>
                            <small><?= e($employeeNo) ?></small>
                        </span>
                    </a>
                    <form method="post" action="employee-logout.php" class="employee-logout-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <button class="logout-link employee-logout-button" type="submit"><?= ui_icon('logout', 'button-icon') ?> <span>Log out</span></button>
                    </form>
                </div>
            </aside>
            <div class="app-main employee-app-main">
                <header class="topbar employee-topbar">
                    <button class="menu-toggle" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false"><?= ui_icon('menu') ?></button>
                    <div>
                        <h1><?= e($title) ?></h1>
                        <p><?= e($subtitle) ?></p>
                    </div>
                    <div class="topbar-right">
                        <?= pwa_install_button() ?>
                        <span class="terminal-status">View-only access</span>
                        <time datetime="<?= e(date('Y-m-d')) ?>"><?= e(date('F j, Y')) ?></time>
                    </div>
                </header>
                <main class="content-wrap employee-content-wrap">
                    <?= flash_html() ?>
                <?php
            }

            function render_employee_footer(): void
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
