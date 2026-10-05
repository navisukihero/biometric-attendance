<?php

declare(strict_types=1);

$date = trim((string) ($_GET['date'] ?? date('Y-m-d')));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
}
$integratedAttendance = function_exists('attendance_processing_schema_ready')
    && attendance_processing_schema_ready($pdo);

$stmt = $pdo->prepare(
    'SELECT a.*, e.employee_no, e.first_name, e.last_name, d.name department
     FROM attendance a
     JOIN employees e ON e.id=a.employee_id
     LEFT JOIN departments d ON d.id=e.department_id
     WHERE a.scan_date=?
     ORDER BY COALESCE(a.time_in, "23:59:59"), e.last_name, e.first_name'
);
$stmt->execute([$date]);
$records = $stmt->fetchAll();
$lockedPayrolls = [];
if ($integratedAttendance && $date <= date('Y-m-d')) {
    $lockStmt = $pdo->prepare(
        'SELECT DISTINCT pr.id, pr.status, e.employee_no
         FROM payroll_item_attendance pia
         JOIN payroll_items pi ON pi.id=pia.payroll_item_id
         JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
         JOIN attendance a ON a.id=pia.attendance_id
         JOIN employees e ON e.id=a.employee_id
         WHERE a.scan_date=? AND pr.status IN ("For Review","Approved","Finalized","Paid","Released")
         UNION
         SELECT DISTINCT pr.id, pr.status, e.employee_no
         FROM payroll_runs pr
         JOIN payroll_items pi ON pi.payroll_run_id=pr.id
         JOIN employees e ON e.id=pi.employee_id
         WHERE pr.status="Released" AND pr.period_start<=? AND pr.period_end>=?
         ORDER BY id, employee_no'
    );
    $lockStmt->execute([$date, $date, $date]);
    $lockedPayrolls = $lockStmt->fetchAll();
}
$punchesByEmployee = [];
if ($integratedAttendance && $records) {
    $punchStmt = $pdo->prepare(
        'SELECT employee_id, session_index, action, scanned_at
         FROM attendance_logs
         WHERE attendance_date=?
         ORDER BY employee_id, punch_sequence, scanned_at, id'
    );
    $punchStmt->execute([$date]);
    foreach ($punchStmt->fetchAll() as $punch) {
        $punchesByEmployee[(int) $punch['employee_id']][] = $punch;
    }
}

$graceMinutes = function_exists('attendance_grace_minutes') ? attendance_grace_minutes($pdo) : 15;
$formatDuration = static function (int $minutes): string {
    $minutes = max(0, $minutes);
    return intdiv($minutes, 60) . 'h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm';
};
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
$formatActualSessions = static function (array $row) use ($punchesByEmployee): string {
    $punches = $punchesByEmployee[(int) $row['employee_id']] ?? [];
    if (!$punches) {
        return 'No biometric punches';
    }
    $sessions = [];
    foreach ($punches as $punch) {
        $index = max(1, (int) $punch['session_index']);
        $action = (string) $punch['action'];
        $sessions[$index][$action] = date('g:i A', strtotime((string) $punch['scanned_at']));
    }
    $labels = [];
    $fullTime = (string) ($row['employment_type_snapshot'] ?? '') === 'Full-Time';
    foreach ($sessions as $index => $events) {
        $name = $fullTime && count($sessions) <= 2
            ? ($index === 1 ? 'Morning' : 'Afternoon')
            : 'Session ' . $index;
        $labels[] = $name . ': '
            . ($events['TIME_IN'] ?? '—') . ' IN / '
            . ($events['TIME_OUT'] ?? '—') . ' OUT';
    }
    return implode(' · ', $labels);
};
$normalizedStatus = static fn(array $row): string => strtoupper(str_replace([' ', '-'], '_', (string) ($row['status'] ?? '')));
$statusBadge = static fn(string $status): string => match ($status) {
    'PRESENT', 'PAID_LEAVE', 'HOLIDAY_WORK', 'REST_DAY_WORK' => 'badge-green',
    'HALF_DAY', 'LATE', 'UNDERTIME', 'LATE_AND_UNDERTIME', 'INCOMPLETE' => 'badge-amber',
    'ABSENT', 'UNPAID_LEAVE' => 'badge-red',
    default => 'badge-gray',
};
$statusLabel = static fn(string $status): string => match ($status) {
    'HALF_DAY' => 'Half-Day',
    'INCOMPLETE' => 'Pending Session',
    default => ucwords(strtolower(str_replace('_', ' ', $status))),
};

