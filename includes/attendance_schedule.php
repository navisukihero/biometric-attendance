<?php

declare(strict_types=1);

/**
 * Shared work-schedule resolution and attendance calculations.
 *
 * Resolution order is employee monthly override, employee weekday override,
 * default weekday schedule, then Unscheduled. Resolved values are copied to
 * attendance so later schedule edits cannot silently change completed payroll
 * history.
 */

const ATTENDANCE_SCHEDULE_WORK = 'Work';
const ATTENDANCE_SCHEDULE_OFF = 'Off';
const ATTENDANCE_SCHEDULE_UNSCHEDULED = 'Unscheduled';

function attendance_table_columns(PDO $pdo, string $table): array
{
    static $columnsByConnection = [];
    $cacheKey = spl_object_id($pdo) . ':' . $table;
    if (isset($columnsByConnection[$cacheKey])) {
        return $columnsByConnection[$cacheKey];
    }

    try {
        $rows = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll();
        $columnsByConnection[$cacheKey] = array_fill_keys(array_column($rows, 'Field'), true);
    } catch (Throwable) {
        $columnsByConnection[$cacheKey] = [];
    }

    return $columnsByConnection[$cacheKey];
}

function attendance_has_metric_columns(PDO $pdo): bool
{
    $columns = attendance_table_columns($pdo, 'attendance');
    foreach (['expected_time_in', 'expected_time_out', 'worked_minutes', 'late_minutes', 'overtime_minutes'] as $column) {
        if (!isset($columns[$column])) {
            return false;
        }
    }
    return true;
}

function attendance_has_schedule_snapshot_columns(PDO $pdo): bool
{
    $columns = attendance_table_columns($pdo, 'attendance');
    return isset($columns['schedule_type'], $columns['schedule_source']);
}

function attendance_has_integrated_metric_columns(PDO $pdo): bool
{
    $columns = attendance_table_columns($pdo, 'attendance');
    foreach (
        [
            'break_minutes',
            'regular_minutes',
            'undertime_minutes',
            'potential_overtime_minutes',
            'approved_overtime_minutes',
            'day_classification',
            'processed_at',
            'processing_version',
        ] as $column
    ) {
        if (!isset($columns[$column])) {
            return false;
        }
    }

    return true;
}

function attendance_setting_int(PDO $pdo, string $key, int $default, int $minimum = 0): int
{
    try {
        $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || trim((string) $raw) === '') {
            return max($minimum, $default);
        }

        return max($minimum, (int) $raw);
    } catch (Throwable) {
        return max($minimum, $default);
    }
}

function attendance_grace_minutes(PDO $pdo): int
{
    return attendance_setting_int($pdo, 'grace_minutes', 15);
}

function attendance_default_break_minutes(PDO $pdo): int
{
    return attendance_setting_int($pdo, 'break_duration', 60);
}

function attendance_half_day_minimum_percent(PDO $pdo): int
{
    return min(99, attendance_setting_int($pdo, 'half_day_minimum_percent', 50));
}

function attendance_full_day_minimum_percent(PDO $pdo): int
{
    return min(100, max(
        attendance_half_day_minimum_percent($pdo) + 1,
        attendance_setting_int($pdo, 'full_day_minimum_percent', 75)
    ));
}

function attendance_schedule_type(mixed $value): string
{
    $value = strtolower(trim((string) $value));
    return match ($value) {
        'work' => ATTENDANCE_SCHEDULE_WORK,
        'off' => ATTENDANCE_SCHEDULE_OFF,
        default => ATTENDANCE_SCHEDULE_UNSCHEDULED,
    };
}

function attendance_valid_time(mixed $value): ?string
{
    $value = trim((string) $value);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
        return null;
    }
    return strlen($value) === 5 ? $value . ':00' : $value;
}

function attendance_has_period_schedule_schema(PDO $pdo): bool
{
    return isset(attendance_table_columns($pdo, 'work_schedule_periods')['work_schedule_id']);
}

/**
 * Validate, normalize, and sort one day's flexible periods. Split periods are
 * deliberately same-day only; an overnight employee keeps the existing
 * single-shift schedule because overlapping midnight periods are ambiguous.
 *
 * @return list<array{period_start:string,period_end:string,minutes:int}>
 */
function attendance_validate_schedule_periods(array $periods, string $label = 'Schedule'): array
{
    if (count($periods) < 1 || count($periods) > 6) {
        throw new InvalidArgumentException($label . ' requires between 1 and 6 time periods.');
    }

    $normalized = [];
    foreach ($periods as $period) {
        if (!is_array($period)) {
            throw new InvalidArgumentException($label . ' contains an invalid time period.');
        }
        $start = attendance_valid_time($period['period_start'] ?? $period['start'] ?? null);
        $end = attendance_valid_time($period['period_end'] ?? $period['end'] ?? null);
        if (!$start || !$end || strcmp($end, $start) <= 0) {
            throw new InvalidArgumentException($label . ' periods require valid same-day start and end times.');
        }
        $startAt = new DateTimeImmutable('2000-01-01 ' . $start);
        $endAt = new DateTimeImmutable('2000-01-01 ' . $end);
        $normalized[] = [
            'period_start' => $start,
            'period_end' => $end,
            'minutes' => intdiv($endAt->getTimestamp() - $startAt->getTimestamp(), 60),
        ];
    }
    usort($normalized, static fn(array $left, array $right): int => strcmp($left['period_start'], $right['period_start']));
    $previousEnd = null;
    foreach ($normalized as $period) {
        if ($previousEnd !== null && strcmp($period['period_start'], $previousEnd) < 0) {
            throw new InvalidArgumentException($label . ' time periods cannot overlap.');
        }
        $previousEnd = $period['period_end'];
    }
    return $normalized;
}

/** @return list<array{period_start:string,period_end:string,minutes:int}> */
function attendance_normalize_schedule_periods(array $periods): array
{
    if (!$periods) {
        return [];
    }
    try {
        return attendance_validate_schedule_periods($periods);
    } catch (Throwable) {
        return [];
    }
}

/** @return array{shift_start:string,shift_end:string,scheduled_minutes:int,gap_minutes:int} */
function attendance_schedule_period_summary(array $periods): array
{
    $periods = attendance_validate_schedule_periods($periods);
    $start = $periods[0]['period_start'];
    $end = $periods[count($periods) - 1]['period_end'];
    $startAt = new DateTimeImmutable('2000-01-01 ' . $start);
    $endAt = new DateTimeImmutable('2000-01-01 ' . $end);
    $spanMinutes = intdiv($endAt->getTimestamp() - $startAt->getTimestamp(), 60);
    $scheduledMinutes = array_sum(array_column($periods, 'minutes'));
    return [
        'shift_start' => $start,
        'shift_end' => $end,
        'scheduled_minutes' => $scheduledMinutes,
        'gap_minutes' => max(0, $spanMinutes - $scheduledMinutes),
    ];
}

