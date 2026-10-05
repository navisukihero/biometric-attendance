<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}

$employeeId = (int) $employee['employee_id'];
$scheduleRows = employee_weekly_schedule($pdo, $employeeId);

$durationMinutes = static function (?string $start, ?string $end): int {
    if (!$start || !$end) {
        return 0;
    }
    $startParts = array_map('intval', explode(':', $start));
    $endParts = array_map('intval', explode(':', $end));
    $startMinutes = ($startParts[0] * 60) + ($startParts[1] ?? 0);
    $endMinutes = ($endParts[0] * 60) + ($endParts[1] ?? 0);
    if ($endMinutes <= $startMinutes) {
        $endMinutes += 24 * 60;
    }
    return $endMinutes - $startMinutes;
};
$formatTime = static fn(?string $time): string => $time ? date('g:i A', strtotime($time)) : '—';
$formatMinutes = static function (int $minutes): string {
    $hours = intdiv(max(0, $minutes), 60);
    $remainder = max(0, $minutes) % 60;
    return $remainder === 0 ? $hours . 'h' : $hours . 'h ' . $remainder . 'm';
};
$formatPeriods = static function (array $periods) use ($formatTime): string {
    if (!$periods) {
        return '';
    }
    return implode(' and ', array_map(
        static fn(array $period): string => $formatTime($period['period_start'] ?? null)
            . '–' . $formatTime($period['period_end'] ?? null),
        $periods
    ));
};

$workDays = 0;
$weeklyMinutes = 0;
foreach ($scheduleRows as $row) {
    if (($row['schedule_type'] ?? 'Off') !== 'Work') {
        continue;
    }
    $workDays++;
    $periods = attendance_normalize_schedule_periods((array) ($row['periods'] ?? []));
    $weeklyMinutes += $periods
        ? array_sum(array_column($periods, 'minutes'))
        : max(
            0,
            $durationMinutes($row['shift_start'] ?: null, $row['shift_end'] ?: null)
                - max(0, (int) ($row['break_minutes'] ?? 0))
        );
}
?>
<section class="schedule-page employee-schedule-page">
    <article class="panel standard-panel schedule-assignment-panel">
        <div class="panel-title-row schedule-heading">
            <div>
                <h2><?= ui_icon('schedule', 'heading-icon') ?>My Weekly Work Schedule</h2>
                <p class="muted">The attendance system uses these read-only periods as your expected working time.</p>
            </div>
            <span class="badge badge-blue">Read only</span>
        </div>

        <div class="metric-strip">
            <div><small>Employee</small><strong class="stat-date"><?= e($employee['employee_no']) ?></strong></div>
            <div><small>Work days</small><strong><?= $workDays ?></strong></div>
            <div><small>Rest days</small><strong><?= max(0, 7 - $workDays) ?></strong></div>
            <div><small>Weekly net hours</small><strong><?= e($formatMinutes($weeklyMinutes)) ?></strong></div>
        </div>

        <div class="table-scroll">
            <table class="data-table weekly-schedule-table">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Day Type</th>
                        <th>Expected Working Periods</th>
                        <th>Unpaid Gap / Break</th>
                        <th>Required Hours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scheduleRows as $row): ?>
                        <?php
                        $isWork = ($row['schedule_type'] ?? 'Off') === 'Work';
                        $breakMinutes = $isWork ? max(0, (int) ($row['break_minutes'] ?? 0)) : 0;
                        $periods = $isWork ? attendance_normalize_schedule_periods((array) ($row['periods'] ?? [])) : [];
                        $minutes = !$isWork
                            ? 0
                            : ($periods
                                ? array_sum(array_column($periods, 'minutes'))
                                : max(0, $durationMinutes($row['shift_start'] ?: null, $row['shift_end'] ?: null) - $breakMinutes));
                        $overnight = $isWork && $row['shift_start'] && $row['shift_end'] && $row['shift_end'] <= $row['shift_start'];
                        $periodLabel = $periods
                            ? $formatPeriods($periods)
                            : $formatTime($row['shift_start'] ?: null) . '–' . $formatTime($row['shift_end'] ?: null) . ($overnight ? ' (+1 day)' : '');
                        ?>
                        <tr class="<?= $isWork ? '' : 'is-rest-day' ?>">
                            <td><strong><?= e((string) $row['day_of_week']) ?></strong><?php if (($row['day_of_week'] ?? '') === date('l')): ?><small class="cell-subtitle">Today</small><?php endif; ?></td>
                            <td><span class="badge <?= $isWork ? 'badge-green' : 'badge-gray' ?>"><?= $isWork ? 'Work Day' : 'Rest Day' ?></span></td>
                            <td><?= $isWork ? e($periodLabel) : '—' ?><?php if (count($periods) > 1): ?><small class="cell-subtitle"><?= count($periods) ?> flexible periods</small><?php endif; ?></td>
                            <td><?= $isWork ? $breakMinutes . ' min' : '—' ?></td>
                            <td><?= $isWork ? e($formatMinutes($minutes)) : 'Rest' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$scheduleRows): ?><tr>
                            <td colspan="5" class="empty-state">No work schedule has been configured. Please contact HR/Admin.</td>
                        </tr><?php endif; ?>
                </tbody>
                <?php if ($scheduleRows): ?><tfoot>
                        <tr>
                            <td colspan="4"><strong>Total weekly required hours</strong></td>
                            <td><strong><?= e($formatMinutes($weeklyMinutes)) ?></strong></td>
                        </tr>
                    </tfoot><?php endif; ?>
            </table>
        </div>

        <div class="note-box">
            Schedule corrections must be requested from HR/Admin. Employees cannot change work days or expected times from this portal.
        </div>
    </article>
</section>