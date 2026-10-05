<?php

declare(strict_types=1);

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(404);
    exit('Not found.');
}

$payrollSettingKeys = [
    'regular_hours_per_day',
    'grace_minutes',
    'break_duration',
    'late_deduction_enabled',
    'undertime_deduction_enabled',
    'half_day_minimum_percent',
    'full_day_minimum_percent',
    'overtime_enabled',
    'overtime_requires_approval',
    'employee_overtime_requests_enabled',
    'overtime_multiplier',
    'regular_holiday_worked_multiplier',
    'regular_holiday_overtime_multiplier',
    'special_day_worked_multiplier',
    'special_day_overtime_multiplier',
    'rest_day_multiplier',
    'regular_holiday_rest_day_multiplier',
    'special_day_rest_day_multiplier',
    'payroll_frequency',
    'rounding_rule',
    'currency',
];

$defaultPayrollSettings = [
    'regular_hours_per_day' => '8',
    'grace_minutes' => '15',
    'break_duration' => '60',
    'late_deduction_enabled' => '1',
    'undertime_deduction_enabled' => '1',
    'half_day_minimum_percent' => '50',
    'full_day_minimum_percent' => '75',
    'overtime_enabled' => '1',
    'overtime_requires_approval' => '1',
    'employee_overtime_requests_enabled' => '1',
    'overtime_multiplier' => '1.25',
    'regular_holiday_worked_multiplier' => '1.00',
    'regular_holiday_overtime_multiplier' => '1.00',
    'special_day_worked_multiplier' => '1.00',
    'special_day_overtime_multiplier' => '1.00',
    'rest_day_multiplier' => '1.00',
    'regular_holiday_rest_day_multiplier' => '1.00',
    'special_day_rest_day_multiplier' => '1.00',
    'payroll_frequency' => 'Semi-monthly',
    'rounding_rule' => 'nearest_cent',
    'currency' => 'PHP',
];

$payrollSettings = $defaultPayrollSettings;
$payrollSettingsReady = true;
try {
    $placeholders = implode(',', array_fill(0, count($payrollSettingKeys), '?'));
    $settingsStmt = $pdo->prepare(
        'SELECT `key`, `value` FROM settings WHERE `key` IN (' . $placeholders . ')'
    );
    $settingsStmt->execute($payrollSettingKeys);
    foreach ($settingsStmt->fetchAll() as $settingRow) {
        $settingKey = (string) $settingRow['key'];
        if (array_key_exists($settingKey, $payrollSettings)) {
            $payrollSettings[$settingKey] = (string) $settingRow['value'];
        }
    }
} catch (Throwable) {
    $payrollSettingsReady = false;
}