function attendance_schedule_periods_json(array $periods): ?string
{
    $periods = attendance_normalize_schedule_periods($periods);
    if (!$periods) {
        return null;
    }
    return json_encode(
        array_map(
            static fn(array $period): array => [
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
            ],
            $periods
        ),
        JSON_UNESCAPED_SLASHES
    ) ?: null;
}

/** @return list<array{period_start:string,period_end:string,minutes:int}> */
function attendance_decode_schedule_periods(mixed $snapshot): array
{
    if (!is_string($snapshot) || trim($snapshot) === '') {
        return [];
    }
    try {
        $decoded = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }
    return is_array($decoded) ? attendance_normalize_schedule_periods($decoded) : [];
}

function attendance_schedule_result(
    string $type,
    mixed $shiftStart,
    mixed $shiftEnd,
    string $source,
    mixed $breakMinutes = 0,
    array $periods = []
): array {
    $type = attendance_schedule_type($type);
    $start = attendance_valid_time($shiftStart);
    $end = attendance_valid_time($shiftEnd);

    if ($type !== ATTENDANCE_SCHEDULE_WORK || !$start || !$end || $start === $end) {
        return [
            'schedule_type' => $type === ATTENDANCE_SCHEDULE_OFF
                ? ATTENDANCE_SCHEDULE_OFF
                : ATTENDANCE_SCHEDULE_UNSCHEDULED,
            'schedule_source' => $source,
            'shift_start' => null,
            'shift_end' => null,
            'break_minutes' => 0,
            'periods' => [],
            'scheduled_minutes' => 0,
        ];
    }

    $scheduledMinutes = 0;
    $periods = attendance_normalize_schedule_periods($periods);

    if ($periods) {
        $summary = attendance_schedule_period_summary($periods);
        $start = $summary['shift_start'];
        $end = $summary['shift_end'];
        $breakMinutes = $summary['gap_minutes'];
        $scheduledMinutes = (int) $summary['scheduled_minutes'];
    }

    return [
        'schedule_type' => ATTENDANCE_SCHEDULE_WORK,
        'schedule_source' => $source,
        'shift_start' => $start,
        'shift_end' => $end,
        'break_minutes' => max(0, (int) $breakMinutes),
        'periods' => $periods,
        'scheduled_minutes' => $scheduledMinutes,
    ];
}

/** Resolve Employee weekday assignment > organization default > Unscheduled. */
function attendance_schedule(PDO $pdo, int $employeeId, string $scanDate): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $scanDate);
    if (!$date || $date->format('Y-m-d') !== $scanDate) {
        throw new InvalidArgumentException('Attendance scan date must use YYYY-MM-DD.');
    }
    $day = $date->format('l');
    $employmentType = '';
    try {
        $employeeStmt = $pdo->prepare('SELECT employment_type FROM employees WHERE id=? LIMIT 1');
        $employeeStmt->execute([$employeeId]);
        $employmentType = trim((string) $employeeStmt->fetchColumn());
    } catch (Throwable) {
        // Compatibility with a database awaiting the employment/pay migration.
    }

    try {
        $hasBreak = isset(attendance_table_columns($pdo, 'work_schedules')['break_minutes']);
        $stmt = $pdo->prepare(
            'SELECT ws.id, ws.schedule_type, ws.shift_start, ws.shift_end,
                    e.employment_type' . ($hasBreak ? ', ws.break_minutes' : '') . '
             FROM work_schedules ws
             JOIN employees e ON e.id=ws.employee_id
             WHERE ws.employee_id = ? AND ws.day_of_week = ?
             LIMIT 1'
        );
        $stmt->execute([$employeeId, $day]);
        $override = $stmt->fetch();
        if ($override) {
            $periods = [];
            if (
                (string) ($override['employment_type'] ?? '') === 'Part-Time'
                && attendance_has_period_schedule_schema($pdo)
            ) {
                $periodStmt = $pdo->prepare(
                    'SELECT period_start, period_end
                     FROM work_schedule_periods
                     WHERE work_schedule_id=? ORDER BY period_order, id'
                );
                $periodStmt->execute([(int) $override['id']]);
                $periods = $periodStmt->fetchAll();
            }
            return attendance_schedule_result(
                (string) ($override['schedule_type'] ?? ATTENDANCE_SCHEDULE_WORK),
                $override['shift_start'] ?? null,
                $override['shift_end'] ?? null,
                'Employee',
                $override['break_minutes'] ?? 0,
                $periods
            );
        }
    } catch (PDOException) {
        // Compatibility for a database that has not yet added schedule_type.
        try {
            $stmt = $pdo->prepare(
                'SELECT shift_start, shift_end
                 FROM work_schedules
                 WHERE employee_id = ? AND day_of_week = ?
                 LIMIT 1'
            );
            $stmt->execute([$employeeId, $day]);
            $override = $stmt->fetch();
            if ($override) {
                return attendance_schedule_result(
                    ATTENDANCE_SCHEDULE_WORK,
                    $override['shift_start'] ?? null,
                    $override['shift_end'] ?? null,
                    'Employee'
                );
            }
        } catch (Throwable) {
        }
    }

    // Flexible Part-Time attendance must be backed by an individual weekday
    // assignment. Inheriting the organization Full-Time default would invent
    // required hours that HR never assigned to this employee.
    if ($employmentType === 'Part-Time') {
        return attendance_schedule_result(
            ATTENDANCE_SCHEDULE_UNSCHEDULED,
            null,
            null,
            'Part-Time: no assigned schedule'
        );
    }

    try {
        $hasBreak = isset(attendance_table_columns($pdo, 'default_work_schedules')['break_minutes']);
        $stmt = $pdo->prepare(
            'SELECT schedule_type, shift_start, shift_end' . ($hasBreak ? ', break_minutes' : '') . '
             FROM default_work_schedules
             WHERE day_of_week = ?
             LIMIT 1'
        );
        $stmt->execute([$day]);
        $default = $stmt->fetch();
        if ($default) {
            return attendance_schedule_result(
                (string) ($default['schedule_type'] ?? ATTENDANCE_SCHEDULE_WORK),
                $default['shift_start'] ?? null,
                $default['shift_end'] ?? null,
                'Default',
                $default['break_minutes'] ?? 0
            );
        }
    } catch (Throwable) {
        // The migration seeds these same values; this fallback keeps scanning
        // operational while an older installation is being upgraded.
        if ($day !== 'Sunday') {
            return attendance_schedule_result(ATTENDANCE_SCHEDULE_WORK, '08:00:00', '17:00:00', 'Default');
        }
        return attendance_schedule_result(ATTENDANCE_SCHEDULE_OFF, null, null, 'Default');
    }

    return attendance_schedule_result(ATTENDANCE_SCHEDULE_UNSCHEDULED, null, null, 'Unscheduled');
}

