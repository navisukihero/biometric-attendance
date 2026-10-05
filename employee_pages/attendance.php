<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}

$employeeId = (int) $employee['employee_id'];
$selectedMonth = (string) ($_GET['month'] ?? date('Y-m'));
$parsedMonth = DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth);
if (!$parsedMonth || $parsedMonth->format('Y-m') !== $selectedMonth) {
    $parsedMonth = new DateTimeImmutable('first day of this month');
    $selectedMonth = $parsedMonth->format('Y-m');
}
$monthStart = $parsedMonth->format('Y-m-01');
$monthEnd = $parsedMonth->format('Y-m-t');

$attendanceStmt = $pdo->prepare(
    'SELECT * FROM attendance
     WHERE employee_id=? AND scan_date BETWEEN ? AND ?
     ORDER BY scan_date DESC, id DESC'
);
$attendanceStmt->execute([$employeeId, $monthStart, $monthEnd]);
$records = $attendanceStmt->fetchAll();
$punchesByDate = [];
if (function_exists('attendance_processing_schema_ready') && attendance_processing_schema_ready($pdo)) {
    $punchStmt = $pdo->prepare(
        'SELECT attendance_date, session_index, action, scanned_at
         FROM attendance_logs
         WHERE employee_id=? AND attendance_date BETWEEN ? AND ?
         ORDER BY attendance_date, punch_sequence, scanned_at, id'
    );
    $punchStmt->execute([$employeeId, $monthStart, $monthEnd]);
    foreach ($punchStmt->fetchAll() as $punch) {
        $punchesByDate[(string) $punch['attendance_date']][] = $punch;
    }
}

$presentDays = 0;
$workedMinutes = 0;
$lateMinutes = 0;
$undertimeMinutes = 0;
$approvedOvertimeMinutes = 0;
$absenceDays = 0;
$normalizedStatus = static fn(array $record): string => strtoupper(str_replace(
    [' ', '-'],
    '_',
    (string) ($record['status'] ?? '')
));
foreach ($records as $record) {
    $recordStatus = $normalizedStatus($record);
    if (
        !empty($record['time_in'])
        && !empty($record['time_out'])
        && !in_array($recordStatus, ['ABSENT', 'INCOMPLETE'], true)
    ) {
        $presentDays++;
    }
    $workedMinutes += max(0, (int) $record['worked_minutes']);
    $lateMinutes += max(0, (int) $record['late_minutes']);
    $undertimeMinutes += max(0, (int) $record['undertime_minutes']);
    $approvedOvertimeMinutes += max(0, (int) $record['approved_overtime_minutes']);
    if ($recordStatus === 'ABSENT') {
        $absenceDays++;
    }
}

