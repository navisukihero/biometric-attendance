<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}

$employeeId = (int) $employee['employee_id'];
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$summaryStmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(CASE
            WHEN time_in IS NOT NULL AND time_out IS NOT NULL
             AND UPPER(REPLACE(REPLACE(status, " ", "_"), "-", "_")) NOT IN ("ABSENT", "INCOMPLETE")
            THEN 1 ELSE 0 END), 0) AS attendance_days,
        COALESCE(SUM(worked_minutes), 0) AS worked_minutes,
        COALESCE(SUM(late_minutes), 0) AS late_minutes,
        COALESCE(SUM(approved_overtime_minutes), 0) AS approved_overtime_minutes
     FROM attendance
     WHERE employee_id=? AND scan_date BETWEEN ? AND ?'
);
$summaryStmt->execute([$employeeId, $monthStart, $monthEnd]);
$summary = $summaryStmt->fetch() ?: [
    'attendance_days' => 0,
    'worked_minutes' => 0,
    'late_minutes' => 0,
    'approved_overtime_minutes' => 0,
];

$recentStmt = $pdo->prepare(
    'SELECT scan_date, expected_time_in, expected_time_out, schedule_periods_snapshot, time_in, time_out,
            worked_minutes, late_minutes, undertime_minutes,
            approved_overtime_minutes,
            status, source
     FROM attendance
     WHERE employee_id=?
     ORDER BY scan_date DESC, id DESC
     LIMIT 5'
);
$recentStmt->execute([$employeeId]);
$recentAttendance = $recentStmt->fetchAll();

$payslipStmt = $pdo->prepare(
    'SELECT pi.id, pi.days_worked, pi.basic_rate, pi.monthly_basic_salary, pi.gross_pay, pi.net_pay, pi.overtime_hours,
            pr.period_start, pr.period_end, pr.status AS payroll_status,
            pr.payment_method, pr.payment_status
     FROM payroll_items pi
     JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
     WHERE pi.employee_id=?
     ORDER BY pr.period_end DESC, pi.id DESC
     LIMIT 1'
);
$payslipStmt->execute([$employeeId]);
$latestPayslip = $payslipStmt->fetch();

$weeklySchedule = employee_weekly_schedule($pdo, $employeeId);
$todaySchedule = null;
foreach ($weeklySchedule as $scheduleRow) {
    if (($scheduleRow['day_of_week'] ?? '') === date('l')) {
        $todaySchedule = $scheduleRow;
        break;
    }
}

