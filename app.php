<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/layout.php';
require_login($pdo);

$page = (string) ($_GET['page'] ?? 'dashboard');
// Keep old bookmarks harmless after retiring the redundant Deductions module.
// Cash Advance is now entered as part of the employee payroll generation flow.
if ($page === 'deductions') {
    redirect('app.php?page=payroll&view=generate');
}

$allowed = [
    'dashboard',
    'attendance',
    'employees',
    'employee_new',
    'employee_edit',
    'schedule',
    'leave',
    'overtime',
    'holidays',
    'payroll',
    'payslips',
    'payroll_settings',
    'reports',
    'audit_logs',
    'profile',
    'settings',
];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

require __DIR__ . '/includes/actions.php';
render_header($pdo, $page);
require __DIR__ . '/pages/' . $page . '.php';
render_footer();