$settingValue = static fn(string $key): string => (string) ($payrollSettings[$key] ?? '');
$settingChecked = static fn(string $key): bool => (string) ($payrollSettings[$key] ?? '0') === '1';
?>
<section class="schedule-page payroll-settings-page">
    <form method="post" class="stack-form" data-submit-lock>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_payroll_settings">

        <article class="panel standard-panel payroll-settings-intro">
            <div class="panel-title-row attendance-heading">
                <div>
                    <h2><?= ui_icon('payroll-settings', 'heading-icon') ?>Payroll Settings</h2>
                    <p class="muted">Central rules used by processed attendance and future payroll calculations.</p>
                </div>
                <span class="badge badge-amber">HR approval required</span>
            </div>
            <?php if (!$payrollSettingsReady): ?>
                <div class="note-box">Payroll settings could not be loaded. Verify the database migration before saving.</div>
            <?php endif; ?>
        </article>

        <article class="panel standard-panel payroll-settings-panel">
            <h2><?= ui_icon('schedule', 'heading-icon') ?>Work &amp; Time Basis</h2>
            <div class="payroll-settings-fields">
                <label>Regular hours per day<input type="number" name="regular_hours_per_day" min="1" max="24" step="0.25" value="<?= e($settingValue('regular_hours_per_day')) ?>" required></label>
                <label>Late grace period (minutes)<input type="number" name="grace_minutes" min="0" max="240" value="<?= e($settingValue('grace_minutes')) ?>" required></label>
                <label>Default break duration (minutes)<input type="number" name="break_duration" min="0" max="480" value="<?= e($settingValue('break_duration')) ?>" required></label>
                <label>Salary time basis<input value="Assigned Work Schedule" disabled><small>Hourly pay uses scheduled hours; daily pay uses scheduled days. Unpaid attendance is deducted separately.</small></label>
                <label>Half-day minimum scheduled work (%)<input type="number" name="half_day_minimum_percent" min="1" max="99" step="1" value="<?= e($settingValue('half_day_minimum_percent')) ?>" required><small>Below this percentage, a completed scan is classified Absent.</small></label>
                <label>Full-day minimum scheduled work (%)<input type="number" name="full_day_minimum_percent" min="2" max="100" step="1" value="<?= e($settingValue('full_day_minimum_percent')) ?>" required><small>Between the two thresholds, attendance is classified Half-Day.</small></label>
            </div>
        </article>

        <article class="panel standard-panel payroll-settings-panel">
            <h2><?= ui_icon('payroll', 'heading-icon') ?>Payroll Options</h2>
            <div class="payroll-settings-fields">
                <label>Payroll frequency
                    <select name="payroll_frequency" required>
                        <?php foreach (['Weekly', 'Bi-weekly', 'Semi-monthly', 'Monthly'] as $frequency): ?>
                            <option value="<?= e($frequency) ?>" <?= $settingValue('payroll_frequency') === $frequency ? 'selected' : '' ?>><?= e($frequency) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Rounding rule
                    <select name="rounding_rule" required>
                        <option value="nearest_cent" <?= $settingValue('rounding_rule') === 'nearest_cent' ? 'selected' : '' ?>>Nearest centavo</option>
                        <option value="nearest_peso" <?= $settingValue('rounding_rule') === 'nearest_peso' ? 'selected' : '' ?>>Nearest peso</option>
                        <option value="truncate_cent" <?= $settingValue('rounding_rule') === 'truncate_cent' ? 'selected' : '' ?>>Truncate to centavo</option>
                    </select>
                </label>
                <label>Currency<input name="currency" maxlength="3" pattern="[A-Za-z]{3}" value="<?= e($settingValue('currency')) ?>" required></label>
            </div>
        </article>

        <article class="panel standard-panel payroll-settings-panel">
            <h2><?= ui_icon('overtime', 'heading-icon') ?>Deductions &amp; Overtime Rules</h2>
            <div class="payroll-settings-toggles">
                <?php foreach (
                    [
                        'late_deduction_enabled' => 'Apply late deductions',
                        'undertime_deduction_enabled' => 'Apply undertime deductions',
                        'overtime_enabled' => 'Enable overtime processing',
                        'employee_overtime_requests_enabled' => 'Allow employee overtime requests',
                    ] as $key => $label
                ): ?>
                    <label class="payroll-settings-toggle">
                        <input type="hidden" name="<?= e($key) ?>" value="0">
                        <input type="checkbox" name="<?= e($key) ?>" value="1" <?= $settingChecked($key) ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="payroll-settings-multiplier">
                <label>Regular overtime multiplier<input type="number" name="overtime_multiplier" min="0" max="10" step="0.01" value="<?= e($settingValue('overtime_multiplier')) ?>" required></label>
            </div>
        </article>

        <article class="panel standard-panel payroll-settings-panel">
            <h2><?= ui_icon('holiday', 'heading-icon') ?>Holiday &amp; Rest-Day Multipliers</h2>
            <div class="payroll-settings-fields">
                <label>Regular holiday worked<input type="number" name="regular_holiday_worked_multiplier" min="0" max="10" step="0.01" value="<?= e($settingValue('regular_holiday_worked_multiplier')) ?>" required></label>
                <label>Regular holiday overtime<input type="number" name="regular_holiday_overtime_multiplier" min="0" max="10" step="0.01" value="<?= e($settingValue('regular_holiday_overtime_multiplier')) ?>" required></label>
                <label>Special day worked<input type="number" name="special_day_worked_multiplier" min="0" max="10" step="0.01" value="<?= e($settingValue('special_day_worked_multiplier')) ?>" required></label>
                <label>Special day overtime<input type="number" name="special_day_overtime_multiplier" min="0" max="10" step="0.01" value="<?= e($settingValue('special_day_overtime_multiplier')) ?>" required></label>
                <label>Rest day worked<input type="number" name="rest_day_multiplier" min="0" max="10" step="0.01" value="<?= e($settingValue('rest_day_multiplier')) ?>" required></label>
                <label>Regular holiday + rest day<input type="number" name="regular_holiday_rest_day_multiplier" min="0" max="10" step="0.01" value="<?= e($settingValue('regular_holiday_rest_day_multiplier')) ?>" required></label>
                <label>Special day + rest day<input type="number" name="special_day_rest_day_multiplier" min="0" max="10" step="0.01" value="<?= e($settingValue('special_day_rest_day_multiplier')) ?>" required></label>
            </div>
        </article>

        <div class="button-row payroll-settings-actions">
            <button class="btn btn-primary btn-large" type="submit" <?= !$payrollSettingsReady ? 'disabled' : '' ?>><?= ui_icon('save', 'button-icon') ?>Save payroll policies</button>
            <a class="btn btn-outline" href="app.php?page=payroll"><?= ui_icon('arrow-left', 'button-icon') ?>Back to Payroll</a>
        </div>
    </form>
</section>