/** Prefer a frozen attendance snapshot; resolve only genuinely legacy rows. */
function attendance_schedule_for_record(PDO $pdo, array $record, int $employeeId, string $scanDate): array
{
    $periods = attendance_decode_schedule_periods($record['schedule_periods_snapshot'] ?? null);
    $savedType = attendance_schedule_type($record['schedule_type'] ?? '');
    if ($savedType !== ATTENDANCE_SCHEDULE_UNSCHEDULED || !empty($record['schedule_type'])) {
        return attendance_schedule_result(
            $savedType,
            $record['expected_time_in'] ?? null,
            $record['expected_time_out'] ?? null,
            trim((string) ($record['schedule_source'] ?? 'Existing Snapshot')) ?: 'Existing Snapshot',
            $record['break_minutes'] ?? 0,
            $periods
        );
    }

    if (!empty($record['expected_time_in']) || !empty($record['expected_time_out'])) {
        return attendance_schedule_result(
            ATTENDANCE_SCHEDULE_WORK,
            $record['expected_time_in'] ?? null,
            $record['expected_time_out'] ?? null,
            'Existing Snapshot',
            $record['break_minutes'] ?? 0,
            $periods
        );
    }

    return attendance_schedule($pdo, $employeeId, $scanDate);
}

/**
 * Return the independently punchable periods for one employee workday.
 *
 * Part-Time schedules already store their flexible periods explicitly. A
 * Full-Time shift with an unpaid break is split at the centered break so a
 * normal 08:00-17:00 / 60-minute schedule becomes 08:00-12:00 and
 * 13:00-17:00. Each returned period requires its own IN/OUT pair. Overnight
 * and no-break shifts remain one period because their current schedule model
 * has no explicit break clock to split safely.
 *
 * @return list<array{session_index:int,label:string,period_start:string,period_end:string,minutes:int,start_at:DateTimeImmutable,end_at:DateTimeImmutable}>
 */
function attendance_effective_schedule_sessions(
    string $scanDate,
    array $schedule,
    ?string $employmentType = null
): array {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $scanDate);
    if (!$date || $date->format('Y-m-d') !== $scanDate) {
        throw new InvalidArgumentException('Attendance scan date must use YYYY-MM-DD.');
    }
    if (attendance_schedule_type($schedule['schedule_type'] ?? null) !== ATTENDANCE_SCHEDULE_WORK) {
        return [];
    }

    $start = attendance_valid_time($schedule['shift_start'] ?? null);
    $end = attendance_valid_time($schedule['shift_end'] ?? null);
    if (!$start || !$end || $start === $end) {
        return [];
    }

    $periods = attendance_normalize_schedule_periods((array) ($schedule['periods'] ?? []));
    $usesContinuousFallback = !$periods;
    $employmentType = trim((string) $employmentType);
    if (!$periods && $employmentType === 'Full-Time' && strcmp($end, $start) > 0) {
        $breakMinutes = max(0, (int) ($schedule['break_minutes'] ?? 0));
        $startAt = new DateTimeImmutable($scanDate . ' ' . $start);
        $endAt = new DateTimeImmutable($scanDate . ' ' . $end);
        $shiftSeconds = $endAt->getTimestamp() - $startAt->getTimestamp();
        $breakSeconds = min($shiftSeconds, $breakMinutes * 60);
        if ($breakSeconds > 0 && ($shiftSeconds - $breakSeconds) >= 2 * 60) {
            $breakStartAt = $startAt->modify('+' . (int) floor(($shiftSeconds - $breakSeconds) / 2) . ' seconds');
            $breakEndAt = $breakStartAt->modify('+' . $breakSeconds . ' seconds');
            $periods = attendance_normalize_schedule_periods([
                ['period_start' => $startAt->format('H:i:s'), 'period_end' => $breakStartAt->format('H:i:s')],
                ['period_start' => $breakEndAt->format('H:i:s'), 'period_end' => $endAt->format('H:i:s')],
            ]);
        }
    }

    if (!$periods) {
        $periods = [[
            'period_start' => $start,
            'period_end' => $end,
            'minutes' => 0,
        ]];
    }

    $sessions = [];
    $sessionCount = count($periods);
    foreach ($periods as $index => $period) {
        $periodStart = attendance_valid_time($period['period_start'] ?? null);
        $periodEnd = attendance_valid_time($period['period_end'] ?? null);
        if (!$periodStart || !$periodEnd || $periodStart === $periodEnd) {
            continue;
        }
        $startAt = new DateTimeImmutable($scanDate . ' ' . $periodStart);
        $endAt = new DateTimeImmutable($scanDate . ' ' . $periodEnd);
        if ($endAt <= $startAt) {
            $endAt = $endAt->modify('+1 day');
        }
        $label = $employmentType === 'Full-Time' && $sessionCount === 2
            ? ($index === 0 ? 'MORNING' : 'AFTERNOON')
            : 'SESSION ' . ($index + 1);
        $sessionMinutes = max(0, intdiv($endAt->getTimestamp() - $startAt->getTimestamp(), 60));
        if ($usesContinuousFallback && $employmentType === 'Part-Time' && count($periods) === 1) {
            // A legacy Part-Time shift without explicit periods remains one
            // IN/OUT session. Its configured unpaid break still reduces
            // scheduled/payable minutes without inventing a forced lunch pair.
            $sessionMinutes = max(0, $sessionMinutes - max(0, (int) ($schedule['break_minutes'] ?? 0)));
        }
        $sessions[] = [
            'session_index' => $index + 1,
            'label' => $label,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'minutes' => $sessionMinutes,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }

    return $sessions;
}

/**
 * Calculate a day from independent biometric IN/OUT pairs. One pair can cover
 * only one scheduled period, so a long morning envelope cannot silently fill
 * an unpunched afternoon session.
 *
 * @param list<array{in_at:DateTimeImmutable,out_at:?DateTimeImmutable,session_index?:int}> $punchPairs
 */
