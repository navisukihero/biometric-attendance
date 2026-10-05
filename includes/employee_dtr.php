<?php

declare(strict_types=1);

require_once __DIR__ . '/attendance_schedule.php';

/** @return array{start:DateTimeImmutable,end:DateTimeImmutable,error:string} */
function employee_dtr_resolve_range(?string $startInput, ?string $endInput): array
{
    $today = new DateTimeImmutable('today');
    $dayOfMonth = (int) $today->format('j');
    $defaultStart = $dayOfMonth <= 15
        ? $today->modify('first day of this month')
        : $today->setDate((int) $today->format('Y'), (int) $today->format('n'), 16);
    $defaultEnd = $dayOfMonth <= 15
        ? $today->setDate((int) $today->format('Y'), (int) $today->format('n'), 15)
        : $today->modify('last day of this month');

    if ($startInput === null && $endInput === null) {
        return ['start' => $defaultStart, 'end' => $defaultEnd, 'error' => ''];
    }

    $startInput = trim((string) $startInput);
    $endInput = trim((string) $endInput);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startInput);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endInput);

    if (
        !$start || $start->format('Y-m-d') !== $startInput
        || !$end || $end->format('Y-m-d') !== $endInput
    ) {
        return ['start' => $defaultStart, 'end' => $defaultEnd, 'error' => 'Select a valid Range Start and Range End.'];
    }
    if ($end < $start) {
        return ['start' => $start, 'end' => $end, 'error' => 'Range End must be on or after Range Start.'];
    }
    if ((int) $start->diff($end)->days + 1 > 366) {
        return ['start' => $start, 'end' => $end, 'error' => 'Generate a maximum of 366 calendar days at one time.'];
    }

    return ['start' => $start, 'end' => $end, 'error' => ''];
}

function employee_dtr_time(mixed $time): string
{
    $time = trim((string) $time);
    if ($time === '') {
        return '—';
    }
    $timestamp = strtotime($time);
    return $timestamp === false ? '—' : date('g:i A', $timestamp);
}

function employee_dtr_minutes(int $minutes, bool $showZero = true): string
{
    $minutes = max(0, $minutes);
    if ($minutes === 0 && !$showZero) {
        return '—';
    }
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;
    if ($hours === 0) {
        return $remainder . 'm';
    }
    return $hours . 'h' . ($remainder > 0 ? ' ' . $remainder . 'm' : '');
}

function employee_dtr_expected_periods(array $record): string
{
    $periods = attendance_decode_schedule_periods($record['schedule_periods_snapshot'] ?? null);
    if ($periods) {
        return implode(' / ', array_map(
            static fn(array $period): string => employee_dtr_time($period['period_start'])
                . '–' . employee_dtr_time($period['period_end']),
            $periods
        ));
    }

    $expectedIn = employee_dtr_time($record['expected_time_in'] ?? null);
    $expectedOut = employee_dtr_time($record['expected_time_out'] ?? null);
    return $expectedIn === '—' && $expectedOut === '—'
        ? '—'
        : $expectedIn . '–' . $expectedOut;
}

function employee_dtr_status_label(mixed $status): string
{
    $status = trim((string) $status);
    if ($status === '') {
        return 'No record';
    }
    return ucwords(strtolower(str_replace(['_', '-'], ' ', $status)));
}

/**
 * Build one read-only DTR from the processed attendance table.
 *
 * No employee identifier is accepted from request data. The caller supplies
 * the employee returned by employee_require_login(), and the prepared query is
 * always scoped to that authenticated employee_id.
 */
