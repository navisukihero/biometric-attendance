<?php

declare(strict_types=1);

$today = date('Y-m-d');
$trendStart = date('Y-m-d', strtotime('-6 days'));

$employeeSummary = $pdo->query(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(status="Active"), 0) AS active,
            COALESCE(SUM(status="On leave"), 0) AS on_leave,
            COALESCE(SUM(status="Inactive"), 0) AS inactive,
            COUNT(DISTINCT CASE WHEN status="Active" THEN department_id END) AS active_departments
     FROM employees'
)->fetch() ?: [];

$employeeCount = (int) ($employeeSummary['active'] ?? 0);
$onLeaveCount = (int) ($employeeSummary['on_leave'] ?? 0);
$inactiveCount = (int) ($employeeSummary['inactive'] ?? 0);
$activeDepartmentCount = (int) ($employeeSummary['active_departments'] ?? 0);

$attendanceDashboardColumns = function_exists('attendance_table_columns')
    ? attendance_table_columns($pdo, 'attendance')
    : [];
$approvedOvertimeExpression = isset($attendanceDashboardColumns['approved_overtime_minutes'])
    ? 'COALESCE(SUM(a.approved_overtime_minutes), 0)'
    : '0';
$todayStatement = $pdo->prepare(
    'SELECT COUNT(DISTINCT CASE WHEN a.time_in IS NOT NULL THEN a.employee_id END) AS checked_in,
            COALESCE(SUM((a.time_in IS NOT NULL) + (a.time_out IS NOT NULL)), 0) AS scan_events,
            COALESCE(SUM(a.status IN ("Late", "LATE", "LATE_AND_UNDERTIME")), 0) AS late_count,
            ' . $approvedOvertimeExpression . ' AS approved_overtime_minutes
     FROM attendance a
     JOIN employees e ON e.id=a.employee_id AND e.status="Active"
     WHERE a.scan_date=?'
);
$todayStatement->execute([$today]);
$todaySummary = $todayStatement->fetch() ?: [];

$checkedInToday = (int) ($todaySummary['checked_in'] ?? 0);
$scanEventsToday = (int) ($todaySummary['scan_events'] ?? 0);
$lateToday = (int) ($todaySummary['late_count'] ?? 0);
$approvedOvertimeToday = (int) ($todaySummary['approved_overtime_minutes'] ?? 0);
$notCheckedIn = max(0, $employeeCount - $checkedInToday);
$attendanceRate = $employeeCount > 0 ? (int) round(($checkedInToday / $employeeCount) * 100) : 0;

$todayHoliday = null;
try {
    $holidayDashboardStmt = $pdo->prepare(
        'SELECT id, holiday_name, holiday_type
         FROM holidays
         WHERE holiday_date=? AND status="Active"
         LIMIT 1'
    );
    $holidayDashboardStmt->execute([$today]);
    $todayHoliday = $holidayDashboardStmt->fetch() ?: null;
} catch (Throwable) {
    // Holiday Management remains optional until its additive migration is run.
}

$fingerprintReady = 0;
try {
    $fingerprintReady = (int) $pdo->query(
        'SELECT COUNT(DISTINCT e.id)
         FROM employees e
         JOIN fingerprint_registrations fr
           ON fr.employee_id=e.id AND fr.mapping_status="Enrolled"
         WHERE e.status="Active" AND e.fingerprint_status="Enrolled"'
    )->fetchColumn();
} catch (Throwable $exception) {
    $fingerprintReady = (int) $pdo->query(
        'SELECT COUNT(*) FROM employees WHERE status="Active" AND fingerprint_status="Enrolled"'
    )->fetchColumn();
}

$scheduledEmployees = 0;
try {
    $scheduledEmployees = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM (
             SELECT ws.employee_id
             FROM work_schedules ws
             JOIN employees e ON e.id=ws.employee_id AND e.status="Active"
             GROUP BY ws.employee_id
             HAVING COUNT(DISTINCT ws.day_of_week)=7
         ) complete_schedules'
    )->fetchColumn();
} catch (Throwable $exception) {
    $scheduledEmployees = 0;
}

$portalReady = 0;
try {
    $portalReady = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM employee_accounts ea
         JOIN employees e ON e.id=ea.employee_id AND e.status="Active"
         WHERE ea.account_status="Active" AND ea.password_hash IS NOT NULL'
    )->fetchColumn();
} catch (Throwable $exception) {
    $portalReady = 0;
}

