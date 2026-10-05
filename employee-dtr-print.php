<?php

declare(strict_types=1);

define('UCCHR_SESSION_NAME', 'UCCHR_EMPLOYEE_SESSION');
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/employee_auth.php';
require __DIR__ . '/includes/employee_dtr.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow', true);

$employee = employee_require_login($pdo);
if ((bool) ($employee['must_change_password'] ?? false)) {
    set_flash('error', 'Change the temporary password before printing your DTR.');
    redirect('employee-portal.php?page=security');
}

$range = employee_dtr_resolve_range(
    isset($_GET['start']) ? (string) $_GET['start'] : null,
    isset($_GET['end']) ? (string) $_GET['end'] : null
);
if ($range['error'] !== '') {
    http_response_code(422);
    $returnUrl = 'employee-portal.php?page=dtr';
?>
    <!doctype html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Invalid DTR Range | UCCHR</title>
        <link rel="stylesheet" href="assets/css/app.css">
        <link rel="stylesheet" href="assets/css/mobile.css">
    </head>

    <body class="dtr-print-page">
        <main class="dtr-print-error">
            <h1>Unable to generate DTR</h1>
            <p><?= e((string) $range['error']) ?></p><a class="btn btn-primary" href="<?= e($returnUrl) ?>"><?= ui_icon('arrow-left', 'button-icon') ?>Return to DTR</a>
        </main>
    </body>

    </html>
<?php
    exit;
}

$dtr = employee_dtr_build($pdo, $employee, $range['start'], $range['end']);
$returnUrl = 'employee-portal.php?page=dtr&start=' . rawurlencode((string) $dtr['start'])
    . '&end=' . rawurlencode((string) $dtr['end']);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>DTR <?= e((string) $dtr['employee']['employee_no']) ?> · <?= e((string) $dtr['start']) ?> | UCCHR</title>
    <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>

<body class="dtr-print-page">
    <nav class="dtr-print-toolbar" aria-label="DTR print controls">
        <a class="btn btn-outline" href="<?= e($returnUrl) ?>"><?= ui_icon('arrow-left', 'button-icon') ?>Back to DTR</a>
        <button class="btn btn-primary" type="button" onclick="window.print()"><?= ui_icon('print', 'button-icon') ?>Print / Save as PDF</button>
    </nav>
    <main class="dtr-print-stage">
        <?php require __DIR__ . '/includes/employee_dtr_document.php'; ?>
    </main>
</body>

</html>
