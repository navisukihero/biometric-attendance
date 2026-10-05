<?php

declare(strict_types=1);

define('UCCHR_SESSION_NAME', 'UCCHR_EMPLOYEE_SESSION');
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/employee_auth.php';
require __DIR__ . '/includes/overtime_requests.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow', true);

$employee = employee_require_login($pdo);
if ((bool) ($employee['must_change_password'] ?? false)) {
    set_flash('error', 'Change the temporary password before printing an overtime request.');
    redirect('employee-portal.php?page=security');
}

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$overtimeDocument = ucchr_overtime_request_document($pdo, (int) $requestId, (int) $employee['employee_id']);
if (!$overtimeDocument) {
    http_response_code(404);
    exit('Overtime request not found.');
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Overtime Request <?= (int) $overtimeDocument['id'] ?> | UCCHR Employee Portal</title>
    <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>

<body class="overtime-print-page">
    <nav class="overtime-print-toolbar" aria-label="Overtime request print controls">
        <a class="btn btn-outline" href="employee-portal.php?page=overtime"><?= ui_icon('arrow-left', 'button-icon') ?>Back to My Requests</a>
        <button class="btn btn-primary" type="button" onclick="window.print()"><?= ui_icon('print', 'button-icon') ?>Print / Save as PDF</button>
    </nav>
    <main class="overtime-print-stage">
        <?php require __DIR__ . '/includes/overtime_request_document.php'; ?>
    </main>
</body>

</html>