$payroll = $pdo->query(
    'SELECT pr.*,
            COUNT(pi.id) AS employee_count,
            COALESCE(SUM(pi.monthly_basic_salary), 0) AS monthly_basic,
            COALESCE(SUM(pi.gross_pay), 0) AS gross,
            COALESCE(SUM(pi.late_deduction), 0) AS late_deductions,
            COALESCE(SUM(pi.total_deductions), 0) AS total_deductions,
            COALESCE(SUM(pi.net_pay), 0) AS net
     FROM payroll_runs pr
     LEFT JOIN payroll_items pi ON pi.payroll_run_id=pr.id
     GROUP BY pr.id
     ORDER BY pr.id DESC
     LIMIT 1'
)->fetch();

$payrollDeductions = $payroll
    ? (float) $payroll['total_deductions']
    : 0.0;
$payrollGross = $payroll ? (float) $payroll['gross'] : 0.0;
$deductionPercent = $payrollGross > 0 ? min(100, (int) round(($payrollDeductions / $payrollGross) * 100)) : 0;

$trendStatement = $pdo->prepare(
    'SELECT a.scan_date, COUNT(DISTINCT a.employee_id) AS employee_count
     FROM attendance a
     JOIN employees e ON e.id=a.employee_id AND e.status="Active"
     WHERE a.scan_date BETWEEN ? AND ? AND a.time_in IS NOT NULL
     GROUP BY a.scan_date
     ORDER BY a.scan_date'
);
$trendStatement->execute([$trendStart, $today]);
$trendRows = [];
foreach ($trendStatement->fetchAll() as $trendRow) {
    $trendRows[(string) $trendRow['scan_date']] = (int) $trendRow['employee_count'];
}

$attendanceTrend = [];
for ($dayOffset = 6; $dayOffset >= 0; $dayOffset--) {
    $date = date('Y-m-d', strtotime('-' . $dayOffset . ' days'));
    $attendanceTrend[] = [
        'date' => $date,
        'label' => date('D', strtotime($date)),
        'count' => $trendRows[$date] ?? 0,
    ];
}
$maxTrend = max(1, ...array_column($attendanceTrend, 'count'));

$recentAttendance = $pdo->query(
    'SELECT a.scan_date, a.time_in, a.time_out, a.status, a.source,
            e.employee_no, e.first_name, e.last_name,
            d.name AS department_name
     FROM attendance a
     JOIN employees e ON e.id=a.employee_id
     LEFT JOIN departments d ON d.id=e.department_id
     ORDER BY a.scan_date DESC, COALESCE(a.time_out, a.time_in) DESC
     LIMIT 7'
)->fetchAll();

$terminal = null;
$terminalOnline = false;
try {
    $terminal = $pdo->query(
        'SELECT device_id, last_seen, ip_address, firmware_version, last_message,
                TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_ago
         FROM device_status
         ORDER BY last_seen DESC
         LIMIT 1'
    )->fetch() ?: null;
    $terminalOnline = $terminal !== null && max(0, (int) $terminal['seconds_ago']) <= 20;
} catch (Throwable $exception) {
    $terminal = null;
}

$commandSummary = ['pending_count' => 0, 'running_count' => 0, 'failed_count' => 0];
try {
    $commandSummary = $pdo->query(
        'SELECT COALESCE(SUM(status="Pending"), 0) AS pending_count,
                COALESCE(SUM(status="Running"), 0) AS running_count,
                COALESCE(SUM(status="Failed" AND requested_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)), 0) AS failed_count
         FROM device_commands'
    )->fetch() ?: $commandSummary;
} catch (Throwable $exception) {
    // Older installations may not have the terminal command queue yet.
}

$fingerprintPercent = $employeeCount > 0 ? min(100, (int) round(($fingerprintReady / $employeeCount) * 100)) : 0;
$schedulePercent = $employeeCount > 0 ? min(100, (int) round(($scheduledEmployees / $employeeCount) * 100)) : 0;
$portalPercent = $employeeCount > 0 ? min(100, (int) round(($portalReady / $employeeCount) * 100)) : 0;

$formatMinutes = static function (int $minutes): string {
    if ($minutes <= 0) {
        return '0 min';
    }

    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    if ($hours === 0) {
        return $remainingMinutes . ' min';
    }

    return $remainingMinutes > 0
        ? $hours . 'h ' . $remainingMinutes . 'm'
        : $hours . 'h';
};

$formatTime = static function (?string $time): string {
    return $time ? date('g:i A', strtotime($time)) : '—';
};