$presentCount = count(array_filter($records, static fn(array $row): bool => in_array($normalizedStatus($row), ['PRESENT', 'LATE', 'UNDERTIME', 'LATE_AND_UNDERTIME'], true)));
$halfDayCount = count(array_filter($records, static fn(array $row): bool => $normalizedStatus($row) === 'HALF_DAY'));
$lateCount = count(array_filter($records, static fn(array $row): bool => (int) ($row['late_minutes'] ?? 0) > 0));
$undertimeCount = count(array_filter($records, static fn(array $row): bool => (int) ($row['undertime_minutes'] ?? 0) > 0));
$absenceCount = count(array_filter($records, static fn(array $row): bool => strtoupper((string) ($row['status'] ?? '')) === 'ABSENT'));
?>
<article class="panel standard-panel attendance-log-panel">
    <div class="panel-title-row attendance-heading">
        <div>
            <h2><?= ui_icon('attendance', 'heading-icon') ?>Processed Daily Attendance</h2>
            <p class="muted">Server-calculated attendance projection used by payroll.</p>
        </div>
        <div class="button-row">
            <form method="get" class="inline-form"><input type="hidden" name="page" value="attendance"><input type="date" name="date" value="<?= e($date) ?>" aria-label="Attendance date" onchange="this.form.submit()"></form>
            <?php if ($integratedAttendance && $date <= date('Y-m-d') && !$lockedPayrolls): ?>
                <form method="post" class="inline-form" data-submit-lock>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="process_attendance_date">
                    <input type="hidden" name="attendance_date" value="<?= e($date) ?>">
                    <button class="btn btn-outline" type="submit"><?= ui_icon('refresh', 'button-icon') ?>Reprocess Day</button>
                </form>
            <?php elseif ($lockedPayrolls): ?><span class="badge badge-amber">Payroll-locked day</span><?php endif; ?>
            <a class="btn btn-primary" href="biometric.php"><?= ui_icon('fingerprint', 'button-icon') ?>Open Terminal</a>
        </div>
    </div>
    <?php if ($lockedPayrolls): ?>
        <div class="note-box"><strong>Attendance is view-only for this date:</strong> <?= e(implode('; ', array_map(static fn(array $lock): string => $lock['employee_no'] . ' · ' . $lock['status'] . ' run #' . $lock['id'], $lockedPayrolls))) ?>. Reprocess Day is unavailable because these records are already in payroll.</div>
    <?php endif; ?>
    <?php if (!$integratedAttendance): ?><div class="note-box"><strong>Compatibility mode:</strong> import the integrated attendance/payroll migration to enable raw logs, undertime, leave/holiday classification, approved OT, and deterministic reprocessing.</div><?php endif; ?>
    <div class="metric-strip">
        <div><small>Present</small><strong><?= $presentCount ?></strong></div>
        <div><small>Half-Day</small><strong class="<?= $halfDayCount ? 'text-amber' : '' ?>"><?= $halfDayCount ?></strong></div>
        <div><small>Late</small><strong class="<?= $lateCount ? 'text-red' : '' ?>"><?= $lateCount ?></strong></div>
        <div><small>Undertime</small><strong class="<?= $undertimeCount ? 'text-red' : '' ?>"><?= $undertimeCount ?></strong></div>
        <div><small>Absent</small><strong class="<?= $absenceCount ? 'text-red' : '' ?>"><?= $absenceCount ?></strong></div>
    </div>
    <div class="table-scroll">
        <table class="data-table attendance-log-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Expected / Actual</th>
                    <th>Worked / Regular</th>
                    <th>Late / Undertime</th>
                    <th>Approved OT</th>
                    <th>Classification</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <?php
                    $status = $normalizedStatus($record);
                    $scheduleType = (string) ($record['schedule_type'] ?? (!empty($record['expected_time_in']) ? 'Work' : 'Unscheduled'));
                    $worked = max(0, (int) ($record['worked_minutes'] ?? 0));
                    $regular = $integratedAttendance ? max(0, (int) ($record['regular_minutes'] ?? 0)) : max(0, $worked - (int) ($record['overtime_minutes'] ?? 0));
                    $approved = $integratedAttendance ? max(0, (int) ($record['approved_overtime_minutes'] ?? 0)) : 0;
                    ?>
                    <tr>
                        <td><strong><?= e($record['first_name'] . ' ' . $record['last_name']) ?></strong><small class="cell-subtitle"><?= e($record['employee_no']) ?> · <?= e((string) ($record['department'] ?: 'Unassigned')) ?></small></td>
                        <td><strong><?= e($scheduleType === 'Off' ? 'Rest Day' : $formatExpectedPeriods($record)) ?></strong><small class="cell-subtitle"><?= e($formatActualSessions($record)) ?></small></td>
                        <td><?= e($formatDuration($worked)) ?><small class="cell-subtitle"><?= e($formatDuration($regular)) ?> eligible regular · <?= (int) ($record['break_minutes'] ?? 0) ?>m unpaid gap/break</small></td>
                        <td><span class="<?= (int) ($record['late_minutes'] ?? 0) ? 'text-red' : '' ?>"><?= e(ucchr_minutes_label((int) ($record['late_minutes'] ?? 0))) ?> late</span><small class="cell-subtitle <?= (int) ($record['undertime_minutes'] ?? 0) ? 'text-red' : '' ?>"><?= e(ucchr_minutes_label((int) ($record['undertime_minutes'] ?? 0))) ?> undertime</small></td>
                        <td class="text-green"><?= $approved ?>m approved</td>
                        <td><?= e((string) ($record['day_classification'] ?? $scheduleType)) ?><small class="cell-subtitle"><?= e((string) ($record['schedule_source'] ?? 'Legacy')) ?></small></td>
                        <td><span class="badge <?= e($statusBadge($status)) ?>"><?= e($statusLabel($status)) ?></span><small class="cell-subtitle"><?= e((string) ($record['source'] ?: 'Attendance Processor')) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$records): ?><tr>
                        <td colspan="7" class="empty-state">No processed attendance rows for this date. Reprocess a completed past workday to create absence/rest/leave/holiday classifications.</td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>