$formatMinutes = static function (int $minutes): string {
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;
    return $hours . 'h ' . str_pad((string) $remainder, 2, '0', STR_PAD_LEFT) . 'm';
};
$formatTime = static fn(?string $time): string => $time ? date('g:i A', strtotime($time)) : '—';
$formatPeriodList = static function (array $periods) use ($formatTime): string {
    return implode(' and ', array_map(
        static fn(array $period): string => $formatTime($period['period_start'] ?? null)
            . '–' . $formatTime($period['period_end'] ?? null),
        $periods
    ));
};
$formatAttendanceSchedule = static function (array $row) use ($formatTime, $formatPeriodList): string {
    $periods = attendance_decode_schedule_periods($row['schedule_periods_snapshot'] ?? null);
    return $periods
        ? $formatPeriodList($periods)
        : $formatTime($row['expected_time_in'] ?: null) . ' – ' . $formatTime($row['expected_time_out'] ?: null);
};
$displayName = trim((string) $employee['first_name'] . ' ' . (string) $employee['last_name']);
?>
<section class="schedule-page employee-dashboard">
    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('dashboard', 'heading-icon') ?>Welcome, <?= e($employee['first_name']) ?></h2>
                <p class="muted">This private portal shows only the records linked to <?= e($employee['employee_no']) ?>.</p>
            </div>
            <span class="badge badge-green">View only</span>
        </div>
    </article>

    <div class="stats-grid employee-summary-cards">
        <article class="stat-card">
            <span class="stat-label">Attendance days</span>
            <strong><?= (int) $summary['attendance_days'] ?></strong>
            <small><?= e(date('F Y')) ?></small>
        </article>
        <article class="stat-card">
            <span class="stat-label">Hours worked</span>
            <strong class="stat-date"><?= e($formatMinutes((int) $summary['worked_minutes'])) ?></strong>
            <small>Recorded this month</small>
        </article>
        <article class="stat-card">
            <span class="stat-label">Late</span>
            <strong class="stat-date text-red"><?= e(ucchr_minutes_label((int) $summary['late_minutes'])) ?></strong>
            <small>After the configured grace period</small>
        </article>
        <article class="stat-card">
            <span class="stat-label">Approved OT</span>
            <strong class="stat-date text-green"><?= e($formatMinutes((int) $summary['approved_overtime_minutes'])) ?></strong>
            <small>Manual employee request and HR/Admin approval required</small>
        </article>
    </div>

    <article class="panel standard-panel employee-today-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('schedule', 'heading-icon') ?>Today's Schedule</h2>
                <p class="muted"><?= e(date('l, F j, Y')) ?></p>
            </div>
            <a class="btn btn-outline" href="employee-portal.php?page=schedule"><?= ui_icon('calendar', 'button-icon') ?>View full week</a>
        </div>
        <?php if ($todaySchedule && ($todaySchedule['schedule_type'] ?? 'Off') === 'Work'): ?>
            <?php
            $todayPeriods = attendance_normalize_schedule_periods((array) ($todaySchedule['periods'] ?? []));
            $todayStartAt = new DateTimeImmutable('2000-01-01 ' . $todaySchedule['shift_start']);
            $todayEndAt = new DateTimeImmutable('2000-01-01 ' . $todaySchedule['shift_end']);
            if ($todayEndAt <= $todayStartAt) {
                $todayEndAt = $todayEndAt->modify('+1 day');
            }
            $todayMinutes = $todayPeriods
                ? array_sum(array_column($todayPeriods, 'minutes'))
                : max(0, intdiv($todayEndAt->getTimestamp() - $todayStartAt->getTimestamp(), 60) - (int) ($todaySchedule['break_minutes'] ?? 0));
            $todayPeriodLabel = $todayPeriods
                ? $formatPeriodList($todayPeriods)
                : $formatTime($todaySchedule['shift_start'] ?: null) . '–' . $formatTime($todaySchedule['shift_end'] ?: null);
            ?>
            <div class="metric-strip">
                <div><small>Work day</small><strong><?= e((string) $todaySchedule['day_of_week']) ?></strong></div>
                <div><small>Expected period<?= count($todayPeriods) === 1 ? '' : 's' ?></small><strong class="stat-date"><?= e($todayPeriodLabel) ?></strong></div>
                <div><small>Required hours</small><strong><?= e($formatMinutes($todayMinutes)) ?></strong></div>
                <div><small>Schedule</small><strong class="stat-date"><?= e((string) ($todaySchedule['source'] ?? 'Assigned')) ?></strong></div>
            </div>
        <?php else: ?>
            <div class="empty-state">Today is a scheduled rest day.</div>
        <?php endif; ?>
    </article>

    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('attendance', 'heading-icon') ?>Recent Attendance</h2>
                <p class="muted">Your latest biometric Time In and Time Out records.</p>
            </div>
            <a class="btn btn-outline" href="employee-portal.php?page=attendance"><?= ui_icon('eye', 'button-icon') ?>View attendance</a>
        </div>
        <div class="table-scroll">
            <table class="data-table compact">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Expected</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Worked</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAttendance as $record): ?>
                        <?php
                        $status = strtoupper(str_replace([' ', '-'], '_', (string) ($record['status'] ?? '')));
                        $statusClass = match ($status) {
                            'PRESENT', 'PAID_LEAVE', 'HOLIDAY_WORK', 'REST_DAY_WORK' => 'badge-green',
                            'LATE', 'UNDERTIME', 'LATE_AND_UNDERTIME', 'INCOMPLETE' => 'badge-amber',
                            'ABSENT', 'UNPAID_LEAVE' => 'badge-red',
                            default => 'badge-gray',
                        };
                        ?>
                        <tr>
                            <td><strong><?= e(date('M j, Y', strtotime((string) $record['scan_date']))) ?></strong></td>
                            <td><?= e($formatAttendanceSchedule($record)) ?></td>
                            <td><?= e($formatTime($record['time_in'] ?: null)) ?></td>
                            <td><?= e($formatTime($record['time_out'] ?: null)) ?></td>
                            <td><?= $record['time_out'] ? e($formatMinutes((int) $record['worked_minutes'])) : '—' ?></td>
                            <td><span class="badge <?= e($statusClass) ?>"><?= e($status) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentAttendance): ?><tr>
                            <td colspan="6" class="empty-state">No attendance has been recorded for <?= e($displayName) ?> yet.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('payslip', 'heading-icon') ?>Latest Payroll</h2>
                <p class="muted">Track processing, approval, payment, and official payslip availability.</p>
            </div>
            <a class="btn btn-outline" href="employee-portal.php?page=payslips"><?= ui_icon('payslip', 'button-icon') ?>View payslips</a>
        </div>
        <?php if ($latestPayslip): ?>
            <div class="metric-strip">
                <div><small>Pay period</small><strong class="stat-date"><?= e(date('M j', strtotime((string) $latestPayslip['period_start'])) . '–' . date('M j, Y', strtotime((string) $latestPayslip['period_end']))) ?></strong></div>
                <div><small>Approval status</small><strong><?= e((string) $latestPayslip['payroll_status']) ?></strong></div>
                <div><small>Payment</small><strong><?= e((string) $latestPayslip['payment_status']) ?></strong><small><?= e((string) $latestPayslip['payment_method']) ?></small></div>
                <div><small>Net pay</small><strong class="text-green"><?= e(money($latestPayslip['net_pay'])) ?></strong><small>Calculated basic <?= e(money($latestPayslip['monthly_basic_salary'])) ?></small></div>
            </div>
        <?php else: ?>
            <div class="empty-state">No payroll has been generated for your account yet.</div>
        <?php endif; ?>
    </article>
</section>