$formatTime = static fn(?string $time): string => $time ? date('g:i A', strtotime($time)) : '—';
$formatExpectedPeriods = static function (array $row) use ($formatTime): string {
    $periods = attendance_decode_schedule_periods($row['schedule_periods_snapshot'] ?? null);
    if ($periods) {
        return implode(' and ', array_map(
            static fn(array $period): string => $formatTime($period['period_start']) . '–' . $formatTime($period['period_end']),
            $periods
        ));
    }
    return $formatTime($row['expected_time_in'] ?: null) . ' – ' . $formatTime($row['expected_time_out'] ?: null);
};
$formatActualSessions = static function (array $row) use ($punchesByDate, $formatTime): string {
    $punches = $punchesByDate[(string) $row['scan_date']] ?? [];
    if (!$punches) {
        return $formatTime($row['time_in'] ?: null) . ' – ' . $formatTime($row['time_out'] ?: null);
    }
    $sessions = [];
    foreach ($punches as $punch) {
        $index = max(1, (int) $punch['session_index']);
        $sessions[$index][(string) $punch['action']] = date('g:i A', strtotime((string) $punch['scanned_at']));
    }
    $labels = [];
    foreach ($sessions as $index => $events) {
        $name = (string) ($row['employment_type_snapshot'] ?? '') === 'Full-Time' && $index <= 2
            ? ($index === 1 ? 'Morning' : 'Afternoon')
            : 'Session ' . $index;
        $labels[] = $name . ': ' . ($events['TIME_IN'] ?? '—') . ' IN / '
            . ($events['TIME_OUT'] ?? '—') . ' OUT';
    }
    return implode(' · ', $labels);
};
$formatMinutes = static function (int $minutes): string {
    $minutes = max(0, $minutes);
    return intdiv($minutes, 60) . 'h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm';
};
$previousMonth = $parsedMonth->modify('-1 month')->format('Y-m');
$nextMonth = $parsedMonth->modify('+1 month')->format('Y-m');
?>
<section class="schedule-page employee-attendance-page">
    <article class="panel standard-panel attendance-log-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('attendance', 'heading-icon') ?>My Attendance Records</h2>
                <p class="muted">Biometric attendance for <?= e($employee['employee_no']) ?> only.</p>
            </div>
            <form method="get" action="employee-portal.php" class="inline-form">
                <input type="hidden" name="page" value="attendance">
                <input type="month" name="month" value="<?= e($selectedMonth) ?>" max="<?= e(date('Y-m')) ?>" aria-label="Attendance month" onchange="this.form.submit()">
            </form>
        </div>

        <div class="metric-strip">
            <div><small>Attendance days</small><strong><?= $presentDays ?></strong></div>
            <div><small>Worked</small><strong><?= e($formatMinutes($workedMinutes)) ?></strong></div>
            <div><small>Late</small><strong class="<?= $lateMinutes > 0 ? 'text-red' : '' ?>"><?= e(ucchr_minutes_label($lateMinutes)) ?></strong></div>
            <div><small>Undertime</small><strong class="<?= $undertimeMinutes > 0 ? 'text-red' : '' ?>"><?= e(ucchr_minutes_label($undertimeMinutes)) ?></strong></div>
            <div><small>Approved OT</small><strong class="<?= $approvedOvertimeMinutes > 0 ? 'text-green' : '' ?>"><?= e($formatMinutes($approvedOvertimeMinutes)) ?></strong></div>
            <div><small>Absences</small><strong class="<?= $absenceDays > 0 ? 'text-red' : '' ?>"><?= $absenceDays ?></strong></div>
        </div>

        <div class="button-row employee-month-navigation">
            <a class="btn btn-outline" href="employee-portal.php?page=attendance&amp;month=<?= e($previousMonth) ?>"><?= ui_icon('chevron-left', 'button-icon') ?>Previous month</a>
            <?php if ($nextMonth <= date('Y-m')): ?>
                <a class="btn btn-outline" href="employee-portal.php?page=attendance&amp;month=<?= e($nextMonth) ?>">Next month<?= ui_icon('chevron-right', 'button-icon') ?></a>
            <?php endif; ?>
        </div>

        <div class="note-box">
            Expected periods and unpaid gaps are copied from your assigned schedule. Part-Time regular hours count only your actual overlap with those periods. Only overtime manually requested by the employee and approved by HR/Admin appears here.
        </div>

        <div class="table-scroll">
            <table class="data-table attendance-log-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Expected</th>
                        <th>Actual</th>
                        <th>Worked / Regular</th>
                        <th>Late / Undertime</th>
                        <th>Approved OT</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <?php
                        $status = $normalizedStatus($record);
                        $statusClass = match ($status) {
                            'LATE', 'UNDERTIME', 'LATE_AND_UNDERTIME', 'HALF_DAY', 'INCOMPLETE' => 'badge-amber',
                            'ABSENT', 'UNPAID_LEAVE' => 'badge-red',
                            'PRESENT', 'PAID_LEAVE', 'HOLIDAY_WORK', 'REST_DAY_WORK' => 'badge-green',
                            default => 'badge-gray',
                        };
                        $isOffDay = ($record['schedule_type'] ?? '') === 'Off';
                        ?>
                        <tr>
                            <td><strong><?= e(date('M j, Y', strtotime((string) $record['scan_date']))) ?></strong><small class="cell-subtitle"><?= e(date('l', strtotime((string) $record['scan_date']))) ?></small></td>
                            <td><?= $isOffDay ? 'Rest day' : e($formatExpectedPeriods($record)) ?><small class="cell-subtitle"><?= (int) $record['break_minutes'] ?>m unpaid gap/break</small></td>
                            <td><?= e($formatActualSessions($record)) ?></td>
                            <td><?= e($formatMinutes((int) $record['worked_minutes'])) ?><small class="cell-subtitle"><?= e($formatMinutes((int) $record['regular_minutes'])) ?> regular</small></td>
                            <td class="<?= (int) $record['late_minutes'] > 0 || (int) $record['undertime_minutes'] > 0 ? 'text-red' : '' ?>">Late: <?= e(ucchr_minutes_label((int) $record['late_minutes'])) ?><small class="cell-subtitle">Undertime: <?= e(ucchr_minutes_label((int) $record['undertime_minutes'])) ?></small></td>
                            <td class="text-green"><?= (int) $record['approved_overtime_minutes'] ?>m approved</td>
                            <td><span class="badge <?= e($statusClass) ?>"><?= e($status === 'INCOMPLETE' ? 'Pending Session' : ($status === 'HALF_DAY' ? 'Half-Day' : $status)) ?></span><small class="cell-subtitle"><?= e((string) $record['day_classification']) ?> · <?= e((string) ($record['source'] ?: 'Attendance system')) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$records): ?><tr>
                            <td colspan="7" class="empty-state">No attendance records were found for <?= e($parsedMonth->format('F Y')) ?>.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>