<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/account_recovery.php';
require __DIR__ . '/includes/admin_auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');

if (!empty($_SESSION['user_id'])) {
    redirect('app.php?page=profile');
}

function forgot_password_clear_flow(): void
{
    unset($_SESSION['password_change_flow']);
}

function forgot_password_flow(): array
{
    $flow = $_SESSION['password_change_flow'] ?? null;
    if (
        !is_array($flow)
        || !in_array((string) ($flow['stage'] ?? ''), ['old_password', 'new_password'], true)
        || (int) ($flow['user_id'] ?? 0) <= 0
        || !preg_match('/^[a-f0-9]{32}$/', (string) ($flow['flow_id'] ?? ''))
        || (int) ($flow['expires_at'] ?? 0) < time()
    ) {
        forgot_password_clear_flow();
        return [];
    }
    return $flow;
}

$error = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'restart' || $action === 'cancel') {
            forgot_password_clear_flow();
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            redirect($action === 'cancel' ? 'index.php' : 'forgot-password.php');
        } elseif ($action === 'verify_account') {
            forgot_password_clear_flow();
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $clientScopes = admin_auth_scopes();
            if (admin_auth_is_throttled($pdo, $clientScopes)) {
                $error = 'Too many failed attempts. Wait five minutes, then try again.';
            } elseif ($identifier === '') {
                $error = 'Enter your username or email address.';
            } elseif (strlen($identifier) > 160) {
                $error = 'Invalid username or email. The account was not found.';
            } else {
                $user = account_recovery_find_user($pdo, $identifier);
                if (!$user) {
                    $justLocked = admin_auth_record_failure($pdo, $clientScopes);
                    $error = $justLocked
                        ? 'Too many failed attempts. Wait five minutes, then try again.'
                        : 'Account not found. Enter a valid username or email.';
                } else {
                    $recoveryScopes = admin_auth_scopes((int) $user['id']);
                    if (admin_auth_is_throttled($pdo, $recoveryScopes)) {
                        $error = 'Too many failed attempts. Wait five minutes, then try again.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['password_change_flow'] = [
                            'flow_id' => bin2hex(random_bytes(16)),
                            'stage' => 'old_password',
                            'user_id' => (int) $user['id'],
                            'username' => (string) $user['username'],
                            'attempts' => 0,
                            'expires_at' => time() + ACCOUNT_RECOVERY_FLOW_TTL_SECONDS,
                        ];
                        unset($_SESSION['csrf_token']);
                        redirect('forgot-password.php');
                    }
                }
            }
        } elseif ($action === 'verify_old_password') {
            $flow = forgot_password_flow();
            $postedFlowId = (string) ($_POST['flow_id'] ?? '');
            if (($flow['stage'] ?? '') !== 'old_password'
                || !hash_equals((string) ($flow['flow_id'] ?? ''), $postedFlowId)
            ) {
                $error = 'Account verification expired. Start again.';
            } else {
                $oldPassword = (string) ($_POST['old_password'] ?? '');
                $credentials = account_recovery_credentials($pdo, (int) $flow['user_id']);
                $recoveryScopes = admin_auth_scopes((int) $flow['user_id']);
                if (!$credentials) {
                    forgot_password_clear_flow();
                    $error = 'The account no longer exists. Start again.';
                } elseif (admin_auth_is_throttled($pdo, $recoveryScopes)) {
                    forgot_password_clear_flow();
                    $error = 'Too many failed attempts. Wait five minutes, then try again.';
                } elseif (!ucchr_password_hash_is_valid((string) ($credentials['password_hash'] ?? ''))) {
                    forgot_password_clear_flow();
                    error_log('UCCHR recovery blocked because user #' . (int) $credentials['id'] . ' has an invalid password hash.');
                    $error = 'This account password record needs administrator repair.';
                } elseif (!ucchr_password_verify($oldPassword, (string) $credentials['password_hash'])) {
                    $persistentLock = admin_auth_record_failure($pdo, $recoveryScopes);
                    $attempts = (int) ($flow['attempts'] ?? 0) + 1;
                    if ($persistentLock || $attempts >= ACCOUNT_RECOVERY_MAX_OLD_PASSWORD_ATTEMPTS) {
                        forgot_password_clear_flow();
                        $error = 'Too many failed attempts. Wait five minutes, then try again.';
                    } else {
                        $flow['attempts'] = $attempts;
                        $_SESSION['password_change_flow'] = $flow;
                        $remaining = ACCOUNT_RECOVERY_MAX_OLD_PASSWORD_ATTEMPTS - $attempts;
                        $error = 'Old password is incorrect. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' remaining.';
                    }
                } else {
                    admin_auth_clear_failures($pdo, $recoveryScopes);
                    session_regenerate_id(true);
                    $_SESSION['password_change_flow'] = [
                        'flow_id' => (string) $flow['flow_id'],
                        'stage' => 'new_password',
                        'user_id' => (int) $credentials['id'],
                        'username' => (string) $credentials['username'],
                        'password_fingerprint' => account_recovery_password_fingerprint((string) $credentials['password_hash']),
                        'expires_at' => time() + intdiv(ACCOUNT_RECOVERY_FLOW_TTL_SECONDS, 2),
                    ];
                    unset($_SESSION['csrf_token']);
                    redirect('forgot-password.php');
                }
            }
        } elseif ($action === 'update_password') {
            $flow = forgot_password_flow();
            $postedFlowId = (string) ($_POST['flow_id'] ?? '');
            if (($flow['stage'] ?? '') !== 'new_password'
                || !hash_equals((string) ($flow['flow_id'] ?? ''), $postedFlowId)
            ) {
                $error = 'Password verification expired. Start again.';
            } else {
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmation = (string) ($_POST['password_confirmation'] ?? '');
                if (strlen($newPassword) < 8) {
                    $error = 'The new password must contain at least 8 characters.';
                } elseif (strlen($newPassword) > 72) {
                    $error = 'The new password must not exceed 72 characters.';
                } elseif ($newPassword !== $confirmation) {
                    $error = 'The new passwords do not match.';
                } else {
                    $updated = account_recovery_update_verified_password(
                        $pdo,
                        (int) $flow['user_id'],
                        (string) ($flow['password_fingerprint'] ?? ''),
                        $newPassword
                    );
                    if (!$updated) {
                        forgot_password_clear_flow();
                        $error = 'The account password changed before this request completed. Start again.';
                    } else {
                        forgot_password_clear_flow();
                        session_regenerate_id(true);
                        unset($_SESSION['csrf_token']);
                        set_flash('success', 'Password changed successfully. Log in with your new password.');
                        redirect('index.php');
                    }
                }
            }
        } else {
            forgot_password_clear_flow();
            $error = 'Invalid recovery request. Start again.';
        }
    } catch (DomainException $exception) {
        $error = $exception->getMessage() === 'NEW_PASSWORD_MATCHES_CURRENT'
            ? 'Choose a new password that is different from the old password.'
            : 'The password could not be changed.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('UCCHR verified password change failed: ' . $exception->getMessage());
        $error = 'The password could not be changed. Please start again.';
    }
}