function attendance_calculate_session_metrics(
    string $scanDate,
    array $punchPairs,
    array $schedule,
    ?string $employmentType,
    int $graceMinutes,
    int $halfDayMinimumPercent = 50,
    int $fullDayMinimumPercent = 75
): array {
    $sessions = attendance_effective_schedule_sessions($scanDate, $schedule, $employmentType);
    $scheduledMinutes = array_sum(array_column($sessions, 'minutes'));
    $graceMinutes = max(0, $graceMinutes);
    $usedSessions = [];
    $sessionResults = [];
    $workedMinutes = 0;
    $regularMinutes = 0;
    $lateMinutes = 0;
    $undertimeMinutes = 0;
    $potentialOvertimeMinutes = 0;
    $invalidSequence = false;
    $openPair = false;

    foreach ($punchPairs as $pairIndex => $pair) {
        $inAt = $pair['in_at'] ?? null;
        $outAt = $pair['out_at'] ?? null;
        if (!$inAt instanceof DateTimeImmutable || ($outAt !== null && !$outAt instanceof DateTimeImmutable)) {
            continue;
        }
        if ($outAt && $outAt <= $inAt) {
            $invalidSequence = true;
            continue;
        }

        $sessionIndex = max(0, (int) ($pair['session_index'] ?? 0));
        $session = null;
        if ($sessionIndex > 0 && isset($sessions[$sessionIndex - 1]) && empty($usedSessions[$sessionIndex])) {
            $session = $sessions[$sessionIndex - 1];
        }
        if (!$session) {
            foreach ($sessions as $candidate) {
                $candidateIndex = (int) $candidate['session_index'];
                if (!empty($usedSessions[$candidateIndex])) {
                    continue;
                }
                if ($inAt < $candidate['end_at']) {
                    $session = $candidate;
                    break;
                }
            }
        }
        if (!$session && $sessions) {
            foreach (array_reverse($sessions) as $candidate) {
                if (empty($usedSessions[(int) $candidate['session_index']])) {
                    $session = $candidate;
                    break;
                }
            }
        }

        if (!$session) {
            if ($outAt) {
                $workedMinutes += max(0, intdiv($outAt->getTimestamp() - $inAt->getTimestamp(), 60));
            } else {
                $openPair = true;
            }
            continue;
        }

        $sessionIndex = (int) $session['session_index'];
        $usedSessions[$sessionIndex] = true;
        $rawLate = max(0, intdiv($inAt->getTimestamp() - $session['start_at']->getTimestamp(), 60));
        $sessionLate = max(0, min((int) $session['minutes'], $rawLate) - $graceMinutes);
        $lateMinutes += $sessionLate;
        $sessionRegular = 0;
        $sessionUndertime = 0;
        $onTimeEntry = $inAt <= $session['start_at']->modify('+' . $graceMinutes . ' minutes');
        $punctualComplete = false;

        if ($outAt) {
            $elapsed = max(0, intdiv($outAt->getTimestamp() - $inAt->getTimestamp(), 60));
            $workedMinutes += $elapsed;
            $overlapStart = max($inAt->getTimestamp(), $session['start_at']->getTimestamp());
            $overlapEnd = min($outAt->getTimestamp(), $session['end_at']->getTimestamp());
            $sessionRegular = max(0, intdiv($overlapEnd - $overlapStart, 60));
            $regularMinutes += $sessionRegular;
            if ($outAt < $session['end_at']) {
                $sessionUndertime = max(0, intdiv($session['end_at']->getTimestamp() - $outAt->getTimestamp(), 60));
                $undertimeMinutes += min((int) $session['minutes'], $sessionUndertime);
            }
            $punctualComplete = $onTimeEntry && $outAt >= $session['end_at'];

            if ($sessionIndex === count($sessions) && $outAt > $session['end_at']) {
                $potentialOvertimeMinutes += max(
                    0,
                    intdiv($outAt->getTimestamp() - max($inAt->getTimestamp(), $session['end_at']->getTimestamp()), 60)
                );
            }
        } else {
            $openPair = true;
        }

        $sessionResults[$sessionIndex] = [
            'session_index' => $sessionIndex,
            'label' => $session['label'],
            'in_at' => $inAt,
            'out_at' => $outAt,
            'regular_minutes' => $sessionRegular,
            'late_minutes' => $sessionLate,
            'undertime_minutes' => $sessionUndertime,
            'on_time_entry' => $onTimeEntry,
            'punctual_complete' => $punctualComplete,
        ];
    }

    ksort($sessionResults);
    $completedSessionCount = count(array_filter(
        $sessionResults,
        static fn(array $result): bool => $result['out_at'] instanceof DateTimeImmutable
    ));
    $punctualCompleteCount = count(array_filter(
        $sessionResults,
        static fn(array $result): bool => (bool) $result['punctual_complete']
    ));
    $halfDayLabel = null;
    if ($punctualCompleteCount === 1) {
        foreach ($sessionResults as $sessionResult) {
            if (!empty($sessionResult['punctual_complete'])) {
                $halfDayLabel = (string) $sessionResult['label'];
                break;
            }
        }
    }
    $requiredSessionCount = count($sessions);
    $attendancePercent = $scheduledMinutes > 0
        ? min(100.0, ($regularMinutes / $scheduledMinutes) * 100)
        : 0.0;
    $halfDayMinimumPercent = max(1, min(99, $halfDayMinimumPercent));
    $fullDayMinimumPercent = max($halfDayMinimumPercent + 1, min(100, $fullDayMinimumPercent));

    $classificationStatus = null;
    if ($punchPairs) {
        if ($openPair) {
            $classificationStatus = 'INCOMPLETE';
        } elseif (
            attendance_schedule_type($schedule['schedule_type'] ?? null) !== ATTENDANCE_SCHEDULE_WORK
            || $requiredSessionCount === 0
        ) {
            $classificationStatus = $completedSessionCount > 0 ? 'PRESENT' : null;
        } elseif ($requiredSessionCount > 1 && $employmentType === 'Full-Time') {
            if ($completedSessionCount === $requiredSessionCount && $attendancePercent >= $fullDayMinimumPercent) {
                $classificationStatus = 'PRESENT';
            } elseif ($punctualCompleteCount >= 1) {
                $classificationStatus = 'HALF_DAY';
            } else {
                $classificationStatus = 'ABSENT';
            }
        } elseif ($completedSessionCount === $requiredSessionCount && $attendancePercent >= $fullDayMinimumPercent) {
            $classificationStatus = 'PRESENT';
        } elseif ($attendancePercent >= $halfDayMinimumPercent) {
            $classificationStatus = 'HALF_DAY';
        } else {
            $classificationStatus = 'ABSENT';
        }
    }

    return [
        'elapsed_minutes' => $workedMinutes,
        'break_deducted_minutes' => 0,
        'worked_minutes' => $workedMinutes,
        'regular_minutes' => min($regularMinutes, $scheduledMinutes ?: $regularMinutes),
        'scheduled_minutes' => $scheduledMinutes,
        'attendance_percent' => round($attendancePercent, 2),
        'half_day_session' => $halfDayLabel,
        'late_minutes' => $lateMinutes,
        'undertime_minutes' => $undertimeMinutes,
        'overtime_minutes' => $potentialOvertimeMinutes,
        'potential_overtime_minutes' => $potentialOvertimeMinutes,
        'status' => $classificationStatus === 'PRESENT'
            ? ($lateMinutes > 0 ? 'Late' : 'Present')
            : null,
        'classification_status' => $classificationStatus,
        'invalid_sequence' => $invalidSequence,
        'actual_out_at' => null,
        'scheduled_start_at' => $sessions[0]['start_at'] ?? null,
        'scheduled_end_at' => $sessions ? $sessions[array_key_last($sessions)]['end_at'] : null,
        'break_start_at' => null,
        'break_end_at' => null,
        'schedule_type' => attendance_schedule_type($schedule['schedule_type'] ?? null),
        'schedule_periods' => array_map(static fn(array $session): array => [
            'period_start' => $session['period_start'],
            'period_end' => $session['period_end'],
            'minutes' => $session['minutes'],
        ], $sessions),
        'sessions' => array_values($sessionResults),
        'required_session_count' => $requiredSessionCount,
        'completed_session_count' => $completedSessionCount,
        'open_session' => $openPair,
    ];
}

