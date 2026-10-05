<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}
?>
<section class="profile-grid employee-security-page">
    <article class="panel profile-banner">
        <span class="avatar avatar-xl avatar-soft avatar-icon"><?= ui_icon('shield') ?></span>
        <div>
            <h2>Password &amp; Security</h2>
            <p><?= e($employee['username']) ?> · <?= e($employee['employee_no']) ?></p>
            <span class="badge badge-green">Account <?= e((string) $employee['account_status']) ?></span>
        </div>
    </article>

    <article class="panel security-card">
        <h2><?= ui_icon('key', 'heading-icon') ?>Change My Password</h2>
        <?php if (!empty($employee['must_change_password'])): ?>
            <div class="activation-note"><strong>Password update required.</strong> Create a private password before continuing to use the portal.</div>
        <?php endif; ?>
        <form method="post" action="employee-portal.php?page=security" data-submit-lock>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_employee_password">
            <label>Current Password
                <input type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <label>New Password
                <input type="password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" required>
            </label>
            <label>Confirm New Password
                <input type="password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required>
            </label>
            <button class="btn btn-primary" type="submit" data-submit-label="Updating password…"><?= ui_icon('shield', 'button-icon') ?>Update password</button>
        </form>
    </article>

    <article class="panel account-card">
        <h2><?= ui_icon('shield', 'heading-icon') ?>Account Protection</h2>
        <ul class="status-list">
            <li><span>Access</span><strong>Personal records only</strong></li>
            <li><span>Business data</span><strong>View only</strong></li>
            <li><span>Employee</span><strong><?= e($employee['employee_no']) ?></strong></li>
        </ul>
        <div class="note-box">
            Use at least 8 characters and do not share your password. If you cannot sign in, ask HR/Admin for a secure employee password-reset link.
        </div>
    </article>
</section>