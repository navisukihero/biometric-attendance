<?php

declare(strict_types=1);

define('UCCHR_SESSION_NAME', 'UCCHR_EMPLOYEE_SESSION');
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/employee_auth.php';
require_once __DIR__ . '/includes/payroll_engine.php';
require __DIR__ . '/includes/employee_layout.php';

$employee = employee_require_login($pdo);
$allowedPages = [
    'dashboard',
    'attendance',
    'dtr',
    'schedule',
    'payslips',
    'leave',
    'overtime',
    'holidays',
    'profile',
    'security',
];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}
if ($page === 'dtr') {
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
if ((bool) $employee['must_change_password'] && $page !== 'security') {
    set_flash('error', 'Change the temporary password before opening the rest of your portal.');
    redirect('employee-portal.php?page=security');
}

require __DIR__ . '/includes/employee_actions.php';
render_employee_header($employee, $page);
require __DIR__ . '/employee_pages/' . $page . '.php';
render_employee_footer();
