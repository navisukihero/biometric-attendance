<?php

declare(strict_types=1);

define('UCCHR_SESSION_NAME', 'UCCHR_EMPLOYEE_SESSION');
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/employee_auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$submitted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    employee_request_password_reset(
        $pdo,
        (string) ($_POST['employee_no'] ?? ''),
        (string) ($_POST['email'] ?? '')
    );
    $submitted = true;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#a8172d">
    <?= pwa_head_tags() ?>
    <title>Employee Password Help | UCCHR</title>
    <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>

<body class="login-page employee-login-page">
    <div class="login-stage">
        <section class="login-card recovery-card employee-login-card">
            <img class="site-logo" src="assets/images/ucclogo.jpg" alt="UCC Logo">
            <div class="login-title"><strong>UCCHR</strong><span>Employee Password Help</span></div>
            <?php if ($submitted): ?>
                <div class="recovery-success" role="status">If the Employee ID and registered email match an active account, HR/Admin can now see the request and issue a private one-time reset link or temporary password.</div>
                <a class="btn btn-neon" href="employee-login.php"><?= ui_icon('arrow-left', 'button-icon') ?>Return to Employee Login</a>
            <?php else: ?>
                <p class="employee-login-intro">Enter both items so HR/Admin can verify which employee account needs help.</p>
                <form method="post" class="login-form" data-submit-lock>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label>Employee ID<input name="employee_no" placeholder="Example: EMP-0010" required autofocus></label>
                    <label>Registered Email<input type="email" name="email" autocomplete="email" placeholder="name@example.com" required></label>
                    <button class="btn btn-neon" type="submit"><?= ui_icon('mail', 'button-icon') ?>Request Password Reset</button>
                </form>
                <div class="auth-links"><a href="employee-login.php"><?= ui_icon('arrow-left') ?>Back to employee login</a></div>
            <?php endif; ?>
            <?= pwa_install_button('Install App', 'pwa-login-install') ?>
        </section>
    </div>
    <script src="assets/js/app.js"></script>
    <script src="assets/js/pwa.js" defer></script>
</body>

</html>