$flow = forgot_password_flow();
$stage = (string) ($flow['stage'] ?? 'account');
$accountUsername = (string) ($flow['username'] ?? '');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#a8172d">
    <?= pwa_head_tags() ?>
    <title>Forgot Password | UCCHR</title>
    <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>

<body class="login-page">
    <div class="login-stage">
        <section class="login-card recovery-card">
            <img class="site-logo" src="assets/images/ucclogo.jpg" alt="UCC Logo">
            <div class="login-title"><strong>UCCHR</strong><span>Account Recovery</span></div>
            <?php if ($stage === 'account'): ?>
                <p class="login-kicker"><?= ui_icon('mail', 'section-icon') ?>Enter the registered username or email.</p>
            <?php elseif ($stage === 'old_password'): ?>
                <p class="login-kicker"><?= ui_icon('shield', 'section-icon') ?>Account verified: <?= e($accountUsername) ?></p>
            <?php else: ?>
                <p class="login-kicker"><?= ui_icon('key', 'section-icon') ?>Old password verified. Enter a new password.</p>
            <?php endif; ?>
            <?php if ($error): ?><div class="login-error"><?= e($error) ?></div><?php endif; ?>

            <?php if ($stage === 'account'): ?>
                <form method="post" class="login-form" data-submit-lock>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="verify_account">
                    <label>Username or Email<input name="identifier" value="<?= e($identifier) ?>" maxlength="160" autocomplete="username" placeholder="Username or email" required autofocus></label>
                    <button class="btn btn-neon" type="submit" data-submit-label="Verifying account…"><?= ui_icon('search', 'button-icon') ?>Verify Account</button>
                </form>
                <div class="auth-links"><a href="index.php"><?= ui_icon('arrow-left') ?>Back to login</a></div>
            <?php elseif ($stage === 'old_password'): ?>
                <form method="post" class="login-form" data-submit-lock>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="verify_old_password">
                    <input type="hidden" name="flow_id" value="<?= e((string) $flow['flow_id']) ?>">
                    <label>Old Password<input type="password" name="old_password" autocomplete="current-password" placeholder="Enter your current password" required autofocus></label>
                    <button class="btn btn-neon" type="submit" data-submit-label="Checking password…"><?= ui_icon('shield', 'button-icon') ?>Verify Old Password</button>
                </form>
                <div class="auth-links">
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="restart"><button class="auth-link-button" type="submit"><?= ui_icon('refresh') ?>Use another account</button></form>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><button class="auth-link-button" type="submit"><?= ui_icon('arrow-left') ?>Back to login</button></form>
                </div>
            <?php else: ?>
                <form method="post" class="login-form" data-submit-lock>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_password">
                    <input type="hidden" name="flow_id" value="<?= e((string) $flow['flow_id']) ?>">
                    <label>New Password<input type="password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="8 to 72 characters" required autofocus></label>
                    <label>Confirm New Password<input type="password" name="password_confirmation" minlength="8" maxlength="72" autocomplete="new-password" placeholder="Enter the same password" required></label>
                    <button class="btn btn-neon" type="submit" data-submit-label="Updating password…"><?= ui_icon('key', 'button-icon') ?>Update Password</button>
                </form>
                <div class="auth-links">
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="restart"><button class="auth-link-button" type="submit"><?= ui_icon('refresh') ?>Start again</button></form>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><button class="auth-link-button" type="submit"><?= ui_icon('arrow-left') ?>Back to login</button></form>
                </div>
            <?php endif; ?>
            <?= pwa_install_button('Install App', 'pwa-login-install') ?>
        </section>
    </div>
    <script src="assets/js/app.js" defer></script>
    <script src="assets/js/pwa.js" defer></script>
</body>

</html>
