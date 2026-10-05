<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/admin_auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!empty($_SESSION['user_id'])) {
    redirect('app.php?page=dashboard');
}
unset($_SESSION['password_change_flow']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $user = null;
        if ($username !== '' && strlen($username) <= 80 && $password !== '' && strlen($password) <= 72) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username=? AND role="Administrator" LIMIT 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch() ?: null;
        }
        $authScopes = admin_auth_scopes($user ? (int) $user['id'] : null);
        if (admin_auth_is_throttled($pdo, $authScopes)) {
            $error = 'Too many failed attempts. Wait five minutes, then try again.';
            $user = null;
        }
        if ($user && !ucchr_password_hash_is_valid((string) ($user['password_hash'] ?? ''))) {
            error_log('UCCHR login blocked because user #' . (int) $user['id'] . ' has an invalid password hash.');
        }
        if ($error === '' && $user && ucchr_password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $rehash = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($rehash) || !ucchr_password_hash_is_valid($rehash)) {
                    throw new RuntimeException('Unable to refresh the password hash.');
                }
                $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$rehash, $user['id']]);
                $user['password_hash'] = $rehash;
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['auth_password_fingerprint'] = hash('sha256', (string) $user['password_hash']);
            $_SESSION['admin_last_activity'] = time();
            admin_auth_clear_failures($pdo, $authScopes);
            $pdo->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]);
            log_activity($pdo, (int) $user['id'], 'Logged in');
            redirect('app.php?page=dashboard');
        }
        if ($error === '') {
            $justLocked = admin_auth_record_failure($pdo, $authScopes);
            $error = $justLocked
                ? 'Too many failed attempts. Wait five minutes, then try again.'
                : 'Invalid username or password.';
        }
    } catch (Throwable $exception) {
        error_log('[UCCHR admin login] Security check failed: ' . $exception->getMessage());
        http_response_code(503);
        $error = 'Administrator login is temporarily unavailable. Apply the latest security database update, then try again.';
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#a8172d">
    <?= pwa_head_tags() ?>
    <title>Admin Login | UCCHR</title>
    <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>

<body class="login-page">
    <div class="login-stage">
        <section class="login-card">
            <img class="site-logo" src="assets/images/ucclogo.jpg" alt="UCC Logo">
            <div class="login-title"><strong>UCCHR</strong><span>Ubay Community College HR System</span></div>
            <p class="login-kicker"><?= ui_icon('shield', 'section-icon') ?>Web Admin Login</p>
            <?= flash_html() ?>
            <?php if ($error): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>
            <form method="post" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Username<input name="username" maxlength="80" autocomplete="username" placeholder="Enter username" required autofocus></label>
                <label>Password<input type="password" name="password" maxlength="72" autocomplete="current-password" placeholder="Enter password" required></label>
                <button class="btn btn-neon" type="submit"><?= ui_icon('login', 'button-icon') ?>Login</button>
            </form>
            <div class="auth-links"><a href="forgot-password.php"><?= ui_icon('key') ?>Forgot password?</a><a href="employee-login.php"><?= ui_icon('profile') ?>Employee login</a><a href="biometric.php"><?= ui_icon('fingerprint') ?>Open biometric terminal</a></div>
        </section>
    </div>
    <script src="assets/js/pwa.js" defer></script>
</body>

</html>