/**
 * Converts clock-only values into dated instants. Overtime is actual work that
 * overlaps the period after scheduled end, never more than actual worked time.
 */
function attendance_calculate_metrics(
    string $scanDate,
    ?string $timeIn,
    ?string $timeOut,
    ?string $expectedIn,
    ?string $expectedOut,
    int $graceMinutes,
    string $scheduleType = ATTENDANCE_SCHEDULE_WORK,
    int $breakMinutes = 0,
    array $schedulePeriods = [],
    int $halfDayMinimumPercent = 50,
    int $fullDayMinimumPercent = 75
): array {
    $scheduleType = attendance_schedule_type($scheduleType);
    if ($scheduleType !== ATTENDANCE_SCHEDULE_WORK) {
        $expectedIn = null;
        $expectedOut = null;
    }

    $scheduledStartAt = $expectedIn ? new DateTimeImmutable($scanDate . ' ' . $expectedIn) : null;
    $scheduledEndAt = $expectedOut ? new DateTimeImmutable($scanDate . ' ' . $expectedOut) : null;
    $overnightSchedule = false;
    if ($scheduledStartAt && $scheduledEndAt && $scheduledEndAt <= $scheduledStartAt) {
        $scheduledEndAt = $scheduledEndAt->modify('+1 day');
        $overnightSchedule = true;
    }

    $actualInAt = $timeIn ? new DateTimeImmutable($scanDate . ' ' . $timeIn) : null;
    $actualOutAt = $timeOut ? new DateTimeImmutable($scanDate . ' ' . $timeOut) : null;
    $invalidSequence = false;
    if ($actualInAt && $actualOutAt && $actualOutAt < $actualInAt) {
        if ($overnightSchedule) {
            $actualOutAt = $actualOutAt->modify('+1 day');
        } else {
            $invalidSequence = true;
        }
    }

    $elapsedMinutes = ($actualInAt && $actualOutAt && !$invalidSequence)
        ? max(0, (int) floor(($actualOutAt->getTimestamp() - $actualInAt->getTimestamp()) / 60))
        : 0;

    $schedulePeriods = $scheduleType === ATTENDANCE_SCHEDULE_WORK
        ? attendance_normalize_schedule_periods($schedulePeriods)
        : [];
    $periodInstants = [];
    foreach ($schedulePeriods as $period) {
        $periodInstants[] = [
            'start' => new DateTimeImmutable($scanDate . ' ' . $period['period_start']),
            'end' => new DateTimeImmutable($scanDate . ' ' . $period['period_end']),
            'minutes' => (int) $period['minutes'],
        ];
    }

    // A duration-only schedule cannot identify an exact lunch clock. Place
    // the configured unpaid break at the center of the scheduled shift and
    // deduct only the portion actually overlapped by the employee's presence.
    // This avoids deducting lunch from a short scan wholly outside the shift.
    $breakMinutes = $scheduleType === ATTENDANCE_SCHEDULE_WORK ? max(0, $breakMinutes) : 0;
    $overlapMinutes = static function (
        DateTimeImmutable $leftStart,
        DateTimeImmutable $leftEnd,
        DateTimeImmutable $rightStart,
        DateTimeImmutable $rightEnd
    ): int {
        $overlapStart = max($leftStart->getTimestamp(), $rightStart->getTimestamp());
        $overlapEnd = min($leftEnd->getTimestamp(), $rightEnd->getTimestamp());
        return max(0, (int) floor(($overlapEnd - $overlapStart) / 60));
    };

    $breakDeductedMinutes = 0;
    $breakStartAt = null;
    $breakEndAt = null;
    if (!$periodInstants && $scheduledStartAt && $scheduledEndAt && $breakMinutes > 0) {
        $scheduleSeconds = $scheduledEndAt->getTimestamp() - $scheduledStartAt->getTimestamp();
        $breakSeconds = min($scheduleSeconds, $breakMinutes * 60);
        $breakStartAt = $scheduledStartAt->modify('+' . (int) floor(($scheduleSeconds - $breakSeconds) / 2) . ' seconds');
        $breakEndAt = $breakStartAt->modify('+' . $breakSeconds . ' seconds');
    }
    if ($periodInstants && $actualInAt && $actualOutAt && !$invalidSequence && $scheduledStartAt && $scheduledEndAt) {
        $envelopeStart = max($actualInAt->getTimestamp(), $scheduledStartAt->getTimestamp());
        $envelopeEnd = min($actualOutAt->getTimestamp(), $scheduledEndAt->getTimestamp());
        $envelopeOverlap = max(0, (int) floor(($envelopeEnd - $envelopeStart) / 60));
        $periodOverlap = 0;
        foreach ($periodInstants as $period) {
            $overlapStart = max($actualInAt->getTimestamp(), $period['start']->getTimestamp());
            $overlapEnd = min($actualOutAt->getTimestamp(), $period['end']->getTimestamp());
            $periodOverlap += max(0, (int) floor(($overlapEnd - $overlapStart) / 60));
        }
        // Only actual presence during a configured gap is unpaid. Arriving
        // after the gap never causes an unrelated break to be deducted.
        $breakDeductedMinutes = max(0, $envelopeOverlap - $periodOverlap);
    } elseif ($actualInAt && $actualOutAt && !$invalidSequence && $breakStartAt && $breakEndAt) {
        $breakDeductedMinutes = $overlapMinutes($actualInAt, $actualOutAt, $breakStartAt, $breakEndAt);
    }
    $workedMinutes = max(0, $elapsedMinutes - $breakDeductedMinutes);
    // Grace applies to the calculated duration as well as the status. Work in
    // whole minutes so 08:15:59 is still within a 15-minute grace period,
    // while 08:16:00 records one late minute for an 08:00 shift.
    $rawLateMinutes = 0;
    if ($actualInAt && $periodInstants) {
        foreach ($periodInstants as $period) {
            if ($actualInAt >= $period['end']) {
                $rawLateMinutes += $period['minutes'];
            } elseif ($actualInAt > $period['start']) {
                $rawLateMinutes += (int) floor(
                    ($actualInAt->getTimestamp() - $period['start']->getTimestamp()) / 60
                );
                break;
            } else {
                break;
            }
        }
    } elseif ($actualInAt && $scheduledStartAt) {
        $lateEndAt = $scheduledEndAt && $actualInAt > $scheduledEndAt ? $scheduledEndAt : $actualInAt;
        $rawLateMinutes = max(
            0,
            (int) floor(($lateEndAt->getTimestamp() - $scheduledStartAt->getTimestamp()) / 60)
        );
        if ($breakStartAt && $breakEndAt && $lateEndAt > $scheduledStartAt) {
            $rawLateMinutes = max(
                0,
                $rawLateMinutes - $overlapMinutes($scheduledStartAt, $lateEndAt, $breakStartAt, $breakEndAt)
            );
        }
    }
    $lateMinutes = max(0, $rawLateMinutes - max(0, $graceMinutes));

    $overtimeMinutes = 0;
    if ($actualInAt && $actualOutAt && !$invalidSequence && $scheduledEndAt) {
        $overtimeStart = $actualInAt > $scheduledEndAt ? $actualInAt : $scheduledEndAt;
        $overtimeMinutes = max(
            0,
            min(
                $workedMinutes,
                (int) floor(($actualOutAt->getTimestamp() - $overtimeStart->getTimestamp()) / 60)
            )
        );
    }

    $undertimeMinutes = 0;
    if ($actualOutAt && !$invalidSequence && $periodInstants) {
        foreach ($periodInstants as $period) {
            if ($actualOutAt <= $period['start']) {
                $undertimeMinutes += $period['minutes'];
            } elseif ($actualOutAt < $period['end']) {
                $undertimeMinutes += (int) floor(
                    ($period['end']->getTimestamp() - $actualOutAt->getTimestamp()) / 60
                );
            }
        }
    } elseif ($actualOutAt && !$invalidSequence && $scheduledEndAt && $actualOutAt < $scheduledEndAt) {
        $undertimeMinutes = max(
            0,
            (int) floor(($scheduledEndAt->getTimestamp() - $actualOutAt->getTimestamp()) / 60)
        );
        if ($breakStartAt && $breakEndAt) {
            $underStartAt = $actualOutAt > $scheduledStartAt ? $actualOutAt : $scheduledStartAt;
            $undertimeMinutes = max(
                0,
                $undertimeMinutes - $overlapMinutes($underStartAt, $scheduledEndAt, $breakStartAt, $breakEndAt)
            );
        }
    }

    $regularMinutes = 0;
    if ($actualInAt && $actualOutAt && !$invalidSequence && $periodInstants) {
        foreach ($periodInstants as $period) {
            $regularStart = max($actualInAt->getTimestamp(), $period['start']->getTimestamp());
            $regularEnd = min($actualOutAt->getTimestamp(), $period['end']->getTimestamp());
            $regularMinutes += max(0, (int) floor(($regularEnd - $regularStart) / 60));
        }
        $regularMinutes = min($regularMinutes, $workedMinutes);
    } elseif ($actualInAt && $actualOutAt && !$invalidSequence && $scheduledStartAt && $scheduledEndAt) {
        $regularStart = max($actualInAt->getTimestamp(), $scheduledStartAt->getTimestamp());
        $regularEnd = min($actualOutAt->getTimestamp(), $scheduledEndAt->getTimestamp());
        $regularMinutes = max(0, (int) floor(($regularEnd - $regularStart) / 60));
        $regularMinutes = max(0, $regularMinutes - $breakDeductedMinutes);
        $regularMinutes = min($regularMinutes, $workedMinutes);
    }

    $scheduledMinutes = $schedulePeriods
        ? array_sum(array_map(static fn(array $period): int => (int) $period['minutes'], $schedulePeriods))
        : (($scheduledStartAt && $scheduledEndAt)
            ? max(0, intdiv($scheduledEndAt->getTimestamp() - $scheduledStartAt->getTimestamp(), 60) - max(0, $breakMinutes))
            : 0);
    $attendancePercent = $scheduledMinutes > 0
        ? min(100.0, ($regularMinutes / $scheduledMinutes) * 100)
        : 0.0;
    $halfDayMinimumPercent = max(1, min(99, $halfDayMinimumPercent));
    $fullDayMinimumPercent = max($halfDayMinimumPercent + 1, min(100, $fullDayMinimumPercent));

    // A conventional full-day schedule with a centered unpaid break has two
    // recognizable half-day sessions. For an 08:00-17:00 schedule with a
    // 60-minute break these resolve to 08:00-12:00 and 13:00-17:00. The same
    // rule is derived from each employee's own schedule, never hard-coded.
    $halfDaySession = null;
    $requiresCompleteHalfSession = false;
    if ($actualInAt && $actualOutAt && !$invalidSequence && count($periodInstants) === 2) {
        $firstSession = $periodInstants[0];
        $secondSession = $periodInstants[1];
        $requiresCompleteHalfSession = $scheduledMinutes >= 360
            && $firstSession['minutes'] >= 120
            && $secondSession['minutes'] >= 120
            && abs($firstSession['minutes'] - $secondSession['minutes']) <= 15;
        if ($requiresCompleteHalfSession) {
            $firstGraceCutoff = $firstSession['start']->modify('+' . max(0, $graceMinutes) . ' minutes');
            $secondGraceCutoff = $secondSession['start']->modify('+' . max(0, $graceMinutes) . ' minutes');
            if ($actualInAt <= $firstGraceCutoff && $actualOutAt >= $firstSession['end']) {
                $halfDaySession = 'MORNING';
            } elseif ($actualInAt <= $secondGraceCutoff && $actualOutAt >= $secondSession['end']) {
                $halfDaySession = 'AFTERNOON';
            }
        }
    } elseif (
        $actualInAt && $actualOutAt && !$invalidSequence
        && $scheduledStartAt && $scheduledEndAt && $breakStartAt && $breakEndAt
    ) {
        $morningMinutes = max(0, intdiv($breakStartAt->getTimestamp() - $scheduledStartAt->getTimestamp(), 60));
        $afternoonMinutes = max(0, intdiv($scheduledEndAt->getTimestamp() - $breakEndAt->getTimestamp(), 60));
        $requiresCompleteHalfSession = $scheduledMinutes >= 360
            && $morningMinutes >= 120
            && $afternoonMinutes >= 120
            && abs($morningMinutes - $afternoonMinutes) <= 15;
        if ($requiresCompleteHalfSession) {
            $graceCutoff = $scheduledStartAt->modify('+' . max(0, $graceMinutes) . ' minutes');
            $afternoonGraceCutoff = $breakEndAt->modify('+' . max(0, $graceMinutes) . ' minutes');
            if ($actualInAt <= $graceCutoff && $actualOutAt >= $breakStartAt) {
                $halfDaySession = 'MORNING';
            } elseif ($actualInAt <= $afternoonGraceCutoff && $actualOutAt >= $scheduledEndAt) {
                $halfDaySession = 'AFTERNOON';
            }
        }
    }

    $classificationStatus = null;
    if ($actualInAt && !$invalidSequence) {
        if (!$actualOutAt) {
            $classificationStatus = 'INCOMPLETE';
        } elseif ($scheduleType === ATTENDANCE_SCHEDULE_WORK && $scheduledMinutes > 0) {
            if ($attendancePercent >= $fullDayMinimumPercent) {
                $classificationStatus = 'PRESENT';
            } elseif ($halfDaySession !== null) {
                $classificationStatus = 'HALF_DAY';
            } elseif (!$requiresCompleteHalfSession && $attendancePercent >= $halfDayMinimumPercent) {
                $classificationStatus = 'HALF_DAY';
            } else {
                $classificationStatus = 'ABSENT';
            }
        } else {
            $classificationStatus = 'PRESENT';
        }
    }

    // `status` keeps the original helper contract for installations that have
    // not applied the integrated enum migration. The deterministic processor
    // uses classification_status and writes the complete uppercase vocabulary.
    $status = $actualInAt && !$invalidSequence
        ? ($lateMinutes > 0 ? 'Late' : 'Present')
        : null;

    return [
        'elapsed_minutes' => $elapsedMinutes,
        'break_deducted_minutes' => $breakDeductedMinutes,
        'worked_minutes' => $workedMinutes,
        'regular_minutes' => $regularMinutes,
        'scheduled_minutes' => $scheduledMinutes,
        'attendance_percent' => round($attendancePercent, 2),
        'half_day_session' => $halfDaySession,
        'late_minutes' => $lateMinutes,
        'undertime_minutes' => $undertimeMinutes,
        'overtime_minutes' => $overtimeMinutes,
        'potential_overtime_minutes' => $overtimeMinutes,
        'status' => $status,
        'classification_status' => $classificationStatus,
        'invalid_sequence' => $invalidSequence,
        'actual_out_at' => $actualOutAt,
        'scheduled_start_at' => $scheduledStartAt,
        'scheduled_end_at' => $scheduledEndAt,
        'break_start_at' => $breakStartAt,
        'break_end_at' => $breakEndAt,
        'schedule_type' => $scheduleType,
        'schedule_periods' => $schedulePeriods,
    ];
}