function employee_dtr_build(
    PDO $pdo,
    array $employee,
    DateTimeImmutable $start,
    DateTimeImmutable $end
): array {
    $employeeId = (int) ($employee['employee_id'] ?? 0);
    if ($employeeId < 1) {
        throw new InvalidArgumentException('A valid authenticated employee is required.');
    }

    $attendanceStmt = $pdo->prepare(
        'SELECT scan_date, expected_time_in, expected_time_out, schedule_periods_snapshot,
                time_in, time_out, schedule_type, break_minutes, worked_minutes,
                regular_minutes, late_minutes, undertime_minutes,
                potential_overtime_minutes, approved_overtime_minutes,
                day_classification, status, source, notes
         FROM attendance
         WHERE employee_id=? AND scan_date BETWEEN ? AND ?
         ORDER BY scan_date, id'
    );
    $attendanceStmt->execute([$employeeId, $start->format('Y-m-d'), $end->format('Y-m-d')]);
    $attendanceByDate = [];
    foreach ($attendanceStmt->fetchAll() as $record) {
        $attendanceByDate[(string) $record['scan_date']] = $record;
    }

    $holidayByDate = [];
    try {
        $holidayStmt = $pdo->prepare(
            'SELECT holiday_date, holiday_name
             FROM holidays
             WHERE status="Active" AND holiday_date BETWEEN ? AND ?'
        );
        $holidayStmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
        foreach ($holidayStmt->fetchAll() as $holiday) {
            $holidayByDate[(string) $holiday['holiday_date']] = (string) $holiday['holiday_name'];
        }
    } catch (Throwable) {
        // The DTR remains available on installations without holiday metadata.
    }

    $rows = [];
    $totals = [
        'attendance_days' => 0,
        'worked_minutes' => 0,
        'regular_minutes' => 0,
        'late_minutes' => 0,
        'undertime_minutes' => 0,
        'approved_overtime_minutes' => 0,
        'late_count' => 0,
        'undertime_count' => 0,
        'incomplete_count' => 0,
    ];
    $today = new DateTimeImmutable('today');

    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $dateKey = $date->format('Y-m-d');
        $record = $attendanceByDate[$dateKey] ?? null;
        if ($record) {
            $workedMinutes = max(0, (int) $record['worked_minutes']);
            $regularMinutes = max(0, (int) $record['regular_minutes']);
            $lateMinutes = max(0, (int) $record['late_minutes']);
            $undertimeMinutes = max(0, (int) $record['undertime_minutes']);
            $approvedOvertimeMinutes = max(0, (int) $record['approved_overtime_minutes']);
            $hasAttendance = !empty($record['time_in']) || !empty($record['time_out']);
            $isIncomplete = !empty($record['time_in']) && empty($record['time_out']);

            if ($hasAttendance) {
                $totals['attendance_days']++;
            }
            $totals['worked_minutes'] += $workedMinutes;
            $totals['regular_minutes'] += $regularMinutes;
            $totals['late_minutes'] += $lateMinutes;
            $totals['undertime_minutes'] += $undertimeMinutes;
            $totals['approved_overtime_minutes'] += $approvedOvertimeMinutes;
            $totals['late_count'] += $lateMinutes > 0 ? 1 : 0;
            $totals['undertime_count'] += $undertimeMinutes > 0 ? 1 : 0;
            $totals['incomplete_count'] += $isIncomplete ? 1 : 0;

            $status = employee_dtr_status_label($record['status'] ?? '');
            $classification = trim((string) ($record['day_classification'] ?? ''));
            $source = trim((string) ($record['source'] ?? ''));
            $rows[] = [
                'date' => $dateKey,
                'date_label' => $date->format('M j, Y'),
                'day' => $date->format('l'),
                'expected' => employee_dtr_expected_periods($record),
                'time_in' => employee_dtr_time($record['time_in'] ?? null),
                'time_out' => employee_dtr_time($record['time_out'] ?? null),
                'worked' => employee_dtr_minutes($workedMinutes, false),
                'late' => employee_dtr_minutes($lateMinutes, false),
                'undertime' => employee_dtr_minutes($undertimeMinutes, false),
                'approved_overtime' => employee_dtr_minutes($approvedOvertimeMinutes, false),
                'status' => $status,
                'detail' => implode(' · ', array_filter([$classification, $source])),
                'has_record' => true,
            ];
            continue;
        }

        $holidayName = $holidayByDate[$dateKey] ?? '';
        $rows[] = [
            'date' => $dateKey,
            'date_label' => $date->format('M j, Y'),
            'day' => $date->format('l'),
            'expected' => '—',
            'time_in' => '—',
            'time_out' => '—',
            'worked' => '—',
            'late' => '—',
            'undertime' => '—',
            'approved_overtime' => '—',
            'status' => $holidayName !== '' ? 'Holiday' : ($date > $today ? 'Pending' : 'No record'),
            'detail' => $holidayName,
            'has_record' => false,
        ];
    }

    $middleName = trim((string) ($employee['middle_name'] ?? ''));
    $employeeName = trim(
        (string) ($employee['first_name'] ?? '') . ' '
            . ($middleName !== '' ? $middleName . ' ' : '')
            . (string) ($employee['last_name'] ?? '')
    );

    return [
        'employee' => [
            'name' => $employeeName,
            'employee_no' => (string) ($employee['employee_no'] ?? ''),
            'department' => (string) (($employee['department'] ?? '') ?: '—'),
            'position' => (string) (($employee['position'] ?? '') ?: '—'),
        ],
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'period_label' => $start->format('F j, Y') . ' – ' . $end->format('F j, Y'),
        'generated_at' => date('F j, Y · g:i A'),
        'rows' => $rows,
        'totals' => $totals,
    ];
}
