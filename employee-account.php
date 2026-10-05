<?php

declare(strict_types=1);

define('UCCHR_SESSION_NAME', 'UCCHR_EMPLOYEE_SESSION');
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/employee_auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow', true);

$rawToken = strtolower(trim((string) ($_POST['token'] ?? $_GET['token'] ?? '')));
$tokenRecord = employee_token_record($pdo, $rawToken);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    if (!$tokenRecord) {
        $error = 'This setup/reset link is invalid, expired, or already used.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'The new password and confirmation do not match.';
    } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
        $error = 'Use a password containing 8 to 72 characters.';
    } else {
        try {
            employee_consume_account_token($pdo, $rawToken, $newPassword);
            employee_clear_auth_session();
            set_flash('success', 'Employee password saved securely. You can now log in with your Employee ID.');
            redirect('employee-login.php');
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'This setup/reset link could not be completed. Ask HR/Admin for a new link.';
        }
    }
}

$purposeLabel = ($tokenRecord['purpose'] ?? '') === 'Reset' ? 'Reset Employee Password' : 'Activate Employee Account';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#a8172d">
    <?= pwa_head_tags() ?>
    <title><?= e($purposeLabel) ?> | UCCHR</title>
    <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>

<body class="login-page employee-login-page">
    <div class="login-stage">
        <section class="login-card recovery-card employee-login-card">
            <img class="site-logo" src="assets/images/ucclogo.jpg" alt="UCC Logo">
            <div class="login-title"><strong>UCCHR</strong><span><?= e($purposeLabel) ?></span></div>
            <?php if ($error): ?><div class="login-error" role="alert"><?= e($error) ?></div><?php endif; ?>
            <?php if ($tokenRecord): ?>
                <p class="employee-login-intro">Account for <strong><?= e($tokenRecord['employee_no']) ?></strong> · <?= e($tokenRecord['first_name'] . ' ' . $tokenRecord['last_name']) ?></p>
                <form method="post" class="login-form" data-submit-lock>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="token" value="<?= e($rawToken) ?>">
                    <label>New Password<input type="password" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
                    <label>Confirm New Password<input type="password" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
                    <button class="btn btn-neon" type="submit"><?= ui_icon('key', 'button-icon') ?>Save New Password</button>
                </form>
            <?php elseif (!$error): ?>
                <div class="login-error" role="alert">This setup/reset link is invalid, expired, or already used.</div>
            <?php endif; ?>
            <div class="auth-links"><a href="employee-login.php"><?= ui_icon('arrow-left') ?>Return to employee login</a></div>
            <?= pwa_install_button('Install App', 'pwa-login-install') ?>
        </section>
    </div>
    <script src="assets/js/app.js"></script>
    <script src="assets/js/pwa.js" defer></script>
</body>

</html>