function attendance_sync_current_schedule(
    PDO $pdo,
    int $employeeId,
    string $dayOfWeek,
    ?string $shiftStart = null,
    ?string $shiftEnd = null,
    ?string $scheduleType = null,
    ?int $breakMinutes = null,
    array $schedulePeriods = []
): int {
    if (!attendance_has_metric_columns($pdo)) {
        return 0;
    }

    $hasSnapshots = attendance_has_schedule_snapshot_columns($pdo);
    $hasBreakSnapshot = isset(attendance_table_columns($pdo, 'attendance')['break_minutes']);
    $hasPeriodSnapshot = isset(attendance_table_columns($pdo, 'attendance')['schedule_periods_snapshot']);
    $snapshotSelect = $hasSnapshots ? ', schedule_type, schedule_source' : '';
    $snapshotSelect .= $hasPeriodSnapshot ? ', schedule_periods_snapshot' : '';
    $snapshotSelect .= $hasBreakSnapshot ? ', break_minutes' : '';
    $snapshotMissing = $hasSnapshots
        ? 'schedule_type IS NULL'
        : '(expected_time_in IS NULL AND expected_time_out IS NULL)';
    $stmt = $pdo->prepare(
        'SELECT id, employee_id, scan_date, time_in, time_out, status,
                expected_time_in, expected_time_out' . $snapshotSelect . '
         FROM attendance
         WHERE employee_id = ?
           AND DAYNAME(scan_date) = ?
           AND (time_out IS NULL OR ' . $snapshotMissing . ')
         FOR UPDATE'
    );
    $stmt->execute([$employeeId, $dayOfWeek]);
    $records = $stmt->fetchAll();
    $graceMinutes = attendance_grace_minutes($pdo);
    $explicit = $scheduleType !== null || $shiftStart !== null || $shiftEnd !== null;

    $integrated = attendance_has_integrated_metric_columns($pdo);
    $sets = [
        'expected_time_in = ?',
        'expected_time_out = ?',
        'worked_minutes = ?',
        'late_minutes = ?',
        'overtime_minutes = ?',
        'status = ?',
    ];
    if ($hasSnapshots) {
        $sets[] = 'schedule_type = ?';
        $sets[] = 'schedule_source = ?';
    }
    if ($hasPeriodSnapshot) {
        $sets[] = 'schedule_periods_snapshot = ?';
    }
    if ($hasBreakSnapshot) {
        $sets[] = 'break_minutes = ?';
    }
    if ($integrated) {
        $sets[] = 'regular_minutes = ?';
        $sets[] = 'undertime_minutes = ?';
        $sets[] = 'potential_overtime_minutes = ?';
        $sets[] = 'processed_at = NOW()';
        $sets[] = 'processing_version = 3';
    }
    $sql = 'UPDATE attendance SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $update = $pdo->prepare($sql);

    foreach ($records as $record) {
        $isCompleted = !empty($record['time_out']);
        $hasExistingSnapshot = $hasSnapshots
            ? !empty($record['schedule_type'])
            : (!empty($record['expected_time_in']) || !empty($record['expected_time_out']));

        if ($isCompleted && $hasExistingSnapshot) {
            continue;
        }

        if (
            !$explicit && !$hasExistingSnapshot
            && (!empty($record['expected_time_in']) || !empty($record['expected_time_out']))
        ) {
            $schedule = attendance_schedule_result(
                ATTENDANCE_SCHEDULE_WORK,
                $record['expected_time_in'] ?? null,
                $record['expected_time_out'] ?? null,
                'Existing Snapshot',
                $record['break_minutes'] ?? 0,
                attendance_decode_schedule_periods($record['schedule_periods_snapshot'] ?? null)
            );
        } elseif ($explicit) {
            $schedule = attendance_schedule_result(
                $scheduleType ?? ATTENDANCE_SCHEDULE_WORK,
                $shiftStart,
                $shiftEnd,
                'Employee',
                $breakMinutes ?? attendance_default_break_minutes($pdo),
                $schedulePeriods
            );
        } else {
            $schedule = attendance_schedule($pdo, $employeeId, (string) $record['scan_date']);
        }

        if ($isCompleted && !$hasExistingSnapshot) {
            $schedule['schedule_source'] = match ($schedule['schedule_source']) {
                'Employee' => 'Backfill Employee',
                'Default' => 'Backfill Default',
                default => $schedule['schedule_source'],
            };
        }

        $metrics = attendance_calculate_metrics(
            (string) $record['scan_date'],
            $record['time_in'] ?: null,
            $record['time_out'] ?: null,
            $schedule['shift_start'],
            $schedule['shift_end'],
            $graceMinutes,
            $schedule['schedule_type'],
            (int) $schedule['break_minutes'],
            $schedule['periods'],
            attendance_half_day_minimum_percent($pdo),
            attendance_full_day_minimum_percent($pdo)
        );
        // Integrated attendance uses the full uppercase classification
        // vocabulary. In particular, an accepted Time In without Time Out
        // must stay INCOMPLETE when a schedule edit recalculates its metrics.
        // `status` remains the compatibility value for legacy installations.
        $calculatedStatus = $integrated
            ? $metrics['classification_status']
            : $metrics['status'];
        $status = in_array((string) $record['status'], ['Present', 'Late', 'Absent', 'PRESENT', 'LATE', 'UNDERTIME', 'LATE_AND_UNDERTIME', 'HALF_DAY', 'ABSENT', 'INCOMPLETE'], true) && $calculatedStatus
            ? $calculatedStatus
            : (string) $record['status'];

        $parameters = [
            $schedule['shift_start'],
            $schedule['shift_end'],
            $metrics['worked_minutes'],
            $metrics['late_minutes'],
            $metrics['overtime_minutes'],
            $status,
        ];
        if ($hasSnapshots) {
            $parameters[] = $schedule['schedule_type'];
            $parameters[] = $schedule['schedule_source'];
        }
        if ($hasPeriodSnapshot) {
            $parameters[] = attendance_schedule_periods_json($schedule['periods']);
        }
        if ($hasBreakSnapshot) {
            $parameters[] = (int) $schedule['break_minutes'];
        }
        if ($integrated) {
            $parameters[] = (int) $metrics['regular_minutes'];
            $parameters[] = (int) $metrics['undertime_minutes'];
            $parameters[] = (int) $metrics['potential_overtime_minutes'];
        }
        $parameters[] = (int) $record['id'];
        $update->execute($parameters);
    }

    return count($records);
}

