<?php
$settingRows = $pdo->query('SELECT `key`,`value` FROM settings')->fetchAll();
$settings = [];
foreach ($settingRows as $row) {
    $settings[$row['key']] = $row['value'];
}

$device = null;
try {
    $device = $pdo->query('SELECT * FROM device_status ORDER BY last_seen DESC LIMIT 1')->fetch() ?: null;
} catch (Throwable) {
}
$deviceOnline = $device && strtotime((string) $device['last_seen']) >= time() - 20;
?>
<section class="two-column settings-layout">
    <article class="panel standard-panel">
        <h2><?= ui_icon('settings', 'heading-icon') ?>General Settings</h2>
        <form method="post" class="stack-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_settings">
            <label>Institution Name<input name="institution_name" value="<?= e($settings['institution_name'] ?? 'Ubay Community College') ?>"></label>
            <label>Payroll Day<input type="number" min="1" max="31" name="payroll_day" value="<?= e($settings['payroll_day'] ?? '30') ?>"></label>
            <label>Late Grace Period (minutes)<input type="number" min="0" name="grace_minutes" value="<?= e($settings['grace_minutes'] ?? '15') ?>"></label>
            <label>Timezone<select name="timezone">
                    <option value="Asia/Manila" selected>Asia/Manila (PHT)</option>
                    <option value="UTC">UTC</option>
                </select></label>
            <button class="btn btn-primary" type="submit"><?= ui_icon('save', 'button-icon') ?>Save settings</button>
        </form>
    </article>
    <article class="panel standard-panel">
        <h2><?= ui_icon('activity', 'heading-icon') ?>System Status</h2>
        <ul class="status-list">
            <li><span><?= ui_icon('activity', 'section-icon') ?>Database</span><strong class="status-value text-green"><?= ui_icon('check-circle', 'section-icon') ?>Connected</strong></li>
            <li><span><?= ui_icon('fingerprint', 'section-icon') ?>Biometric terminal</span><strong class="status-value <?= $deviceOnline ? 'text-green' : 'text-red' ?>"><?= ui_icon($deviceOnline ? 'check-circle' : 'warning', 'section-icon') ?><?= $deviceOnline ? 'Online' : 'Offline' ?></strong></li>
            <?php if ($device): ?>
                <li><span>Device ID</span><strong><?= e($device['device_id']) ?></strong></li>
                <li><span>Device IP</span><strong><?= e($device['ip_address'] ?: 'Unknown') ?></strong></li>
                <li><span>Last heartbeat</span><strong><?= date('M j, g:i:s A', strtotime((string) $device['last_seen'])) ?></strong></li>
            <?php endif; ?>
            <li><span>PHP runtime</span><strong><?= e(PHP_VERSION) ?></strong></li>
            <li><span>Current timezone</span><strong><?= e(date_default_timezone_get()) ?></strong></li>
        </ul>
        <div class="note-box">The ESP32 is online while it sends authenticated command polls at least once every 20 seconds. Start PHP with <code>-S 0.0.0.0:8080</code> so devices on the same Wi-Fi can reach it.</div>
    </article>
</section>