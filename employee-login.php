<?php

declare(strict_types=1);

define('UCCHR_SESSION_NAME', 'UCCHR_EMPLOYEE_SESSION');
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/employee_auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!empty($_SESSION['employee_account_id'])) {
    employee_require_login($pdo);
    redirect('employee-portal.php?page=dashboard');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $result = employee_attempt_login(
        $pdo,
        (string) ($_POST['username'] ?? ''),
        (string) ($_POST['password'] ?? '')
    );
    if ($result['ok']) {
        if ($result['must_change_password']) {
            set_flash('error', 'For security, replace the temporary password before using the portal.');
            redirect('employee-portal.php?page=security');
        }
        set_flash('success', 'Welcome back. Your records are synchronized with the HR system.');
        redirect('employee-portal.php?page=dashboard');
    }
    $error = (string) $result['error'];
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#a8172d">
    <?= pwa_head_tags() ?>
    <title>Employee Login | UCCHR</title>
    <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>

<body class="login-page employee-login-page">
    <div class="login-stage">
        <section class="login-card employee-login-card">
            <img class="site-logo" src="assets/images/ucclogo.jpg" alt="UCC Logo">
            <div class="login-title"><strong>UCCHR</strong><span>Employee Self-Service Portal</span></div>
            <p class="login-kicker"><?= ui_icon('id-card', 'section-icon') ?>Employee Login</p>
            <p class="employee-login-intro">View your biometric attendance, work schedule, and released payslips securely.</p>
            <?= flash_html() ?>
            <?php if ($error): ?><div class="login-error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="login-form" data-submit-lock>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Employee ID<input name="username" autocomplete="username" placeholder="Example: EMP-0010" required autofocus></label>
                <label>Password<input type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required></label>
                <button class="btn btn-neon" type="submit"><?= ui_icon('login', 'button-icon') ?>Open My Portal</button>
            </form>
            <div class="auth-links"><a href="employee-forgot-password.php"><?= ui_icon('key') ?>Forgot password?</a><a href="index.php"><?= ui_icon('shield') ?>Admin login</a></div>
            <p class="employee-access-note">No account yet? Ask HR/Admin to create your private setup link or temporary password.</p>
            <?= pwa_install_button('Install App', 'pwa-login-install') ?>
        </section>
    </div>
    <script src="assets/js/app.js"></script>
    <script src="assets/js/pwa.js" defer></script>
</body>

</html>
