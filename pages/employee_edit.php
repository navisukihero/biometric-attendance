<?php
$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT e.*, d.name department,
            fr.fingerprint_slot AS mapped_fingerprint_slot,
            fr.mapping_status AS fingerprint_mapping_status,
            fr.enrollment_version AS fingerprint_mapping_version,
            fr.device_id AS fingerprint_device_id,
            fr.enrolled_at AS fingerprint_enrolled_at
     FROM employees e
     LEFT JOIN departments d ON d.id=e.department_id
     LEFT JOIN fingerprint_registrations fr ON fr.employee_id=e.id
     WHERE e.id=?'
);
$stmt->execute([$id]);
$employee = $stmt->fetch();
if (!$employee) {
    echo '<article class="panel empty-state">Employee not found.</article>';
    return;
}
$enrollmentStatus = fingerprint_enrollment_status($pdo, $id);
$enrollmentActive = (bool) ($enrollmentStatus['active'] ?? false);
$requiredFingerprintTemplateCount = 5;
$fingerprintTemplateCount = 0;
$fingerprintTemplateCountKnown = false;
try {
    $templateCountStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT position)
         FROM fingerprint_template_slots
         WHERE employee_id=?
           AND mapping_status='Enrolled'
           AND position IN ('CENTER', 'LEFT', 'RIGHT', 'UPPER', 'LOWER')"
    );
    $templateCountStmt->execute([$id]);
    $fingerprintTemplateCount = min(
        $requiredFingerprintTemplateCount,
        max(0, (int) $templateCountStmt->fetchColumn())
    );
    $fingerprintTemplateCountKnown = true;
} catch (Throwable) {
    // Keep this edit page usable while the five-template migration is being repaired.
}
$fingerprintMarkedEnrolled = ($employee['fingerprint_mapping_status'] ?? '') === 'Enrolled'
    && $employee['fingerprint_status'] === 'Enrolled';
$fingerprintProfileComplete = $fingerprintMarkedEnrolled
    && $fingerprintTemplateCountKnown
    && $fingerprintTemplateCount === $requiredFingerprintTemplateCount;
$fingerprintRecoveryRequired = $fingerprintMarkedEnrolled
    && $fingerprintTemplateCountKnown
    && !$fingerprintProfileComplete;
$fingerprintActive = $fingerprintTemplateCountKnown
    ? $fingerprintProfileComplete
    : $fingerprintMarkedEnrolled;
$fingerprintBadgeText = $fingerprintRecoveryRequired
    ? 'Profile recovery · ' . $fingerprintTemplateCount . '/5'
    : ($fingerprintProfileComplete
        ? 'Enrolled · 5/5'
        : (string) $employee['fingerprint_status'] . ($fingerprintTemplateCountKnown ? ' · ' . $fingerprintTemplateCount . '/5' : ''));
$fingerprintDisplayPhase = (string) ($enrollmentStatus['phase'] ?? 'Not Enrolled');
$fingerprintDisplayMessage = (string) ($enrollmentStatus['message'] ?? 'Queue enrollment when the employee and terminal are ready.');
if ($fingerprintRecoveryRequired && !$enrollmentActive) {
    $fingerprintDisplayPhase = 'Profile recovery required';
    $fingerprintDisplayMessage = 'Only ' . $fingerprintTemplateCount
        . ' of 5 enrolled AS608 position mappings are safely linked. Use Controlled Re-enroll with the employee present; never guess or restore missing sensor slots manually.';
}
$departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();
$expectedSalaryRate = (float) (($employee['basic_rate'] ?? 0) > 0
    ? $employee['basic_rate']
    : ($employee['daily_rate'] ?? 0));
