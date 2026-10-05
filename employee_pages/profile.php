<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}

$middleName = trim((string) ($employee['middle_name'] ?? ''));
$fullName = trim(
    (string) $employee['first_name'] . ' ' .
        ($middleName !== '' ? $middleName . ' ' : '') .
        (string) $employee['last_name']
);
$initials = strtoupper(substr((string) $employee['first_name'], 0, 1) . substr((string) $employee['last_name'], 0, 1));
$birthDate = !empty($employee['date_of_birth'])
    ? date('F j, Y', strtotime((string) $employee['date_of_birth']))
    : 'Not provided';
$fingerprintStatus = (string) ($employee['fingerprint_status'] ?? 'Not enrolled');
$profilePayType = in_array((string) ($employee['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true)
    ? (string) $employee['pay_type']
    : 'Daily';
$profileRate = (float) ($employee['basic_rate'] ?? 0);
$profileEstimate = ['amount' => 0.0, 'scheduled_days' => 0, 'scheduled_minutes' => 0];
if ($profileRate > 0) {
    try {
        $profileEstimate = payroll_expected_monthly_estimate(
            $pdo,
            (int) $employee['employee_id'],
            $profilePayType,
            $profileRate,
            new DateTimeImmutable('first day of this month')
        );
    } catch (Throwable) {
        // Keep the view-only portal available while HR repairs compensation setup.
    }
}
$profileRateUnit = $profilePayType === 'Hourly' ? 'hour' : ($profilePayType === 'Monthly' ? 'month' : 'day');
$profileEstimateDetail = match ($profilePayType) {
    'Hourly' => (int) $profileEstimate['scheduled_minutes'] > 0
        ? number_format((int) $profileEstimate['scheduled_minutes'] / 60, 2) . ' scheduled hours × hourly rate'
        : 'No scheduled hours this month',
    'Monthly' => 'configured monthly rate; schedule controls adjustments',
    default => (int) $profileEstimate['scheduled_days'] > 0
        ? (int) $profileEstimate['scheduled_days'] . ' scheduled workday' . ((int) $profileEstimate['scheduled_days'] === 1 ? '' : 's') . ' × daily rate'
        : 'No scheduled workdays this month',
};
?>
<section class="profile-grid employee-profile-page">
    <article class="panel profile-banner">
        <span class="avatar avatar-xl avatar-soft"><?= e($initials ?: 'EP') ?></span>
        <div>
            <h2><?= e($fullName) ?></h2>
            <p><?= e($employee['employee_no']) ?> · <?= e((string) ($employee['department'] ?: 'No department')) ?></p>
            <span class="badge <?= $employee['employment_status'] === 'Active' ? 'badge-green' : 'badge-amber' ?>"><?= e((string) $employee['employment_status']) ?></span>
        </div>
        <span class="badge badge-blue">View-only profile</span>
    </article>

    <article class="panel account-card">
        <h2><?= ui_icon('id-card', 'heading-icon') ?>Employee Information</h2>
        <ul class="status-list">
            <li><span>Employee number</span><strong><?= e($employee['employee_no']) ?></strong></li>
            <li><span>Full name</span><strong><?= e($fullName) ?></strong></li>
            <li><span>Date of birth</span><strong><?= e($birthDate) ?></strong></li>
            <li><span>Gender</span><strong><?= e((string) ($employee['gender'] ?: 'Not provided')) ?></strong></li>
            <li><span>Civil status</span><strong><?= e((string) ($employee['civil_status'] ?: 'Not provided')) ?></strong></li>
        </ul>
    </article>

    <article class="panel account-card">
        <h2><?= ui_icon('payroll', 'heading-icon') ?>Work Information</h2>
        <ul class="status-list">
            <li><span>Department</span><strong><?= e((string) ($employee['department'] ?: 'Not assigned')) ?></strong></li>
            <li><span>Position</span><strong><?= e((string) ($employee['position'] ?: 'Not assigned')) ?></strong></li>
            <li><span>Employment status</span><strong><?= e((string) $employee['employment_status']) ?></strong></li>
            <li><span>Employment type</span><strong><?= e((string) ($employee['employment_type'] ?: 'Pending HR confirmation')) ?></strong></li>
            <li><span>Fingerprint</span><strong class="<?= $fingerprintStatus === 'Enrolled' ? 'text-green' : 'text-red' ?>"><?= e($fingerprintStatus) ?></strong></li>
            <li><span>Pay type</span><strong><?= e($profilePayType) ?></strong></li>
            <li><span>Approved rate</span><strong><?= e(money($profileRate)) ?> <small>per <?= e($profileRateUnit) ?></small></strong></li>
            <li><span>Expected monthly salary</span><strong><?= e(money($profileEstimate['amount'])) ?> <small>(<?= e($profileEstimateDetail) ?>)</small></strong></li>
        </ul>
    </article>

    <article class="panel account-card">
        <h2><?= ui_icon('mail', 'heading-icon') ?>Contact Information</h2>
        <ul class="status-list">
            <li><span>Email</span><strong><?= e((string) ($employee['email'] ?: 'Not provided')) ?></strong></li>
            <li><span>Contact number</span><strong><?= e((string) ($employee['contact_number'] ?: 'Not provided')) ?></strong></li>
        </ul>
        <div class="note-box">Ask HR/Admin to correct employee or contact information. This portal cannot modify the official employee record.</div>
    </article>

    <article class="panel security-card">
        <h2><?= ui_icon('shield', 'heading-icon') ?>Portal Account</h2>
        <ul class="status-list">
            <li><span>Login username</span><strong><?= e((string) $employee['username']) ?></strong></li>
            <li><span>Account status</span><strong class="text-green"><?= e((string) $employee['account_status']) ?></strong></li>
            <li><span>Access level</span><strong>Employee · View only</strong></li>
        </ul>
        <a class="btn btn-outline" href="employee-portal.php?page=security"><?= ui_icon('key', 'button-icon') ?>Change my password</a>
    </article>
</section>