$statusClass = static function (string $status): string {
    return match (strtoupper(str_replace([' ', '-'], '_', $status))) {
        'LATE', 'UNDERTIME', 'LATE_AND_UNDERTIME', 'HALF_DAY' => 'late',
        'ABSENT' => 'absent',
        'ON_LEAVE', 'PAID_LEAVE', 'UNPAID_LEAVE' => 'leave',
        'INCOMPLETE' => 'open',
        'REST_DAY', 'UNSCHEDULED' => 'neutral',
        default => 'present',
    };
};

$attendanceStatusLabel = static function (string $status): string {
    $normalized = strtoupper(str_replace([' ', '-'], '_', $status));
    return match ($normalized) {
        'INCOMPLETE' => 'Pending Time Out',
        'HALF_DAY' => 'Half-Day',
        default => ucwords(strtolower(str_replace('_', ' ', $normalized))),
    };
};

$icon = static function (string $name, string $class = ''): string {
    return ui_icon($name, trim('dashboard-icon ' . $class));
};

$terminalLastSeen = $terminal
    ? date('M j, g:i A', strtotime((string) $terminal['last_seen']))
    : 'No heartbeat recorded';
?>
<section class="admin-dashboard" aria-label="HR system overview">
    <header class="admin-dashboard__hero">
        <div class="admin-dashboard__hero-copy">
            <p class="admin-dashboard__eyebrow"><?= $icon('activity') ?> Live operations overview</p>
            <h2>HR Operations <span>Command Center</span></h2>
            <p class="admin-dashboard__hero-intro">One clear view of your people, biometric attendance, schedules, payroll, and connected terminal.</p>
            <div class="admin-dashboard__hero-meta" aria-label="Current system status">
                <span class="admin-dashboard__meta-chip"><?= $icon('calendar') ?> <?= e(date('l, F j')) ?></span>
                <span class="admin-dashboard__meta-chip <?= $terminalOnline ? 'is-online' : 'is-offline' ?>"><?= $icon('terminal') ?> ESP32 <?= $terminalOnline ? 'online' : 'offline' ?></span>
                <span class="admin-dashboard__meta-chip"><?= $icon('scan') ?> <?= $scanEventsToday ?> scan event<?= $scanEventsToday === 1 ? '' : 's' ?> today</span>
                <?php if ($todayHoliday): ?><span class="admin-dashboard__meta-chip"><?= $icon('calendar') ?> <?= e((string) $todayHoliday['holiday_name']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="admin-dashboard__hero-actions">
            <a class="btn btn-light" href="biometric.php"><?= $icon('fingerprint') ?> Open Terminal</a>
            <a class="btn btn-glass" href="app.php?page=reports"><?= $icon('report') ?> View Reports</a>
        </div>
        <div class="admin-dashboard__hero-orbit" aria-hidden="true">
            <span></span><span></span><span></span>
        </div>
    </header>

    <div class="admin-dashboard__metrics" aria-label="Key performance indicators">
        <a class="admin-dashboard__metric" data-accent="burgundy" href="app.php?page=employees">
            <span class="admin-dashboard__metric-icon"><?= $icon('users') ?></span>
            <span class="admin-dashboard__metric-copy">
                <small>Active employees</small>
                <strong><?= $employeeCount ?></strong>
                <span><?= $activeDepartmentCount ?> department<?= $activeDepartmentCount === 1 ? '' : 's' ?> · <?= $onLeaveCount ?> on leave</span>
            </span>
            <span class="admin-dashboard__metric-arrow"><?= $icon('arrow') ?></span>
        </a>
        <a class="admin-dashboard__metric" data-accent="green" href="app.php?page=attendance">
            <span class="admin-dashboard__metric-icon"><?= $icon('attendance') ?></span>
            <span class="admin-dashboard__metric-copy">
                <small>Checked in today</small>
                <strong><?= $checkedInToday ?><em>/<?= $employeeCount ?></em></strong>
                <span><?= $attendanceRate ?>% attendance · <?= $notCheckedIn ?> not checked in</span>
            </span>
            <span class="admin-dashboard__metric-arrow"><?= $icon('arrow') ?></span>
        </a>
        <a class="admin-dashboard__metric" data-accent="cyan" href="biometric.php">
            <span class="admin-dashboard__metric-icon"><?= $icon('scan') ?></span>
            <span class="admin-dashboard__metric-copy">
                <small>Biometric activity</small>
                <strong><?= $scanEventsToday ?></strong>
                <span><?= $lateToday ?> late · <?= e($formatMinutes($approvedOvertimeToday)) ?> approved OT</span>
            </span>
            <span class="admin-dashboard__metric-arrow"><?= $icon('arrow') ?></span>
        </a>
        <a class="admin-dashboard__metric" data-accent="violet" href="app.php?page=payroll">
            <span class="admin-dashboard__metric-icon"><?= $icon('wallet') ?></span>
            <span class="admin-dashboard__metric-copy">
                <small>Latest net payroll</small>
                <strong class="is-money"><?= $payroll ? money($payroll['net']) : 'Not run' ?></strong>
                <span><?= $payroll ? e(date('M j', strtotime((string) $payroll['period_start'])) . '–' . date('M j, Y', strtotime((string) $payroll['period_end']))) : 'Create the first payroll period' ?></span>
            </span>
            <span class="admin-dashboard__metric-arrow"><?= $icon('arrow') ?></span>
        </a>
    </div>

    <div class="admin-dashboard__main-grid">
        <article class="admin-dashboard__card admin-dashboard__attendance-card">
            <div class="admin-dashboard__section-heading">
                <div>
                    <p class="admin-dashboard__eyebrow">Attendance intelligence</p>
                    <h2><?= $icon('attendance') ?> Recent Attendance</h2>
                </div>
                <a class="btn btn-outline" href="app.php?page=attendance">View full log <?= $icon('arrow') ?></a>
            </div>

            <div class="admin-dashboard__trend">
                <div class="admin-dashboard__trend-copy">
                    <strong><?= $checkedInToday ?></strong>
                    <span>employees checked in today</span>
                    <small>Seven-day check-in trend</small>
                </div>
                <div class="admin-dashboard__trend-bars" aria-label="Seven-day employee check-in trend">
                    <?php foreach ($attendanceTrend as $trend): ?>
                        <?php $barHeight = $trend['count'] > 0 ? max(14, (int) round(($trend['count'] / $maxTrend) * 100)) : 5; ?>
                        <div class="admin-dashboard__trend-bar" title="<?= e(date('F j', strtotime($trend['date']))) ?>: <?= $trend['count'] ?> checked in">
                            <span class="admin-dashboard__bar-value"><?= $trend['count'] ?></span>
                            <span class="admin-dashboard__bar-track"><span class="admin-dashboard__bar-fill" style="--bar-height: <?= $barHeight ?>%"></span></span>
                            <small><?= e($trend['label']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="table-scroll">
                <table class="admin-dashboard__table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Date</th>
                            <th>Time in</th>
                            <th>Time out</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$recentAttendance): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="admin-dashboard__empty"><?= $icon('scan') ?><strong>No attendance records yet</strong><span>Open the biometric terminal to record the first scan.</span></div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($recentAttendance as $row): ?>
                            <?php
                            $initials = strtoupper(substr((string) $row['first_name'], 0, 1) . substr((string) $row['last_name'], 0, 1));
                            $rowStatus = (string) $row['status'];
                            ?>
                            <tr>
                                <td>
                                    <span class="admin-dashboard__employee-cell">
                                        <span class="admin-dashboard__employee-avatar"><?= e($initials) ?></span>
                                        <span><strong><?= e($row['first_name'] . ' ' . $row['last_name']) ?></strong><small><?= e($row['employee_no']) ?> · <?= e($row['department_name'] ?? 'Unassigned') ?></small></span>
                                    </span>
                                </td>
                                <td><strong><?= e(date('M j', strtotime((string) $row['scan_date']))) ?></strong><small class="admin-dashboard__cell-note"><?= e(date('D', strtotime((string) $row['scan_date']))) ?></small></td>
                                <td><?= e($formatTime($row['time_in'])) ?></td>
                                <td><?= e($formatTime($row['time_out'])) ?></td>
                                <td><span class="admin-dashboard__status admin-dashboard__status--<?= e($statusClass($rowStatus)) ?>"><?= e($attendanceStatusLabel($rowStatus)) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <div class="admin-dashboard__side-stack">
            <article class="admin-dashboard__card admin-dashboard__readiness-card">
                <div class="admin-dashboard__section-heading">
                    <div>
                        <p class="admin-dashboard__eyebrow">Connected systems</p>
                        <h2><?= $icon('terminal') ?> System Readiness</h2>
                    </div>
                    <a class="admin-dashboard__heading-link" href="biometric.php" aria-label="Open biometric terminal"><?= $icon('terminal') ?></a>
                </div>

                <div class="admin-dashboard__terminal-state <?= $terminalOnline ? 'is-online' : 'is-offline' ?>">
                    <span class="admin-dashboard__terminal-pulse" aria-hidden="true"></span>
                    <span><strong>Biometric terminal <?= $terminalOnline ? 'online' : 'offline' ?></strong><small><?= e($terminalLastSeen) ?><?= $terminal && $terminal['ip_address'] ? ' · ' . e((string) $terminal['ip_address']) : '' ?></small></span>
                </div>

                <div class="admin-dashboard__readiness-list">
                    <div class="admin-dashboard__readiness-item">
                        <div class="admin-dashboard__readiness-heading"><span><?= $icon('fingerprint') ?> Fingerprints ready</span><strong><?= $fingerprintReady ?>/<?= $employeeCount ?></strong></div>
                        <span class="admin-dashboard__progress-track"><span class="admin-dashboard__progress-fill" data-tone="green" style="width: <?= $fingerprintPercent ?>%"></span></span>
                    </div>
                    <div class="admin-dashboard__readiness-item">
                        <div class="admin-dashboard__readiness-heading"><span><?= $icon('calendar') ?> Schedules assigned</span><strong><?= $scheduledEmployees ?>/<?= $employeeCount ?></strong></div>
                        <span class="admin-dashboard__progress-track"><span class="admin-dashboard__progress-fill" data-tone="cyan" style="width: <?= $schedulePercent ?>%"></span></span>
                    </div>
                    <div class="admin-dashboard__readiness-item">
                        <div class="admin-dashboard__readiness-heading"><span><?= $icon('users') ?> Employee portals active</span><strong><?= $portalReady ?>/<?= $employeeCount ?></strong></div>
                        <span class="admin-dashboard__progress-track"><span class="admin-dashboard__progress-fill" data-tone="violet" style="width: <?= $portalPercent ?>%"></span></span>
                    </div>
                </div>

                <div class="admin-dashboard__command-strip" aria-label="Biometric command queue">
                    <span><strong><?= (int) $commandSummary['pending_count'] ?></strong> Pending</span>
                    <span><strong><?= (int) $commandSummary['running_count'] ?></strong> Running</span>
                    <span><strong><?= (int) $commandSummary['failed_count'] ?></strong> Failed 24h</span>
                </div>
            </article>

            <article class="admin-dashboard__card admin-dashboard__payroll-card">
                <div class="admin-dashboard__section-heading">
                    <div>
                        <p class="admin-dashboard__eyebrow">Financial snapshot</p>
                        <h2><?= $icon('payroll') ?> Latest Payroll</h2>
                    </div>
                    <?php if ($payroll): ?><span class="admin-dashboard__run-status"><?= e($payroll['status']) ?></span><?php endif; ?>
                </div>

                <?php if ($payroll): ?>
                    <p class="admin-dashboard__period"><?= e(date('M j', strtotime((string) $payroll['period_start']))) ?> – <?= e(date('M j, Y', strtotime((string) $payroll['period_end']))) ?></p>
                    <div class="admin-dashboard__payroll-visual">
                        <div class="admin-dashboard__payroll-ring" style="--deduction-angle: <?= $deductionPercent * 3.6 ?>deg">
                            <span class="admin-dashboard__payroll-ring-inner"><strong><?= $deductionPercent ?>%</strong><small>deductions</small></span>
                        </div>
                        <dl class="admin-dashboard__payroll-stats">
                            <div>
                                <dt>Calculated basic</dt>
                                <dd><?= money($payroll['monthly_basic']) ?></dd>
                            </div>
                            <div>
                                <dt>Total deductions</dt>
                                <dd><?= money($payrollDeductions) ?></dd>
                            </div>
                            <div>
                                <dt>Employees</dt>
                                <dd><?= (int) $payroll['employee_count'] ?></dd>
                            </div>
                            <div class="is-net">
                                <dt>Net pay</dt>
                                <dd><?= money($payroll['net']) ?></dd>
                            </div>
                        </dl>
                    </div>
                    <div class="admin-dashboard__payroll-links">
                        <a href="app.php?page=payslips"><?= $icon('document') ?> View payslips</a>
                        <a href="app.php?page=payroll">Open payroll <?= $icon('arrow') ?></a>
                    </div>
                <?php else: ?>
                    <div class="admin-dashboard__empty"><?= $icon('wallet') ?><strong>No payroll run yet</strong><span>Start a payroll period when attendance is ready.</span><a class="btn btn-primary" href="app.php?page=payroll">Open payroll</a></div>
                <?php endif; ?>
            </article>
        </div>
    </div>

    <article class="admin-dashboard__card admin-dashboard__modules">
        <div class="admin-dashboard__section-heading">
            <div>
                <p class="admin-dashboard__eyebrow">Connected workspace</p>
                <h2><?= $icon('dashboard') ?> System Modules</h2>
            </div>
            <span class="admin-dashboard__section-note">Every card opens its existing module</span>
        </div>
        <div class="admin-dashboard__module-grid">
            <a class="admin-dashboard__module" data-dashboard-module="employees" href="app.php?page=employees"><span class="admin-dashboard__module-icon" data-tone="burgundy"><?= $icon('users') ?></span><span><strong>Employees</strong><small>Records and credentials</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="terminal" href="biometric.php"><span class="admin-dashboard__module-icon" data-tone="green"><?= $icon('fingerprint') ?></span><span><strong>Biometric Terminal</strong><small>Enroll and verify</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="attendance" href="app.php?page=attendance"><span class="admin-dashboard__module-icon" data-tone="cyan"><?= $icon('attendance') ?></span><span><strong>Attendance</strong><small>Daily time records</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="schedule" href="app.php?page=schedule"><span class="admin-dashboard__module-icon" data-tone="violet"><?= $icon('calendar') ?></span><span><strong>Work Schedules</strong><small>Shifts and rest days</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="leave" href="app.php?page=leave"><span class="admin-dashboard__module-icon" data-tone="burgundy"><?= $icon('leave') ?></span><span><strong>Leave Management</strong><small>Requests and decisions</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="overtime" href="app.php?page=overtime"><span class="admin-dashboard__module-icon" data-tone="cyan"><?= $icon('overtime') ?></span><span><strong>Overtime Approvals</strong><small>Employee requests and approved OT</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="holidays" href="app.php?page=holidays"><span class="admin-dashboard__module-icon" data-tone="violet"><?= $icon('holiday') ?></span><span><strong>Holiday Calendar</strong><small>Dates and classifications</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="payroll" href="app.php?page=payroll"><span class="admin-dashboard__module-icon" data-tone="green"><?= $icon('wallet') ?></span><span><strong>Run Payroll</strong><small>Compute and release</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="payslips" href="app.php?page=payslips"><span class="admin-dashboard__module-icon" data-tone="burgundy"><?= $icon('payslip') ?></span><span><strong>Payslips</strong><small>Employee statements</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="payroll-settings" href="app.php?page=payroll_settings"><span class="admin-dashboard__module-icon" data-tone="green"><?= $icon('settings') ?></span><span><strong>Payroll Settings</strong><small>Rates and policy rules</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="reports" href="app.php?page=reports"><span class="admin-dashboard__module-icon" data-tone="cyan"><?= $icon('report') ?></span><span><strong>Reports</strong><small>Operational insights</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="audit-logs" href="app.php?page=audit_logs"><span class="admin-dashboard__module-icon" data-tone="burgundy"><?= $icon('audit') ?></span><span><strong>Audit Logs</strong><small>Actions and changes</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
            <a class="admin-dashboard__module" data-dashboard-module="settings" href="app.php?page=settings"><span class="admin-dashboard__module-icon" data-tone="violet"><?= $icon('settings') ?></span><span><strong>Settings</strong><small>Policies and connection</small></span><span class="admin-dashboard__module-arrow"><?= $icon('arrow') ?></span></a>
        </div>
    </article>

    <article class="admin-dashboard__actions-strip">
        <div>
            <span class="admin-dashboard__actions-icon"><?= $icon('activity') ?></span>
            <span><strong>Ready for the next HR task?</strong><small>Jump directly into a connected workflow.</small></span>
        </div>
        <div class="admin-dashboard__action-links">
            <a href="app.php?page=employee_new"><?= $icon('plus') ?> Add employee</a>
            <a href="app.php?page=attendance"><?= $icon('attendance') ?> Attendance</a>
            <a href="app.php?page=schedule"><?= $icon('schedule') ?> Assign schedule</a>
            <a href="app.php?page=leave"><?= $icon('leave') ?> Review leave</a>
            <a href="app.php?page=overtime"><?= $icon('overtime') ?> Review overtime</a>
            <a href="app.php?page=payroll"><?= $icon('wallet') ?> Run payroll</a>
            <a href="app.php?page=reports"><?= $icon('report') ?> Generate report</a>
        </div>
    </article>
</section>