/** Freeze a resolution onto every legacy row that does not have schedule_type. */
function attendance_backfill_missing_schedule_snapshots(PDO $pdo, ?int $employeeId = null): int
{
    if (!attendance_has_metric_columns($pdo) || !attendance_has_schedule_snapshot_columns($pdo)) {
        return 0;
    }

    $sql = 'SELECT DISTINCT employee_id, DAYNAME(scan_date) AS day_of_week
            FROM attendance
            WHERE schedule_type IS NULL';
    $parameters = [];
    if ($employeeId !== null) {
        $sql .= ' AND employee_id = ?';
        $parameters[] = $employeeId;
    }
    $rows = $pdo->prepare($sql);
    $rows->execute($parameters);

    $updated = 0;
    foreach ($rows->fetchAll() as $row) {
        $updated += attendance_sync_current_schedule(
            $pdo,
            (int) $row['employee_id'],
            (string) $row['day_of_week']
        );
    }
    return $updated;
}

/**
 * Save one complete seven-day employee assignment. Work days have explicit
 * Expected In/Out values; every other day is stored as an explicit Rest Day.
 * The caller owns the transaction so schedule rows and attendance updates are
 * committed together.
 */
function attendance_save_weekly_schedule(PDO $pdo, int $employeeId, array $scheduleByDay): int
{
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    if ($employeeId < 1) {
        throw new InvalidArgumentException('A valid employee is required.');
    }

    $hasBreak = isset(attendance_table_columns($pdo, 'work_schedules')['break_minutes']);
    $hasPeriods = attendance_has_period_schedule_schema($pdo);
    $save = $pdo->prepare(
        $hasBreak
            ? 'INSERT INTO work_schedules
              (employee_id, day_of_week, schedule_type, shift_start, shift_end, break_minutes)
           VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE
              schedule_type=VALUES(schedule_type), shift_start=VALUES(shift_start),
              shift_end=VALUES(shift_end), break_minutes=VALUES(break_minutes),
              id=LAST_INSERT_ID(id)'
            : 'INSERT INTO work_schedules
              (employee_id, day_of_week, schedule_type, shift_start, shift_end)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE
              schedule_type=VALUES(schedule_type), shift_start=VALUES(shift_start),
              shift_end=VALUES(shift_end), id=LAST_INSERT_ID(id)'
    );
    $deletePeriods = $hasPeriods
        ? $pdo->prepare('DELETE FROM work_schedule_periods WHERE work_schedule_id=?')
        : null;
    $insertPeriod = $hasPeriods
        ? $pdo->prepare(
            'INSERT INTO work_schedule_periods
                (work_schedule_id, period_order, period_start, period_end)
             VALUES (?, ?, ?, ?)'
        )
        : null;

    $syncedRecords = 0;
    foreach ($days as $day) {
        if (!isset($scheduleByDay[$day]) || !is_array($scheduleByDay[$day])) {
            throw new InvalidArgumentException('The weekly schedule must contain all seven days.');
        }
        $row = $scheduleByDay[$day];
        $type = attendance_schedule_type((string) ($row['schedule_type'] ?? ''));
        if (!in_array($type, [ATTENDANCE_SCHEDULE_WORK, ATTENDANCE_SCHEDULE_OFF], true)) {
            throw new InvalidArgumentException('Each day must be a Work Day or Rest Day.');
        }

        $periods = $type === ATTENDANCE_SCHEDULE_WORK
            ? attendance_normalize_schedule_periods((array) ($row['periods'] ?? []))
            : [];
        if (!empty($row['periods']) && !$periods) {
            throw new InvalidArgumentException($day . ' contains invalid or overlapping flexible periods.');
        }
        if ($periods && !$hasPeriods) {
            throw new RuntimeException('Import database/part_time_schedule_periods_update.sql before saving flexible periods.');
        }

        $start = $type === ATTENDANCE_SCHEDULE_WORK
            ? attendance_valid_time($row['shift_start'] ?? null)
            : null;
        $end = $type === ATTENDANCE_SCHEDULE_WORK
            ? attendance_valid_time($row['shift_end'] ?? null)
            : null;
        $dayBreakMinutes = $type === ATTENDANCE_SCHEDULE_WORK
            ? max(0, (int) ($row['break_minutes'] ?? attendance_default_break_minutes($pdo)))
            : 0;
        if ($periods) {
            $summary = attendance_schedule_period_summary($periods);
            $start = $summary['shift_start'];
            $end = $summary['shift_end'];
            $dayBreakMinutes = $summary['gap_minutes'];
        }
        if ($type === ATTENDANCE_SCHEDULE_WORK && (!$start || !$end || $start === $end)) {
            throw new InvalidArgumentException($day . ' requires different, valid Expected In and Expected Out times.');
        }

        $parameters = [$employeeId, $day, $type, $start, $end];
        if ($hasBreak) {
            $parameters[] = $dayBreakMinutes;
        }
        $save->execute($parameters);
        $workScheduleId = (int) $pdo->lastInsertId();
        if ($hasPeriods && $workScheduleId > 0 && $deletePeriods && $insertPeriod) {
            $deletePeriods->execute([$workScheduleId]);
            foreach ($periods as $index => $period) {
                $insertPeriod->execute([
                    $workScheduleId,
                    $index + 1,
                    $period['period_start'],
                    $period['period_end'],
                ]);
            }
        }
        $syncedRecords += attendance_sync_current_schedule(
            $pdo,
            $employeeId,
            $day,
            $start,
            $end,
            $type,
            $dayBreakMinutes,
            $periods
        );
    }

    return $syncedRecords;
}