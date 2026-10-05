<?php
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$employees = $pdo->query(
    'SELECT e.id, e.employee_no, e.first_name, e.last_name,
            e.employment_type, e.pay_type, e.basic_rate, e.daily_rate,
            d.name department
     FROM employees e
     LEFT JOIN departments d ON d.id=e.department_id
     WHERE e.status="Active"
     ORDER BY e.last_name, e.first_name'
)->fetchAll();

$defaultRows = $pdo->query(
    'SELECT *
     FROM default_work_schedules
     ORDER BY FIELD(day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday")'
)->fetchAll();
$defaults = [];
foreach ($defaultRows as $row) {
    $defaults[(string) $row['day_of_week']] = $row;
}
foreach ($days as $day) {
    if (!isset($defaults[$day])) {
        $isSunday = $day === 'Sunday';
        $defaults[$day] = [
            'day_of_week' => $day,
            'schedule_type' => $isSunday ? 'Off' : 'Work',
            'shift_start' => $isSunday ? null : '08:00:00',
            'shift_end' => $isSunday ? null : '17:00:00',
            'break_minutes' => $isSunday ? 0 : (function_exists('attendance_default_break_minutes') ? attendance_default_break_minutes($pdo) : 60),
        ];
    }
}

$scheduleRows = $pdo->query(
    'SELECT ws.*
     FROM work_schedules ws
     JOIN employees e ON e.id=ws.employee_id
     WHERE e.status="Active"
     ORDER BY ws.employee_id,
              FIELD(ws.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday")'
)->fetchAll();
$scheduleMap = [];
foreach ($scheduleRows as $row) {
    $scheduleMap[(int) $row['employee_id']][(string) $row['day_of_week']] = $row;
}
$flexiblePeriodsReady = function_exists('attendance_has_period_schedule_schema')
    && attendance_has_period_schedule_schema($pdo)
    && isset(attendance_table_columns($pdo, 'attendance')['schedule_periods_snapshot']);
$periodMap = [];
if ($flexiblePeriodsReady) {
    $periodRows = $pdo->query(
        'SELECT wsp.work_schedule_id, wsp.period_start, wsp.period_end
         FROM work_schedule_periods wsp
         JOIN work_schedules ws ON ws.id=wsp.work_schedule_id
         JOIN employees e ON e.id=ws.employee_id
         WHERE e.status="Active"
         ORDER BY wsp.work_schedule_id, wsp.period_order, wsp.id'
    )->fetchAll();
    foreach ($periodRows as $period) {
        $periodMap[(int) $period['work_schedule_id']][] = $period;
    }
}

$selectedEmployeeId = (int) ($_GET['employee_id'] ?? 0);
$activeEmployeeIds = array_map(static fn(array $employee): int => (int) $employee['id'], $employees);
if (!in_array($selectedEmployeeId, $activeEmployeeIds, true)) {
    $selectedEmployeeId = $activeEmployeeIds[0] ?? 0;
}
$selectedEmployee = null;
foreach ($employees as $employee) {
    if ((int) $employee['id'] === $selectedEmployeeId) {
        $selectedEmployee = $employee;
        break;
    }
}

$durationMinutes = static function (?string $start, ?string $end): int {
    if (!$start || !$end) {
        return 0;
    }
    $startParts = array_map('intval', explode(':', $start));
    $endParts = array_map('intval', explode(':', $end));
    $startMinute = ($startParts[0] * 60) + $startParts[1];
    $endMinute = ($endParts[0] * 60) + $endParts[1];
    if ($endMinute <= $startMinute) {
        $endMinute += 24 * 60;
    }
    return $endMinute - $startMinute;
};
$formatTime = static fn(?string $time): string => $time ? date('g:i A', strtotime($time)) : '—';
$formatHours = static function (int $minutes): string {
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    return $remaining === 0 ? $hours . 'h' : $hours . 'h ' . $remaining . 'm';
};

$selectedRows = [];
foreach ($days as $day) {
    $selectedRows[$day] = $scheduleMap[$selectedEmployeeId][$day] ?? $defaults[$day];
    $scheduleId = (int) ($selectedRows[$day]['id'] ?? 0);
    $selectedRows[$day]['periods'] = $scheduleId > 0 ? ($periodMap[$scheduleId] ?? []) : [];
}

$employeeSummaries = [];
foreach ($employees as $employee) {
    $summaryEmployeeId = (int) $employee['id'];
    $savedCount = count($scheduleMap[$summaryEmployeeId] ?? []);
    $workDays = [];
    $shiftLabels = [];
    $weeklyMinutes = 0;
    foreach ($days as $day) {
        $row = $scheduleMap[$summaryEmployeeId][$day] ?? $defaults[$day];
        if ((string) $row['schedule_type'] !== 'Work') {
            continue;
        }
        $workDays[] = substr($day, 0, 3);
        $start = $row['shift_start'] ?: null;
        $end = $row['shift_end'] ?: null;
        $weeklyMinutes += max(0, $durationMinutes($start, $end) - (int) ($row['break_minutes'] ?? 0));
        if ($start && $end) {
            $rowPeriods = $periodMap[(int) ($row['id'] ?? 0)] ?? [];
            $label = $rowPeriods
                ? implode(', ', array_map(
                    static fn(array $period): string => $formatTime($period['period_start']) . '–' . $formatTime($period['period_end']),
                    $rowPeriods
                ))
                : $formatTime($start) . ' – ' . $formatTime($end);
            $shiftLabels[$label] = true;
        }
    }
    $employeeSummaries[] = [
        'employee' => $employee,
        'saved_count' => $savedCount,
        'work_days' => $workDays,
        'shift_labels' => array_keys($shiftLabels),
        'weekly_minutes' => $weeklyMinutes,
    ];
}
$today = new DateTimeImmutable('today');
$periodPreviewStart = (int) $today->format('j') <= 15
    ? $today->format('Y-m-01')
    : $today->format('Y-m-16');
$periodPreviewEnd = (int) $today->format('j') <= 15
    ? $today->format('Y-m-15')
    : $today->format('Y-m-t');
?>
<section class="schedule-page simplified-schedule-page">
    <article class="panel standard-panel schedule-assignment-panel" id="schedule-assignment">
        <div class="panel-title-row schedule-heading">
            <div>
                <h2><?= ui_icon('schedule', 'heading-icon') ?>Assign Employee Work Schedule</h2>
                <p class="muted">Confirm Employment Type, Pay Type, and Approved Rate, then set each workday independently. Part-Time employees may have different hours on every day.</p>
            </div>
            <span class="badge badge-blue"><?= count($employees) ?> active employee<?= count($employees) === 1 ? '' : 's' ?></span>
        </div>

        <?php if (!$employees): ?>
            <div class="empty-state schedule-empty-state">No active employees are available. Register an employee with Active status first.</div>
        <?php else: ?>
            <form method="get" class="schedule-employee-picker" data-schedule-employee-picker>
                <input type="hidden" name="page" value="schedule">
                <label>Employee
                    <select name="employee_id" required>
                        <?php foreach ($employees as $employee): ?>
                            <option value="<?= (int) $employee['id'] ?>" <?= $selectedEmployeeId === (int) $employee['id'] ? 'selected' : '' ?>>
                                <?= e($employee['employee_no'] . ' — ' . $employee['first_name'] . ' ' . $employee['last_name'] . ' · ' . ($employee['department'] ?: 'No department')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-outline" type="submit"><?= ui_icon('refresh', 'button-icon') ?>Load schedule</button>
            </form>

            <div class="schedule-help-note">
                <strong><?= e($selectedEmployee['first_name'] . ' ' . $selectedEmployee['last_name']) ?></strong>
                <?php if (count($scheduleMap[$selectedEmployeeId] ?? []) === 7): ?>
                    has a complete saved weekly assignment. Edit any day below and save again.
                <?php else: ?>
                    is available but does not yet have a complete assignment. The displayed organization fallback is only a starting template; saving creates this employee’s own schedule.
                <?php endif; ?>
            </div>

            <form method="post" class="weekly-schedule-form" data-weekly-schedule-form data-submit-lock>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_weekly_schedule">
                <input type="hidden" name="employee_id" value="<?= $selectedEmployeeId ?>">
                <div class="form-grid schedule-employment-grid">
                    <label>Employment Type
                        <select name="employment_type" data-employment-type required>
                            <option value="Full-Time" <?= ($selectedEmployee['employment_type'] ?? '') === 'Full-Time' ? 'selected' : '' ?>>Full-Time</option>
                            <option value="Part-Time" <?= ($selectedEmployee['employment_type'] ?? '') === 'Part-Time' ? 'selected' : '' ?>>Part-Time</option>
                        </select>
                    </label>
                    <label>Pay Type
                        <select name="pay_type" data-pay-type required>
                            <option value="">Select pay type</option>
                            <option value="Daily" <?= ($selectedEmployee['pay_type'] ?? 'Daily') === 'Daily' ? 'selected' : '' ?>>Daily</option>
                            <option value="Hourly" <?= ($selectedEmployee['pay_type'] ?? '') === 'Hourly' ? 'selected' : '' ?>>Hourly</option>
                            <option value="Monthly" <?= ($selectedEmployee['pay_type'] ?? '') === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                        </select>
                        <small data-pay-type-help>Choose the unit represented by the Approved Rate.</small>
                    </label>
                    <label>Approved Rate (₱)
                        <input type="number" name="basic_rate" min="0.01" max="999999999.99" step="0.01" value="<?= e((string) (($selectedEmployee['basic_rate'] ?? 0) > 0 ? $selectedEmployee['basic_rate'] : $selectedEmployee['daily_rate'])) ?>" required>
                        <small data-approved-rate-help>Daily uses scheduled workdays; Hourly uses scheduled hours; Monthly uses its approved monthly rate. Unpaid attendance is deducted separately.</small>
                    </label>
                </div>
                <div class="note-box"><strong>Flexible Part-Time schedule:</strong> select only required workdays, then add up to six non-overlapping periods per day. Example: 9:00 AM–12:00 PM plus 1:00 PM–2:00 PM automatically becomes 4 required hours; the gap is unpaid.</div>
                <?php if (!$flexiblePeriodsReady): ?>
                    <div class="note-box"><strong>Migration required:</strong> import <code>database/part_time_schedule_periods_update.sql</code> before saving a split Part-Time schedule. Existing single-shift schedules remain available.</div>
                <?php endif; ?>
                <div class="table-scroll">
                    <table class="data-table weekly-schedule-table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Work / Rest</th>
                                <th>Schedule configuration</th>
                                <th>Required Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($days as $day): ?>
                                <?php
                                $row = $selectedRows[$day];
                                $isWork = (string) $row['schedule_type'] === 'Work';
                                $start = $row['shift_start'] ? substr((string) $row['shift_start'], 0, 5) : '08:00';
                                $end = $row['shift_end'] ? substr((string) $row['shift_end'], 0, 5) : '17:00';
                                $breakMinutes = $isWork ? max(0, (int) ($row['break_minutes'] ?? (function_exists('attendance_default_break_minutes') ? attendance_default_break_minutes($pdo) : 60))) : 0;
                                $minutes = $isWork ? max(0, $durationMinutes($start, $end) - $breakMinutes) : 0;
                                $periodRows = (array) ($row['periods'] ?? []);
                                if (!$periodRows) {
                                    $periodRows = [[
                                        'period_start' => $start,
                                        'period_end' => $end,
                                    ]];
                                }
                                ?>
                                <tr data-schedule-day-row data-day="<?= e($day) ?>">
                                    <td><strong><?= e($day) ?></strong></td>
                                    <td>
                                        <label class="workday-switch">
                                            <input type="checkbox" name="work_days[]" value="<?= e($day) ?>" <?= $isWork ? 'checked' : '' ?> data-workday-toggle>
                                            <span data-day-type><?= $isWork ? 'Work Day' : 'Rest Day' ?></span>
                                        </label>
                                    </td>
                                    <td class="schedule-config-cell">
                                        <div class="single-shift-config" data-single-shift>
                                            <label>Expected In<input type="time" name="shift_start[<?= e($day) ?>]" value="<?= e($start) ?>" <?= $isWork ? 'required' : 'disabled' ?> data-schedule-start></label>
                                            <label>Expected Out<input type="time" name="shift_end[<?= e($day) ?>]" value="<?= e($end) ?>" <?= $isWork ? 'required' : 'disabled' ?> data-schedule-end></label>
                                            <label>Unpaid Break<input type="number" name="break_minutes[<?= e($day) ?>]" min="0" max="480" step="1" value="<?= $breakMinutes ?>" <?= $isWork ? 'required' : 'disabled' ?> data-schedule-break><small>minutes</small></label>
                                        </div>
                                        <div class="part-time-period-config" data-part-time-periods data-period-limit="6">
                                            <div class="part-time-period-list" data-period-list>
                                                <?php foreach ($periodRows as $periodIndex => $period): ?>
                                                    <div class="part-time-period-row" data-period-row>
                                                        <span>Period <?= $periodIndex + 1 ?></span>
                                                        <label>From<input type="time" name="period_start[<?= e($day) ?>][]" value="<?= e(substr((string) $period['period_start'], 0, 5)) ?>" data-period-start></label>
                                                        <label>To<input type="time" name="period_end[<?= e($day) ?>][]" value="<?= e(substr((string) $period['period_end'], 0, 5)) ?>" data-period-end></label>
                                                        <button class="btn btn-mini btn-outline" type="button" data-remove-period aria-label="Remove <?= e($day) ?> period <?= $periodIndex + 1 ?>"><?= ui_icon('trash', 'button-icon') ?>Remove</button>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <button class="btn btn-mini btn-outline add-period-button" type="button" data-add-period><?= ui_icon('plus', 'button-icon') ?>Add time period</button>
                                            <small class="period-validation" data-period-message>Periods must not overlap.</small>
                                        </div>
                                    </td>
                                    <td><strong data-day-hours><?= $isWork ? e($formatHours($minutes)) : 'Rest' ?></strong><small class="cell-subtitle" data-period-count></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3"><strong>Total weekly required hours</strong></td>
                                <td><strong data-weekly-hours>—</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="schedule-period-preview">
                    <div>
                        <strong>Pay-period scheduled-hours preview</strong>
                        <small>Repeats the weekly assignment across the selected dates. Payroll uses the schedule for attendance, late, undertime, and approved overtime calculations.</small>
                    </div>
                    <label>From<input type="date" value="<?= e($periodPreviewStart) ?>" data-period-preview-start></label>
                    <label>To<input type="date" value="<?= e($periodPreviewEnd) ?>" data-period-preview-end></label>
                    <div><small>Scheduled total</small><strong data-period-preview-total>—</strong></div>
                </div>
                <div class="schedule-save-row">
                    <p class="muted">Attendance freezes the selected periods for each day. Schedules determine basic pay hours or workdays; verified punches determine late, undertime, absence, and any separately approved overtime.</p>
                    <button class="btn btn-primary" type="submit" data-submit-label="Saving schedule…"><?= ui_icon('save', 'button-icon') ?>Save employee schedule</button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel standard-panel schedule-overview-panel">
        <div class="panel-title-row schedule-heading">
            <div>
                <h2><?= ui_icon('calendar', 'heading-icon') ?>Active Employee Schedule Overview</h2>
                <p class="muted">New active employees appear here automatically as soon as their employee record is saved.</p>
            </div>
        </div>
        <div class="table-scroll">
            <table class="data-table schedule-overview-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Employment / Pay</th>
                        <th>Work Days</th>
                        <th>Expected Shift</th>
                        <th>Weekly Hours</th>
                        <th>Assignment</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employeeSummaries as $summary): ?>
                        <?php
                        $savedCount = (int) $summary['saved_count'];
                        $assignmentLabel = $savedCount === 7 ? 'Assigned' : ($savedCount > 0 ? 'Needs review' : 'Not assigned');
                        $assignmentClass = $savedCount === 7 ? 'badge-green' : ($savedCount > 0 ? 'badge-amber' : 'badge-gray');
                        $shiftLabels = $summary['shift_labels'];
                        $shiftText = !$shiftLabels ? 'Rest week' : (count($shiftLabels) === 1 ? $shiftLabels[0] : 'Different times by day');
                        ?>
                        <tr>
                            <td><strong><?= e($summary['employee']['first_name'] . ' ' . $summary['employee']['last_name']) ?></strong><small class="cell-subtitle"><?= e($summary['employee']['employee_no']) ?> · <?= e($summary['employee']['department'] ?: 'No department') ?></small></td>
                            <?php
                            $summaryPayType = in_array((string) ($summary['employee']['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true)
                                ? (string) $summary['employee']['pay_type']
                                : 'Daily';
                            $summaryUnit = match ($summaryPayType) {
                                'Hourly' => 'hour',
                                'Monthly' => 'month',
                                default => 'day',
                            };
                            ?>
                            <td><?= e((string) ($summary['employee']['employment_type'] ?: 'Not set')) ?><small class="cell-subtitle"><?= e($summaryPayType) ?> · <?= e(money((float) ($summary['employee']['basic_rate'] ?: $summary['employee']['daily_rate']))) ?>/<?= e($summaryUnit) ?></small></td>
                            <td><?= $summary['work_days'] ? e(implode(', ', $summary['work_days'])) : 'None' ?></td>
                            <td><?= e($shiftText) ?></td>
                            <td><?= e($formatHours((int) $summary['weekly_minutes'])) ?></td>
                            <td><span class="badge <?= $assignmentClass ?>"><?= e($assignmentLabel) ?></span></td>
                            <td><a class="btn btn-mini btn-outline" href="app.php?page=schedule&amp;employee_id=<?= (int) $summary['employee']['id'] ?>#schedule-assignment"><?= ui_icon($savedCount === 7 ? 'edit' : 'plus', 'button-icon') ?><?= $savedCount === 7 ? 'Edit' : 'Assign' ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$employeeSummaries): ?><tr>
                            <td colspan="7" class="empty-state">No active employees are available.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
