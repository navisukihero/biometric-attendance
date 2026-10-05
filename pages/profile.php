<?php
$user = current_user($pdo);
$stmt = $pdo->prepare('SELECT * FROM activity_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 8');
$stmt->execute([$_SESSION['user_id']]);
$activity = $stmt->fetchAll();
?>
<section class="profile-grid">
    <article class="panel profile-banner">
        <span class="avatar avatar-xl avatar-soft">HR</span>
        <div>
            <h2><?= e($user['full_name']) ?></h2>
            <p><?= e($user['email']) ?></p>
            <span class="badge badge-blue"><?= ui_icon('shield') ?><?= e($user['role']) ?></span>
        </div>
        <button class="btn btn-outline" type="button" onclick="alert('Photo upload can be connected to your server storage.')"><?= ui_icon('camera', 'button-icon') ?>Change photo</button>
    </article>
    <article class="panel account-card">
        <h2><?= ui_icon('profile', 'heading-icon') ?>Account Information</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_profile">
            <label>Full Name<input name="full_name" value="<?= e($user['full_name']) ?>" required></label>
            <label>Username<input name="username" value="<?= e($user['username']) ?>" minlength="3" maxlength="80" pattern="[A-Za-z0-9._-]+" autocomplete="username" required></label>
            <label>Email<input type="email" name="email" value="<?= e($user['email']) ?>" required></label>
            <label>Current Password <small>(required to save)</small><input type="password" name="current_password" autocomplete="current-password" required></label>
            <button class="btn btn-primary" type="submit"><?= ui_icon('save', 'button-icon') ?>Save changes</button>
        </form>
    </article>
    <article class="panel security-card">
        <h2><?= ui_icon('shield', 'heading-icon') ?>Security</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_password">
            <label>Current Password<input type="password" name="current_password" autocomplete="current-password" required></label>
            <label>New Password<input type="password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
            <label>Confirm New Password<input type="password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
            <button class="btn btn-outline" type="submit"><?= ui_icon('key', 'button-icon') ?>Update Password</button>
        </form>
    </article>
    <article class="panel activity-card">
        <h2><?= ui_icon('audit', 'heading-icon') ?>Recent Activity</h2>
        <div class="table-scroll">
            <table class="data-table compact">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Date &amp; Time</th>
                    </tr>
                </thead>
                <tbody><?php foreach ($activity as $item): ?><tr>
                            <td><strong><?= e($item['action']) ?></strong></td>
                            <td><?= date('M j, Y, g:i A', strtotime($item['created_at'])) ?></td>
                        </tr><?php endforeach; ?><?php if (!$activity): ?><tr>
                            <td colspan="2" class="empty-state">No activity recorded yet.</td>
                        </tr><?php endif; ?></tbody>
            </table>
        </div>
    </article>
</section>