$expectedSalaryPayType = in_array((string) ($employee['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true)
    ? (string) $employee['pay_type']
    : 'Daily';
$requiresPayTypeConversion = !in_array($expectedSalaryPayType, ['Daily', 'Hourly'], true);
$expectedSalaryEstimate = ['amount' => 0.0, 'scheduled_days' => 0, 'scheduled_minutes' => 0, 'sources' => []];
if ($expectedSalaryRate > 0) {
    try {
        $expectedSalaryEstimate = payroll_expected_monthly_estimate(
            $pdo,
            $id,
            $expectedSalaryPayType,
            $expectedSalaryRate,
            new DateTimeImmutable('first day of this month')
        );
    } catch (Throwable) {
        // Keep an incomplete legacy employee editable while HR repairs its rate/schedule.
    }
}
$expectedSalaryAmount = (float) $expectedSalaryEstimate['amount'];
$expectedScheduledDays = (int) $expectedSalaryEstimate['scheduled_days'];
$expectedScheduledMinutes = (int) $expectedSalaryEstimate['scheduled_minutes'];
$expectedSalaryFormula = match ($expectedSalaryPayType) {
    'Hourly' => $expectedScheduledMinutes > 0
        ? number_format($expectedScheduledMinutes / 60, 2) . ' scheduled hours × ' . money($expectedSalaryRate) . '/hour'
        : 'Assign a Work Schedule to calculate the hourly monthly estimate',
    'Monthly' => money($expectedSalaryRate) . ' configured monthly rate; schedule controls adjustments',
    default => $expectedScheduledDays > 0
        ? $expectedScheduledDays . ' scheduled workday' . ($expectedScheduledDays === 1 ? '' : 's') . ' × ' . money($expectedSalaryRate) . '/day'
        : 'Assign a Work Schedule to calculate the daily monthly estimate',
};
$accountStmt = $pdo->prepare('SELECT * FROM employee_accounts WHERE employee_id=? LIMIT 1');
$accountStmt->execute([$id]);
$portalAccount = $accountStmt->fetch() ?: [];
$portalHasPassword = ucchr_password_hash_is_valid((string) ($portalAccount['password_hash'] ?? ''));
$portalStatus = (string) ($portalAccount['account_status'] ?? 'Pending');
$portalLink = null;
if (isset($_SESSION['employee_portal_link'])) {
    $pendingLink = (array) $_SESSION['employee_portal_link'];
    unset($_SESSION['employee_portal_link']);
    if ((int) ($pendingLink['employee_id'] ?? 0) === $id) {
        $portalLink = $pendingLink;
    }
}
?>
<article class="panel edit-panel">
    <div class="edit-title-row">
        <h2><?= ui_icon('edit', 'heading-icon') ?>Edit Employee</h2>
        <div class="button-row"><?php if ($employee['status'] === 'Active'): ?><a class="btn btn-outline" href="app.php?page=schedule&amp;employee_id=<?= $id ?>#schedule-assignment"><?= ui_icon('schedule', 'button-icon') ?>Assign Work Schedule</a><?php endif; ?><form method="post" data-submit-lock><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="enroll_fingerprint"><input type="hidden" name="id" value="<?= $id ?>"><button id="fingerprint-sync-button" class="btn btn-outline" type="submit" <?= $enrollmentActive ? 'disabled' : '' ?>><?= ui_icon('fingerprint', 'button-icon') ?><span><?= $enrollmentActive ? 'Enrollment in progress' : ($fingerprintRecoveryRequired ? 'Controlled re-enroll' : ($fingerprintActive ? 'Sync / Re-enroll' : 'Sync / Enroll')) ?> fingerprint</span></button></form>
        </div>
    </div>
    <div class="employee-identity"><span class="avatar avatar-large avatar-1"><?= e(initials($employee['first_name'], $employee['last_name'])) ?></span><span><strong><?= e(substr($employee['first_name'], 0, 1) . '. ' . $employee['last_name']) ?></strong><small><?= e($employee['employee_no']) ?></small></span><span id="fingerprint-employee-badge" class="badge <?= $fingerprintActive ? 'badge-green' : 'badge-amber' ?>"><?= e($fingerprintBadgeText) ?></span></div>
    <div
        id="fingerprint-sync-status"
        class="<?= $fingerprintActive && !$enrollmentActive ? 'activation-note' : 'note-box onboarding-note' ?>"
        data-employee-id="<?= $id ?>"
        data-active="<?= $enrollmentActive ? '1' : '0' ?>"
        data-template-count="<?= $fingerprintTemplateCount ?>"
        data-required-template-count="<?= $requiredFingerprintTemplateCount ?>"
        role="status"
        aria-live="polite">
        <strong id="fingerprint-sync-phase">Fingerprint: <?= e($fingerprintDisplayPhase) ?>.</strong>
        <span id="fingerprint-sync-message"> <?= e($fingerprintDisplayMessage) ?></span>
        <br><small id="fingerprint-sync-meta">Employee <?= e($employee['employee_no']) ?> · center AS608 slot <?= (int) ($enrollmentStatus['slot'] ?? $employee['mapped_fingerprint_slot'] ?? $employee['fingerprint_code']) ?> · enrolled mappings <?= $fingerprintTemplateCount ?>/<?= $requiredFingerprintTemplateCount ?> · mapping version <?= (int) ($enrollmentStatus['mappingVersion'] ?? $employee['fingerprint_mapping_version']) ?></small>
        <br><small><strong>Capture order:</strong> Center → Left → Right → Upper → Lower. Remove and reposition the same thumb after each OLED prompt.</small>
        <?php if ($enrollmentActive): ?><br><small>At the terminal, press <strong>BTN1</strong> to approve enrollment or BTN2 to cancel. Attendance is paused only after BTN1 starts the five-position scan.</small><?php endif; ?>
    </div>
    <section class="employee-account-admin" id="employee-account">
        <div class="panel-title-row employee-account-heading">
            <div>
                <h2><?= ui_icon('shield', 'heading-icon') ?>Employee Portal Access</h2>
                <p class="muted">Creates a separate view-only login for this employee. Attendance, schedules, and released payslips remain linked through employee ID <?= e($employee['employee_no']) ?>.</p>
            </div>
            <span class="badge <?= $portalStatus === 'Active' ? 'badge-green' : ($portalStatus === 'Disabled' ? 'badge-gray' : 'badge-amber') ?>"><?= e($portalStatus) ?></span>
        </div>

        <div class="employee-account-summary">
            <div><small>Login username</small><strong><?= e((string) ($portalAccount['username'] ?? $employee['employee_no'])) ?></strong></div>
            <div><small>Password</small><strong><?= $portalHasPassword ? 'Securely configured' : 'Not created yet' ?></strong></div>
            <div><small>Last login</small><strong><?= !empty($portalAccount['last_login']) ? e(date('M j, Y g:i A', strtotime((string) $portalAccount['last_login']))) : 'Never' ?></strong></div>
            <div><small>Access</small><strong>Personal records · View only</strong></div>
        </div>

        <?php if (!empty($portalAccount['reset_requested_at'])): ?>
            <div class="note-box portal-reset-request"><strong>Password help requested:</strong> <?= e(date('M j, Y g:i A', strtotime((string) $portalAccount['reset_requested_at']))) ?>. Issue a reset link or assign a temporary password below.</div>
        <?php endif; ?>

        <?php if ($portalLink): ?>
            <div class="portal-link-box" role="status">
                <strong><?= e((string) $portalLink['purpose']) ?> link created</strong>
                <p>It expires <?= e(date('M j, Y g:i A', strtotime((string) $portalLink['expires_at']))) ?> and works once. Give it privately to this employee.</p>
                <div class="portal-link-copy"><input id="employee-portal-private-link" value="<?= e((string) $portalLink['url']) ?>" readonly><button class="btn btn-outline" type="button" data-copy-target="employee-portal-private-link"><?= ui_icon('copy', 'button-icon') ?><span>Copy link</span></button></div>
            </div>
        <?php endif; ?>

        <?php if ($employee['status'] === 'Inactive'): ?>
            <div class="note-box">This employee is inactive, so portal access is disabled. Change the employee status first if access should be restored.</div>
        <?php else: ?>
            <?php if ($portalStatus === 'Disabled'): ?>
                <div class="note-box">Portal access is disabled. Enable it below before issuing a password link or temporary password.</div>
            <?php else: ?>
                <div class="employee-account-actions">
                    <form method="post" class="employee-account-action-card" data-submit-lock>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="issue_employee_portal_link">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="purpose" value="<?= $portalHasPassword ? 'Reset' : 'Activation' ?>">
                        <h3><?= $portalHasPassword ? 'Issue Password Reset Link' : 'Create Private Setup Link' ?></h3>
                        <p>The employee opens a one-time link and creates their own password. Old links are cancelled automatically.</p>
                        <button class="btn btn-outline" type="submit"><?= ui_icon('link', 'button-icon') ?><?= $portalHasPassword ? 'Create reset link' : 'Create setup link' ?></button>
                    </form>

                    <form method="post" class="employee-account-action-card" data-submit-lock>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="set_employee_portal_password">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <h3><?= $portalHasPassword ? 'Assign New Temporary Password' : 'Assign Temporary Password' ?></h3>
                        <p>Use this when HR needs to activate or reset the login immediately. The employee must change it after signing in.</p>
                        <label>Temporary Password<input type="password" name="temporary_password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
                        <label>Confirm Password<input type="password" name="confirm_temporary_password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
                        <button class="btn btn-primary" type="submit"><?= ui_icon('key', 'button-icon') ?>Save temporary password</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($portalHasPassword): ?>
                <form method="post" class="portal-status-form" data-submit-lock>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_employee_portal_status">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="account_status" value="<?= $portalStatus === 'Disabled' ? 'Active' : 'Disabled' ?>">
                    <button class="btn btn-outline" type="submit"><?= ui_icon($portalStatus === 'Disabled' ? 'check-circle' : 'lock', 'button-icon') ?><?= $portalStatus === 'Disabled' ? 'Enable employee portal access' : 'Disable employee portal access' ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php if ($requiresPayTypeConversion): ?><div class="note-box"><strong>Pay Type update required:</strong> this employee still has a legacy Monthly rate. Choose Daily or Hourly and enter the correctly converted Approved Rate before saving or generating new payroll.</div><?php endif; ?>
    <form method="post" class="employee-form" data-employment-pay-form>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_employee"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-grid edit-grid">
            <label>First Name<input name="first_name" value="<?= e($employee['first_name']) ?>" required></label>
            <label>Last Name<input name="last_name" value="<?= e($employee['last_name']) ?>" required></label>
            <label class="span-2">Employee ID<input value="<?= e($employee['employee_no']) ?>" disabled></label>
            <label>Department<select name="department_id"><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (int) $department['id'] === (int) $employee['department_id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></label>
            <label>Position<input name="position" value="<?= e($employee['position']) ?>" required></label>
            <label>Employment Type<select name="employment_type" data-employment-type required>
                    <option value="" <?= empty($employee['employment_type']) ? 'selected' : '' ?>>Select employment type</option>
                    <option value="Full-Time" <?= ($employee['employment_type'] ?? '') === 'Full-Time' ? 'selected' : '' ?>>Full-Time</option>
                    <option value="Part-Time" <?= ($employee['employment_type'] ?? '') === 'Part-Time' ? 'selected' : '' ?>>Part-Time</option>
                </select></label>
            <label>Pay Type<select name="pay_type" data-pay-type required>
                    <option value="">Select pay type</option>
                    <option value="Daily" <?= $expectedSalaryPayType === 'Daily' ? 'selected' : '' ?>>Daily</option>
                    <option value="Hourly" <?= $expectedSalaryPayType === 'Hourly' ? 'selected' : '' ?>>Hourly</option>
                    <option value="Monthly" <?= $expectedSalaryPayType === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                </select><small data-pay-type-help>Choose the unit represented by the Approved Rate.</small></label>
            <label>Approved Rate (₱)<input type="number" min="0.01" max="999999999.99" step="0.01" name="basic_rate" value="<?= e((string) $expectedSalaryRate) ?>" required><small data-approved-rate-help>Enter the approved <?= e(strtolower($expectedSalaryPayType)) ?> amount.</small></label>
            <section
                class="expected-monthly-salary span-2"
                data-expected-monthly-salary-card
                data-scheduled-days="<?= $expectedScheduledDays ?>"
                data-scheduled-minutes="<?= $expectedScheduledMinutes ?>"
                aria-labelledby="expected-monthly-salary-title">
                <div class="expected-monthly-salary-copy">
                    <span id="expected-monthly-salary-title">Expected Monthly Salary</span>
                    <strong data-expected-monthly-salary><?= e(money($expectedSalaryAmount)) ?></strong>
                    <small data-expected-monthly-formula><?= e($expectedSalaryFormula) ?></small>
                </div>
                <div class="expected-monthly-salary-meta">
                    <span><?= e(date('F Y')) ?> schedule estimate</span>
                    <span>Saved payroll keeps this rate snapshot</span>
                    <a href="app.php?page=schedule&amp;employee_id=<?= $id ?>#schedule-assignment"><?= ui_icon('schedule', 'button-icon') ?>Open Work Schedule</a>
                </div>
                <small>Work Schedule supplies the Daily workdays and Hourly hours used for Basic Pay. Verified attendance determines any late, undertime, half-day, or absence deductions below Gross Pay; Monthly rates are prorated to the payroll period.</small>
            </section>
            <label>Rate Effective Date<input type="date" name="compensation_effective_from" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label class="span-2">Rate Change Reason<input name="compensation_change_reason" maxlength="500" placeholder="Required when employment type, pay type, or approved rate changes"></label>
            <label>Status<select name="status">
                    <option <?= $employee['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option <?= $employee['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option <?= $employee['status'] === 'On leave' ? 'selected' : '' ?>>On leave</option>
                </select></label>
            <label>Contact Number<input name="contact_number" value="<?= e($employee['contact_number']) ?>"></label>
            <label>Email<input type="email" name="email" value="<?= e($employee['email']) ?>"></label>
            <input type="hidden" name="middle_name" value="<?= e($employee['middle_name']) ?>"><input type="hidden" name="date_of_birth" value="<?= e($employee['date_of_birth']) ?>"><input type="hidden" name="gender" value="<?= e($employee['gender']) ?>"><input type="hidden" name="civil_status" value="<?= e($employee['civil_status']) ?>">
        </div>
        <div class="note-box"><strong>Compensation history:</strong> changing Employment Type, Pay Type, or Approved Rate creates an effective-dated audit record. Existing finalized payroll keeps its frozen salary snapshot.</div>
        <div class="form-actions"><a class="btn btn-outline" href="app.php?page=employees"><?= ui_icon('x', 'button-icon') ?>Cancel</a><button class="btn btn-primary" type="submit"><?= ui_icon('save', 'button-icon') ?>Save changes</button></div>
    </form>
</article>
<?php if ($enrollmentActive): ?>
    <script>
        (() => {
            const panel = document.getElementById('fingerprint-sync-status');
            const phase = document.getElementById('fingerprint-sync-phase');
            const message = document.getElementById('fingerprint-sync-message');
            const meta = document.getElementById('fingerprint-sync-meta');
            const badge = document.getElementById('fingerprint-employee-badge');
            const button = document.getElementById('fingerprint-sync-button');
            if (!panel || !phase || !message || !meta || !badge || !button) return;

            const employeeId = panel.dataset.employeeId;
            const requiredTemplateCount = Number(panel.dataset.requiredTemplateCount || 5);
            let enrolledTemplateCount = Number(panel.dataset.templateCount || 0);
            let failures = 0;
            let stopped = false;

            const poll = async () => {
                if (stopped) return;
                if (document.hidden) {
                    window.setTimeout(poll, 2000);
                    return;
                }

                try {
                    const response = await fetch(
                        'api/terminal/enrollment_status.php?employee_id=' + encodeURIComponent(employeeId), {
                            cache: 'no-store',
                            headers: {
                                'Accept': 'application/json'
                            }
                        }
                    );
                    const data = await response.json();
                    if (!response.ok || !data.ok) throw new Error(data.error || 'Status unavailable');

                    failures = 0;
                    const enrollment = data.enrollment;
                    const markedEnrolled = enrollment.fingerprintStatus === 'Enrolled' &&
                        enrollment.mappingStatus === 'Enrolled';
                    if (markedEnrolled && Array.isArray(enrollment.templateSlots)) {
                        enrolledTemplateCount = Math.min(requiredTemplateCount, enrollment.templateSlots.length);
                    }
                    const profileComplete = markedEnrolled &&
                        enrolledTemplateCount === requiredTemplateCount;
                    const recoveryRequired = markedEnrolled && !profileComplete;

                    phase.textContent = 'Fingerprint: ' +
                        (recoveryRequired && !enrollment.active ? 'Profile recovery required' : enrollment.phase) +
                        '.';
                    message.textContent = recoveryRequired && !enrollment.active ?
                        ' Only ' + enrolledTemplateCount + ' of ' + requiredTemplateCount +
                        ' enrolled AS608 position mappings are safely linked. Use Controlled Re-enroll with the employee present; never guess or restore missing sensor slots manually.' :
                        ' ' + enrollment.message;
                    meta.textContent = enrollment.employeeNo + ' · AS608 slot ' + enrollment.slot +
                        ' · enrolled mappings ' + enrolledTemplateCount + '/' + requiredTemplateCount +
                        ' · mapping version ' + enrollment.mappingVersion;
                    badge.textContent = recoveryRequired ?
                        'Profile recovery · ' + enrolledTemplateCount + '/' + requiredTemplateCount :
                        (profileComplete ?
                            'Enrolled · ' + requiredTemplateCount + '/' + requiredTemplateCount :
                            enrollment.fingerprintStatus + ' · ' + enrolledTemplateCount + '/' + requiredTemplateCount);
                    badge.classList.toggle('badge-green', profileComplete);
                    badge.classList.toggle('badge-amber', !profileComplete);

                    if (!enrollment.active) {
                        stopped = true;
                        button.disabled = false;
                        const icon = button.querySelector('.ui-icon')?.cloneNode(true);
                        const buttonLabel = recoveryRequired ?
                            'Controlled re-enroll fingerprint' :
                            (profileComplete ?
                                'Sync / Re-enroll fingerprint' :
                                'Sync / Enroll fingerprint');
                        button.textContent = buttonLabel;
                        if (icon) button.prepend(icon);
                        panel.dataset.active = '0';
                        panel.className = profileComplete ?
                            'activation-note' :
                            'note-box onboarding-note';
                        return;
                    }
                } catch (error) {
                    failures++;
                    if (failures >= 3) {
                        message.textContent = ' Connection interrupted while checking enrollment status. The queued command is still saved; checking again…';
                    }
                }

                window.setTimeout(poll, 2000);
            };

            window.setTimeout(poll, 800);
        })();
    </script>
<?php endif; ?>
