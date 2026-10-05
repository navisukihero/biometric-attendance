<?php

declare(strict_types=1);

require_once __DIR__ . '/attendance_processing.php';

/**
 * Integrated payroll service.
 *
 * Public write operations own their transaction and serialize payroll changes
 * with a database-scoped advisory lock.  The daily attendance projection is
 * refreshed before any money is calculated; payroll_item_attendance then owns
 * every daily row consumed by a run.
 */

const PAYROLL_CALCULATION_VERSION = 10;
const PAYROLL_SOURCE_FINGERPRINT_VERSION = 8;

/** @return array{start: DateTimeImmutable, end: DateTimeImmutable} */
function payroll_validate_period(string $periodStart, string $periodEnd): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $periodEnd);
    if (
        !$start || $start->format('Y-m-d') !== $periodStart
        || !$end || $end->format('Y-m-d') !== $periodEnd
    ) {
        throw new InvalidArgumentException('Payroll dates must use YYYY-MM-DD.');
    }
    if ($end < $start) {
        throw new InvalidArgumentException('Payroll period end cannot precede its start.');
    }
    if ((int) $start->diff($end)->days > 366) {
        throw new InvalidArgumentException('A payroll period cannot exceed 367 inclusive days.');
    }
    if ($end > new DateTimeImmutable('today')) {
        throw new InvalidArgumentException('A payroll period cannot end in the future.');
    }

    return ['start' => $start, 'end' => $end];
}

function payroll_json(array $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
}

function payroll_round_amount(float $amount, string $rule = 'nearest_cent'): float
{
    if (!is_finite($amount)) {
        throw new InvalidArgumentException('A payroll amount is not finite.');
    }

    return match ($rule) {
        'nearest_cent' => round($amount, 2, PHP_ROUND_HALF_UP),
        'nearest_peso' => round($amount, 0, PHP_ROUND_HALF_UP),
        'truncate_cent' => ($amount >= 0
            ? floor(($amount + 0.0000001) * 100)
            : ceil(($amount - 0.0000001) * 100)) / 100,
        // Retain the original low-level aliases for backward compatibility
        // with callers that used them before the settings UI was added.
        'floor_cent' => floor(($amount + 0.0000001) * 100) / 100,
        'ceil_cent' => ceil(($amount - 0.0000001) * 100) / 100,
        default => throw new InvalidArgumentException('Unsupported payroll rounding rule: ' . $rule),
    };
}

/**
 * Convert the configured compensation rate into daily and hourly equivalents.
 * The supplied hours are the applicable scheduled day's duration and the day
 * basis is the number of scheduled workdays in the selected payroll period.
 * These equivalents are used only for attendance deductions and overtime.
 *
 * @return array{daily_rate: float, hourly_rate: float}
 */
function payroll_derive_rates(
    string $payType,
    float $basicRate,
    float $scheduledHoursForDay,
    float $scheduledWorkdayBasis
): array {
    if (!in_array($payType, ['Monthly', 'Daily', 'Hourly'], true)) {
        throw new InvalidArgumentException('Unsupported pay type: ' . $payType);
    }
    if (
        !is_finite($basicRate) || $basicRate <= 0
        || !is_finite($scheduledHoursForDay) || $scheduledHoursForDay <= 0
        || !is_finite($scheduledWorkdayBasis) || $scheduledWorkdayBasis <= 0
    ) {
        throw new InvalidArgumentException('Basic rate and schedule-derived hours/workdays must be positive.');
    }

    $dailyRate = match ($payType) {
        'Monthly' => $basicRate / $scheduledWorkdayBasis,
        'Daily' => $basicRate,
        'Hourly' => $basicRate * $scheduledHoursForDay,
    };
    $hourlyRate = $payType === 'Hourly' ? $basicRate : $dailyRate / $scheduledHoursForDay;

    return ['daily_rate' => $dailyRate, 'hourly_rate' => $hourlyRate];
}

/**
 * Calculate expected basic salary without changing the configured rate unit.
 * Hourly and Daily use the schedule resolved for the requested period;
 * Monthly remains the employee's configured monthly salary.
 */
function payroll_expected_monthly_amount(
    string $payType,
    float $basicRate,
    int $scheduledDays,
    int $scheduledMinutes
): float {
    if (!in_array($payType, ['Monthly', 'Daily', 'Hourly'], true)) {
        throw new InvalidArgumentException('Unsupported pay type: ' . $payType);
    }
    if (!is_finite($basicRate) || $basicRate <= 0 || $scheduledDays < 0 || $scheduledMinutes < 0) {
        throw new InvalidArgumentException('Expected salary inputs must be valid non-negative schedule values and a positive rate.');
    }

    return round(match ($payType) {
        'Monthly' => $basicRate,
        'Daily' => $basicRate * $scheduledDays,
        'Hourly' => $basicRate * ($scheduledMinutes / 60),
    }, 2, PHP_ROUND_HALF_UP);
}

/**
 * Resolve a read-only estimate using the employee's schedule for an exact
 * date range. This common basis ensures a weekly schedule is never multiplied
 * by a fixed number of calendar days.
 *
 * @return array{period_start:string,period_end:string,amount:float,scheduled_days:int,scheduled_minutes:int,sources:array<int,string>}
 */
function payroll_expected_period_estimate(
    PDO $pdo,
    int $employeeId,
    string $payType,
    float $basicRate,
    DateTimeImmutable $periodStart,
    DateTimeImmutable $periodEnd
): array {
    if ($employeeId < 1) {
        throw new InvalidArgumentException('A valid employee is required for the expected salary estimate.');
    }
    if ($periodEnd < $periodStart) {
        throw new InvalidArgumentException('Expected salary period end cannot precede its start.');
    }
    if ((int) $periodStart->diff($periodEnd)->days > 366) {
        throw new InvalidArgumentException('Expected salary period cannot exceed 367 inclusive days.');
    }

    $periodStart = $periodStart->setTime(0, 0);
    $periodEnd = $periodEnd->setTime(0, 0);
    $scheduledDays = 0;
    $scheduledMinutes = 0;
    $sources = [];

    for ($date = $periodStart; $date <= $periodEnd; $date = $date->modify('+1 day')) {
        $schedule = attendance_schedule($pdo, $employeeId, $date->format('Y-m-d'));
        if (($schedule['schedule_type'] ?? '') !== ATTENDANCE_SCHEDULE_WORK) {
            continue;
        }
        $metrics = attendance_calculate_metrics(
            $date->format('Y-m-d'),
            null,
            null,
            $schedule['shift_start'] ?? null,
            $schedule['shift_end'] ?? null,
            0,
            (string) $schedule['schedule_type'],
            (int) ($schedule['break_minutes'] ?? 0),
            (array) ($schedule['periods'] ?? [])
        );
        $dayMinutes = max(0, (int) ($metrics['scheduled_minutes'] ?? 0));
        if ($dayMinutes < 1) {
            continue;
        }
        $scheduledDays++;
        $scheduledMinutes += $dayMinutes;
        $source = trim((string) ($schedule['schedule_source'] ?? ''));
        if ($source !== '') {
            $sources[$source] = true;
        }
    }

    return [
        'period_start' => $periodStart->format('Y-m-d'),
        'period_end' => $periodEnd->format('Y-m-d'),
        'amount' => payroll_expected_monthly_amount($payType, $basicRate, $scheduledDays, $scheduledMinutes),
        'scheduled_days' => $scheduledDays,
        'scheduled_minutes' => $scheduledMinutes,
        'sources' => array_keys($sources),
    ];
}

/**
 * Resolve a calendar-month estimate while retaining the established helper
 * used by Employee Records and employee self-service pages.
 *
 * @return array{month:string,period_start:string,period_end:string,amount:float,scheduled_days:int,scheduled_minutes:int,sources:array<int,string>}
 */
function payroll_expected_monthly_estimate(
    PDO $pdo,
    int $employeeId,
    string $payType,
    float $basicRate,
    DateTimeImmutable $month
): array {
    $monthStart = $month->modify('first day of this month')->setTime(0, 0);
    $monthEnd = $monthStart->modify('last day of this month');
    $estimate = payroll_expected_period_estimate(
        $pdo,
        $employeeId,
        $payType,
        $basicRate,
        $monthStart,
        $monthEnd
    );
    $estimate['month'] = $monthStart->format('Y-m');

    return $estimate;
}

/**
 * Read the complete payroll policy without hidden fallbacks.  A missing or
 * malformed value stops payroll rather than silently changing compensation.
 *
 * @return array<string, bool|float|string>
 */
function payroll_load_policy(PDO $pdo): array
{
    $required = [
        'regular_hours_per_day',
        'late_deduction_enabled',
        'undertime_deduction_enabled',
        'half_day_minimum_percent',
        'full_day_minimum_percent',
        'overtime_enabled',
        'overtime_requires_approval',
        'overtime_multiplier',
        'regular_holiday_worked_multiplier',
        'regular_holiday_overtime_multiplier',
        'special_day_worked_multiplier',
        'special_day_overtime_multiplier',
        'rest_day_multiplier',
        'regular_holiday_rest_day_multiplier',
        'special_day_rest_day_multiplier',
        'rounding_rule',
        'currency',
    ];

    $placeholders = implode(',', array_fill(0, count($required), '?'));
    $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ({$placeholders})");
    $stmt->execute($required);
    $raw = [];
    foreach ($stmt->fetchAll() as $row) {
        $raw[(string) $row['key']] = trim((string) $row['value']);
    }
    foreach ($required as $key) {
        if (!array_key_exists($key, $raw) || $raw[$key] === '') {
            throw new RuntimeException("Payroll setting '{$key}' is missing. Apply the integrated payroll migration.");
        }
    }

    $boolean = static function (string $key) use ($raw): bool {
        if (!in_array($raw[$key], ['0', '1'], true)) {
            throw new RuntimeException("Payroll setting '{$key}' must be 0 or 1.");
        }
        return $raw[$key] === '1';
    };
    $number = static function (string $key, float $minimum = 0.0, ?float $maximum = null) use ($raw): float {
        if (!is_numeric($raw[$key])) {
            throw new RuntimeException("Payroll setting '{$key}' must be numeric.");
        }
        $value = (float) $raw[$key];
        if (!is_finite($value) || $value < $minimum || ($maximum !== null && $value > $maximum)) {
            throw new RuntimeException("Payroll setting '{$key}' is outside its permitted range.");
        }
        return $value;
    };

    $policy = [
        'regular_hours_per_day' => $number('regular_hours_per_day', 0.01, 24),
        'late_deduction_enabled' => $boolean('late_deduction_enabled'),
        'undertime_deduction_enabled' => $boolean('undertime_deduction_enabled'),
        'half_day_minimum_percent' => $number('half_day_minimum_percent', 1, 99),
        'full_day_minimum_percent' => $number('full_day_minimum_percent', 2, 100),
        'overtime_enabled' => $boolean('overtime_enabled'),
        'overtime_requires_approval' => $boolean('overtime_requires_approval'),
        'overtime_multiplier' => $number('overtime_multiplier', 0, 10),
        'regular_holiday_worked_multiplier' => $number('regular_holiday_worked_multiplier', 0, 10),
        'regular_holiday_overtime_multiplier' => $number('regular_holiday_overtime_multiplier', 0, 10),
        'special_day_worked_multiplier' => $number('special_day_worked_multiplier', 0, 10),
        'special_day_overtime_multiplier' => $number('special_day_overtime_multiplier', 0, 10),
        'rest_day_multiplier' => $number('rest_day_multiplier', 0, 10),
        'regular_holiday_rest_day_multiplier' => $number('regular_holiday_rest_day_multiplier', 0, 10),
        'special_day_rest_day_multiplier' => $number('special_day_rest_day_multiplier', 0, 10),
        'rounding_rule' => $raw['rounding_rule'],
        'currency' => $raw['currency'],
    ];

    if ($policy['overtime_requires_approval'] !== true) {
        throw new RuntimeException('Overtime approval is mandatory and cannot be disabled.');
    }
    if ((float) $policy['full_day_minimum_percent'] <= (float) $policy['half_day_minimum_percent']) {
        throw new RuntimeException('Full-day attendance percentage must be greater than the half-day minimum.');
    }

    // Validate the selected rule now, before a run has any database effects.
    payroll_round_amount(0, (string) $policy['rounding_rule']);
    if (!preg_match('/^[A-Z]{3}$/', (string) $policy['currency'])) {
        throw new RuntimeException('Payroll currency must be a three-letter uppercase code.');
    }

    return $policy;
}

function payroll_normalize_status(array $row): string
{
    $status = strtoupper(str_replace([' ', '-'], '_', trim((string) ($row['status'] ?? ''))));
    if ($status === 'ON_LEAVE') {
        return match ((string) ($row['day_classification'] ?? '')) {
            'Paid Leave' => 'PAID_LEAVE',
            'Unpaid Leave' => 'UNPAID_LEAVE',
            default => 'ON_LEAVE',
        };
    }

    // Backward compatibility for historical rows created by older attendance
    // processors: an Off/Rest Day with an unfinished punch used to remain
    // INCOMPLETE forever. It is not a scheduled payable day, so payroll must
    // treat it as REST_DAY (zero regular pay/deduction) rather than aborting the
    // whole draft. Current Work-day INCOMPLETE rows remain blocked below.
    if (
        $status === 'INCOMPLETE'
        && (string) ($row['schedule_type'] ?? '') === 'Off'
        && (string) ($row['day_classification'] ?? '') === 'Rest Day'
    ) {
        return 'REST_DAY';
    }

    return $status;
}

function payroll_scheduled_minutes(array $row): int
{
    $periods = attendance_decode_schedule_periods($row['schedule_periods_snapshot'] ?? null);
    if ($periods) {
        return array_sum(array_map(
            static fn(array $period): int => (int) $period['minutes'],
            $periods
        ));
    }
    $start = trim((string) ($row['expected_time_in'] ?? ''));
    $end = trim((string) ($row['expected_time_out'] ?? ''));
    if ($start === '' || $end === '') {
        return 0;
    }
    $anchor = '2000-01-01 ';
    try {
        $startAt = new DateTimeImmutable($anchor . $start);
        $endAt = new DateTimeImmutable($anchor . $end);
    } catch (Throwable) {
        return 0;
    }
    if ($endAt <= $startAt) {
        $endAt = $endAt->modify('+1 day');
    }
    $minutes = intdiv($endAt->getTimestamp() - $startAt->getTimestamp(), 60);
    return max(0, $minutes - max(0, (int) ($row['break_minutes'] ?? 0)));
}

/**
 * Resolve the compensation in effect on one work date. Production payroll
 * requires a dated HR-approved history row; the employee-row fallback is
 * retained only for older pure-calculation callers and fixture compatibility.
 *
 * @return array{employment_type: ?string, pay_type: string, basic_rate: float, source: string, history_id: ?int, effective_from: ?string, effective_to: ?string}
 */
function payroll_effective_compensation(array $employee, array $history, string $date): array
{
    $selected = null;
    foreach ($history as $entry) {
        if (
            (string) ($entry['effective_from'] ?? '') <= $date
            && (empty($entry['effective_to']) || (string) $entry['effective_to'] >= $date)
        ) {
            if (
                $selected === null
                || (string) $entry['effective_from'] > (string) $selected['effective_from']
                || ((string) $entry['effective_from'] === (string) $selected['effective_from']
                    && (int) ($entry['id'] ?? 0) > (int) ($selected['id'] ?? 0))
            ) {
                $selected = $entry;
            }
        }
    }

    if ($selected !== null) {
        $payType = (string) ($selected['pay_type'] ?? '');
        $basicRate = (float) ($selected['basic_rate'] ?? 0);
        $employmentType = trim((string) ($selected['employment_type'] ?? '')) ?: null;
        $source = 'employee_compensation_history';
        $historyId = (int) ($selected['id'] ?? 0) ?: null;
    } else {
        if (!empty($employee['_compensation_history_required'])) {
            $employeeNumber = (string) ($employee['employee_no'] ?? $employee['id'] ?? 'unknown');
            throw new RuntimeException(
                "Employee {$employeeNumber} has no HR-approved compensation effective on {$date}. "
                . 'Open Employee Records and add an approved rate with an effective date on or before that workday.'
            );
        }
        $payType = (string) ($employee['pay_type'] ?? '');
        $basicRate = (float) ($employee['basic_rate'] ?? 0);
        if ($basicRate <= 0) {
            $basicRate = (float) ($employee['daily_rate'] ?? 0);
            if ($payType === '') {
                $payType = 'Daily';
            }
        }
        $employmentType = trim((string) ($employee['employment_type'] ?? '')) ?: null;
        $source = 'employees_fallback';
        $historyId = null;
    }

    if (
        !in_array($employmentType, ['Full-Time', 'Part-Time'], true)
        || !in_array($payType, ['Monthly', 'Daily', 'Hourly'], true)
        || $basicRate <= 0
    ) {
        $employeeNumber = (string) ($employee['employee_no'] ?? $employee['id'] ?? 'unknown');
        $issues = [];
        if (!in_array($employmentType, ['Full-Time', 'Part-Time'], true)) {
            $issues[] = 'Employment Type is missing or invalid (choose Full-Time or Part-Time)';
        }
        if (!in_array($payType, ['Monthly', 'Daily', 'Hourly'], true)) {
            $issues[] = 'Pay Type is missing or invalid (choose Daily, Hourly, or Monthly)';
        }
        if ($basicRate <= 0) {
            $issues[] = 'Approved Rate must be greater than zero';
        }
        $sourceDescription = $selected !== null
            ? 'dated compensation record effective ' . (string) ($selected['effective_from'] ?? $date)
            : 'Employee Record compensation setup';
        throw new RuntimeException(
            "Employee {$employeeNumber} cannot be processed on {$date}: the {$sourceDescription} has "
            . implode('; ', $issues)
            . '. Open Employee Records, edit the employee, complete the employment/pay setup, '
            . "and save an effective date on or before {$date}."
        );
    }

    return [
        'employment_type' => $employmentType,
        'pay_type' => $payType,
        'basic_rate' => $basicRate,
        'source' => $source,
        'history_id' => $historyId,
        'effective_from' => $selected !== null ? (string) $selected['effective_from'] : null,
        'effective_to' => $selected !== null && !empty($selected['effective_to']) ? (string) $selected['effective_to'] : null,
    ];
}

function payroll_is_normal_work_status(string $status): bool
{
    return in_array($status, ['PRESENT', 'LATE', 'UNDERTIME', 'LATE_AND_UNDERTIME', 'HALF_DAY'], true);
}

/**
 * Full-Time salary rules: Daily and Hourly basic pay cover the assigned
 * workday. Unpaid time is itemized once as late, undertime, or absence.
 * Monthly base pay is prorated separately by calendar date below.
 *
 * @param array<string,mixed> $day
 * @return array<string,float>
 */
function payroll_full_time_day_pay(array $day): array
{
    $status = (string) $day['status'];
    $payType = (string) $day['pay_type'];
    $dailyRate = (float) $day['daily_rate'];
    $hourlyRate = (float) $day['hourly_rate'];
    $workday = $day['schedule_type'] === 'Work';
    $normal = payroll_is_normal_work_status($status);
    $amounts = [
        'regular_pay' => 0.0,
        'late_deduction' => 0.0,
        'undertime_deduction' => 0.0,
        'half_day_deduction' => 0.0,
        'absence_deduction' => 0.0,
    ];
    if (!$workday) {
        return $amounts;
    }

    if ($payType === 'Daily') {
        $amounts['regular_pay'] = (float) $day['basic_rate'];
    } elseif ($payType === 'Hourly') {
        $amounts = payroll_scheduled_hourly_day_pay($day);
        return $amounts;
    }

    if ($normal) {
        if ($status === 'HALF_DAY') {
            // A half-day is an unpaid half-day of scheduled attendance. Keep
            // the legacy storage field empty for new runs; itemize it under
            // Absence / unpaid attendance in the four-category policy.
            $amounts['absence_deduction'] = $dailyRate / 2;
        } else {
            if ($day['policy']['late_deduction_enabled']) {
                $amounts['late_deduction'] = min(
                    $dailyRate,
                    (int) $day['late_minutes'] / 60 * $hourlyRate
                );
            }
            if ($day['policy']['undertime_deduction_enabled']) {
                $amounts['undertime_deduction'] = min(
                    max(0, $dailyRate - $amounts['late_deduction']),
                    (int) $day['undertime_minutes'] / 60 * $hourlyRate
                );
            }
        }
    } elseif (in_array($status, ['ABSENT', 'UNPAID_LEAVE'], true)) {
        $amounts['absence_deduction'] = $dailyRate;
    }
    return $amounts;
}

/**
 * Part-Time salary rules: Hourly and Daily basic pay follow the assigned
 * periods/workdays. Attendance-based unpaid time is a separate deduction.
 * Monthly part-time uses a calendar-prorated base with the same schedule-
 * based attendance deductions.
 *
 * @param array<string,mixed> $day
 * @return array<string,float>
 */
function payroll_part_time_day_pay(array $day): array
{
    $status = (string) $day['status'];
    $payType = (string) $day['pay_type'];
    $dailyRate = (float) $day['daily_rate'];
    $hourlyRate = (float) $day['hourly_rate'];
    $workday = $day['schedule_type'] === 'Work';
    $normal = payroll_is_normal_work_status($status);
    $amounts = [
        'regular_pay' => 0.0,
        'late_deduction' => 0.0,
        'undertime_deduction' => 0.0,
        'half_day_deduction' => 0.0,
        'absence_deduction' => 0.0,
    ];
    if (!$workday) {
        return $amounts;
    }

    if ($payType === 'Hourly') {
        return payroll_scheduled_hourly_day_pay($day);
    }

    if ($payType === 'Daily') {
        $amounts['regular_pay'] = $dailyRate;
        if ($status === 'HALF_DAY') {
            $amounts['absence_deduction'] = $dailyRate / 2;
        } elseif (in_array($status, ['ABSENT', 'UNPAID_LEAVE'], true)) {
            $amounts['absence_deduction'] = $dailyRate;
        }
    } elseif ($status === 'HALF_DAY') {
        $amounts['absence_deduction'] = $dailyRate / 2;
    } elseif (in_array($status, ['ABSENT', 'UNPAID_LEAVE'], true)) {
        $amounts['absence_deduction'] = $dailyRate;
    }

    if ($normal && $status !== 'HALF_DAY') {
        if ($day['policy']['late_deduction_enabled']) {
            $amounts['late_deduction'] = min(
                $dailyRate,
                (int) $day['late_minutes'] / 60 * $hourlyRate
            );
        }
        if ($day['policy']['undertime_deduction_enabled']) {
            $amounts['undertime_deduction'] = min(
                max(0, $dailyRate - $amounts['late_deduction']),
                (int) $day['undertime_minutes'] / 60 * $hourlyRate
            );
        }
    }
    return $amounts;
}

/**
 * Scheduled Hourly basic is independent of punch completeness. Missing time
 * is charged once below the gross salary, never hidden by shrinking Basic.
 * Processed late/undertime metrics already exclude the configured grace.
 * A complete normal day therefore deducts those metrics, not the raw overlap
 * shortfall; Half-Day/Absent time is itemized as unpaid attendance instead.
 * Verified minutes remain separately auditable.
 *
 * @param array<string,mixed> $day
 * @return array<string,float>
 */
function payroll_scheduled_hourly_day_pay(array $day): array
{
    $amounts = [
        'regular_pay' => 0.0,
        'late_deduction' => 0.0,
        'undertime_deduction' => 0.0,
        'half_day_deduction' => 0.0,
        'absence_deduction' => 0.0,
    ];
    if (($day['schedule_type'] ?? '') !== 'Work') {
        return $amounts;
    }
    $scheduled = max(0, (int) ($day['scheduled_minutes'] ?? 0));
    $rate = max(0.0, (float) ($day['hourly_rate'] ?? 0));
    $status = (string) ($day['status'] ?? '');
    $amounts['regular_pay'] = $scheduled / 60 * $rate;
    if (in_array($status, ['PAID_LEAVE', 'REGULAR_HOLIDAY'], true)) {
        return $amounts;
    }
    if (payroll_is_normal_work_status($status) && $status !== 'HALF_DAY') {
        $remaining = $scheduled;
        if (!empty($day['policy']['late_deduction_enabled'])) {
            $late = min($remaining, max(0, (int) ($day['late_minutes'] ?? 0)));
            $amounts['late_deduction'] = $late / 60 * $rate;
            $remaining -= $late;
        }
        if (!empty($day['policy']['undertime_deduction_enabled'])) {
            $undertime = min($remaining, max(0, (int) ($day['undertime_minutes'] ?? 0)));
            $amounts['undertime_deduction'] = $undertime / 60 * $rate;
        }
        return $amounts;
    }
    if ($status === 'HALF_DAY') {
        // A processed Half-Day can never earn more than half the assigned
        // period, even if overlapping punches total slightly above half.
        $eligible = min(intdiv($scheduled, 2), max(0, (int) ($day['regular_minutes'] ?? 0)));
        $amounts['absence_deduction'] = ($scheduled - $eligible) / 60 * $rate;
        return $amounts;
    }
    if ($status === 'HOLIDAY_WORK') {
        $eligible = min($scheduled, max(0, (int) ($day['regular_minutes'] ?? 0)));
        $amounts['absence_deduction'] = ($scheduled - $eligible) / 60 * $rate;
        return $amounts;
    }
    $amounts['absence_deduction'] = $amounts['regular_pay'];
    return $amounts;
}

/** Monthly compensation is earned over calendar days in the selected period. */
function payroll_monthly_calendar_basic(
    array $employee,
    array $history,
    string $start,
    string $end,
    string $employmentType
): float {
    $total = 0.0;
    $date = new DateTimeImmutable($start);
    $last = new DateTimeImmutable($end);
    for (; $date <= $last; $date = $date->modify('+1 day')) {
        $compensation = payroll_effective_compensation($employee, $history, $date->format('Y-m-d'));
        if ($compensation['employment_type'] !== $employmentType || $compensation['pay_type'] !== 'Monthly') {
            throw new RuntimeException(
                'Employment or Pay Type changes inside this payroll period. Split the period at the effective date.'
            );
        }
        $total += (float) $compensation['basic_rate'] / (int) $date->format('t');
    }
    return $total;
}

/**
 * Pure calculation for a single employee.  Inputs are database-shaped arrays;
 * no database calls or current-time reads occur here.
 *
 * Daily employees use scheduled workdays x approved daily rate and Monthly
 * employees use their configured monthly rate; attendance supplies schedule-
 * based late/undertime/absence deductions. Half-Day pays exactly one-half
 * daily equivalent under unpaid attendance. Absent and approved unpaid-leave
 * workdays deduct one schedule-derived daily equivalent.
 * Hourly employees receive scheduled hours x hourly rate as Basic Pay; missing
 * verified time is itemized once below Gross. Approved overtime and
 * Cash Advance source deductions are then applied for every pay type. Older
 * Other source rows are rejected rather than silently charged or ignored.
 *
 * @return array<string, mixed>
 */
function payroll_calculate_employee(
    array $employee,
    array $attendanceRows,
    array $history,
    array $policy,
    array $deductionEntries = [],
    array $monthlyScheduledDays = []
): array {
    if (!$attendanceRows) {
        throw new InvalidArgumentException('At least one attendance row is required.');
    }

    usort($attendanceRows, static function (array $left, array $right): int {
        return [(string) $left['scan_date'], (int) $left['id']]
            <=> [(string) $right['scan_date'], (int) $right['id']];
    });

    $rule = (string) $policy['rounding_rule'];
    $fallbackHoursPerDay = (float) $policy['regular_hours_per_day'];
    $periodScheduledDays = 0;
    $periodScheduledMinutes = 0;
    foreach ($attendanceRows as $scheduleRow) {
        if ((string) ($scheduleRow['schedule_type'] ?? '') !== 'Work') {
            continue;
        }
        $dayScheduledMinutes = payroll_scheduled_minutes($scheduleRow);
        if ($dayScheduledMinutes < 1) {
            continue;
        }
        $periodScheduledDays++;
        $periodScheduledMinutes += $dayScheduledMinutes;
    }
    if ($periodScheduledDays < 1 || $periodScheduledMinutes < 1) {
        throw new RuntimeException('No assigned work schedule exists in this payroll period. Assign scheduled workdays and hours before generating payroll.');
    }
    $averageScheduledHours = ($periodScheduledMinutes / $periodScheduledDays) / 60;
    $totals = [
        'days_worked' => 0.0,
        'regular_minutes' => 0,
        'regular_pay' => 0.0,
        'late_minutes' => 0,
        'late_deduction' => 0.0,
        'undertime_minutes' => 0,
        'undertime_deduction' => 0.0,
        'half_day_deduction' => 0.0,
        'absence_deduction' => 0.0,
        'absence_days' => 0.0,
        'unpaid_leave_days' => 0.0,
        'approved_overtime_minutes' => 0,
        'overtime_pay' => 0.0,
        'holiday_minutes' => 0,
        'holiday_pay' => 0.0,
        'rest_day_minutes' => 0,
        'rest_day_pay' => 0.0,
        'other_earnings' => 0.0,
    ];
    $attendanceIds = [];
    $calculationRows = [];
    $representative = null;
    $calculationEmploymentType = null;
    $calculationPayType = null;

    foreach ($attendanceRows as $row) {
        $date = (string) ($row['scan_date'] ?? '');
        $attendanceId = (int) ($row['id'] ?? 0);
        if ($attendanceId < 1 || $date === '') {
            throw new InvalidArgumentException('Attendance rows require an id and scan date.');
        }
        $status = payroll_normalize_status($row);
        $scheduleType = (string) ($row['schedule_type'] ?? '');
        $complete = !empty($row['time_in']) && !empty($row['time_out']);
        $scheduledMinutes = payroll_scheduled_minutes($row);
        $scheduledHoursForDay = $scheduledMinutes > 0
            ? $scheduledMinutes / 60
            : ($averageScheduledHours > 0 ? $averageScheduledHours : $fallbackHoursPerDay);
        $compensation = payroll_effective_compensation($employee, $history, $date);
        if ($calculationEmploymentType !== null
            && ($compensation['employment_type'] !== $calculationEmploymentType
                || $compensation['pay_type'] !== $calculationPayType)) {
            throw new RuntimeException(
                'Employee ' . (string) ($employee['employee_no'] ?? $employee['id'] ?? '')
                . ' changes Employment Type or Pay Type inside this payroll period. '
                . 'Split the payroll period at the compensation effective date.'
            );
        }
        $calculationEmploymentType = $compensation['employment_type'];
        $calculationPayType = $compensation['pay_type'];
        $monthKey = substr($date, 0, 7);
        $scheduleBasis = $compensation['pay_type'] === 'Monthly'
            ? (int) ($monthlyScheduledDays[$monthKey] ?? $periodScheduledDays)
            : $periodScheduledDays;
        if ($scheduleBasis < 1) {
            throw new RuntimeException("No assigned work schedule exists for {$monthKey}.");
        }
        $rates = payroll_derive_rates(
            $compensation['pay_type'],
            $compensation['basic_rate'],
            $scheduledHoursForDay,
            $scheduleBasis
        );
        $representative = $compensation + $rates;

        $dailyRate = $rates['daily_rate'];
        $hourlyRate = $rates['hourly_rate'];
        $regularMinutes = max(0, (int) ($row['regular_minutes'] ?? 0));
        $workedMinutes = max(0, (int) ($row['worked_minutes'] ?? 0));
        $potentialOvertime = max(0, (int) ($row['potential_overtime_minutes'] ?? 0));
        $attendanceApproved = max(0, (int) ($row['approved_overtime_minutes'] ?? 0));
        $requestApproved = (string) ($row['ot_request_source'] ?? '') === 'Employee'
            && (string) ($row['ot_request_status'] ?? '') === 'Approved'
            ? max(0, (int) ($row['ot_request_approved_minutes'] ?? 0))
            : 0;
        // Both projections must agree; a stale field alone can never authorize pay.
        $approvedOvertime = (bool) $policy['overtime_enabled']
            ? min($potentialOvertime, $attendanceApproved, $requestApproved)
            : 0;
        $rowAmounts = [
            'regular_pay' => 0.0,
            'late_deduction' => 0.0,
            'undertime_deduction' => 0.0,
            'half_day_deduction' => 0.0,
            'absence_deduction' => 0.0,
            'overtime_pay' => 0.0,
            'holiday_pay' => 0.0,
            'rest_day_pay' => 0.0,
        ];

        if (payroll_is_normal_work_status($status)) {
            if (!$complete) {
                throw new RuntimeException("Attendance {$attendanceId} is marked worked but has no complete Time In/Out pair.");
            }
            if ($scheduleType !== 'Work') {
                throw new RuntimeException("Attendance {$attendanceId} is marked worked without an assigned Work schedule.");
            }
            $dayLateMinutes = max(0, (int) ($row['late_minutes'] ?? 0));
            $dayUndertimeMinutes = max(0, (int) ($row['undertime_minutes'] ?? 0));
            $totals['late_minutes'] += $dayLateMinutes;
            $totals['undertime_minutes'] += $dayUndertimeMinutes;
            $totals['regular_minutes'] += $regularMinutes;
            $totals['days_worked'] += $status === 'HALF_DAY'
                ? 0.5
                : ($regularMinutes > 0 ? 1 : 0);
        } elseif ($status === 'PAID_LEAVE') {
            $totals['regular_minutes'] += $scheduledMinutes;
            $totals['days_worked'] += 1;
        } elseif ($status === 'UNPAID_LEAVE') {
            $totals['unpaid_leave_days'] += 1;
        } elseif ($status === 'ABSENT') {
            $totals['absence_days'] += 1;
        } elseif (in_array($status, ['REGULAR_HOLIDAY', 'SPECIAL_NON_WORKING_DAY'], true)) {
            // The configured Monthly salary or schedule-derived Daily base
            // already includes this scheduled date.
            if ($scheduleType === 'Work' && $status === 'REGULAR_HOLIDAY') {
                $totals['regular_minutes'] += $scheduledMinutes;
                $totals['days_worked'] += 1;
            }
        } elseif ($status === 'HOLIDAY_WORK') {
            if (!$complete) {
                throw new RuntimeException("Holiday attendance {$attendanceId} has no complete Time In/Out pair.");
            }
            $holidayMinutes = $scheduleType === 'Work'
                ? $regularMinutes
                : max(0, $workedMinutes - $potentialOvertime);
            $totals['holiday_minutes'] += $holidayMinutes;
            $totals['days_worked'] += $scheduledMinutes > 0
                ? min(1, $holidayMinutes / $scheduledMinutes)
                : ($holidayMinutes > 0 ? 1 : 0);
            // Holiday attendance remains classified and snapshotted, while the
            // requested payroll formula uses monthly basic salary plus approved OT.
            $totals['regular_minutes'] += $scheduleType === 'Work' ? $scheduledMinutes : 0;
        } elseif ($status === 'REST_DAY_WORK') {
            if (!$complete) {
                throw new RuntimeException("Rest-day attendance {$attendanceId} has no complete Time In/Out pair.");
            }
            $restMinutes = max(0, $workedMinutes - $potentialOvertime);
            $totals['rest_day_minutes'] += $restMinutes;
            $totals['days_worked'] += $scheduledMinutes > 0
                ? min(1, $restMinutes / $scheduledMinutes)
                : ($restMinutes > 0 ? 1 : 0);
            // Rest-day time remains visible for review but is not a separate
            // earning unless it is represented by approved overtime.
        } elseif ($status !== 'REST_DAY') {
            throw new RuntimeException("Attendance {$attendanceId} has unsupported payroll status '{$status}'.");
        }

        $dayContext = [
            'status' => $status,
            'pay_type' => $compensation['pay_type'],
            'basic_rate' => $compensation['basic_rate'],
            'daily_rate' => $dailyRate,
            'hourly_rate' => $hourlyRate,
            'schedule_type' => $scheduleType,
            'regular_minutes' => $regularMinutes,
            'scheduled_minutes' => $scheduledMinutes,
            'late_minutes' => max(0, (int) ($row['late_minutes'] ?? 0)),
            'undertime_minutes' => max(0, (int) ($row['undertime_minutes'] ?? 0)),
            'policy' => $policy,
        ];
        $employmentAmounts = $compensation['employment_type'] === 'Full-Time'
            ? payroll_full_time_day_pay($dayContext)
            : payroll_part_time_day_pay($dayContext);
        $rowAmounts = array_replace($rowAmounts, $employmentAmounts);

        if ($approvedOvertime > 0) {
            $classification = (string) ($row['day_classification'] ?? '');
            if ($classification === 'Regular Holiday') {
                $overtimeMultiplier = (float) $policy['regular_holiday_overtime_multiplier'];
                if ($scheduleType === 'Off') {
                    $overtimeMultiplier *= (float) $policy['regular_holiday_rest_day_multiplier'];
                }
            } elseif ($classification === 'Special Non-Working Day') {
                $overtimeMultiplier = (float) $policy['special_day_overtime_multiplier'];
                if ($scheduleType === 'Off') {
                    $overtimeMultiplier *= (float) $policy['special_day_rest_day_multiplier'];
                }
            } elseif ($scheduleType === 'Off') {
                $overtimeMultiplier = (float) $policy['overtime_multiplier']
                    * (float) $policy['rest_day_multiplier'];
            } else {
                $overtimeMultiplier = (float) $policy['overtime_multiplier'];
            }
            $rowAmounts['overtime_pay'] = ($approvedOvertime / 60) * $hourlyRate * $overtimeMultiplier;
            $totals['approved_overtime_minutes'] += $approvedOvertime;
        }

        foreach ($rowAmounts as $key => $value) {
            if (array_key_exists($key, $totals)) {
                $totals[$key] += $value;
            }
        }
        $attendanceIds[] = $attendanceId;
        $calculationRows[] = [
            'attendance_id' => $attendanceId,
            'date' => $date,
            'time_in' => $row['time_in'] ?? null,
            'time_out' => $row['time_out'] ?? null,
            'expected_time_in' => $row['expected_time_in'] ?? null,
            'expected_time_out' => $row['expected_time_out'] ?? null,
            'status' => $status,
            'day_classification' => (string) ($row['day_classification'] ?? ''),
            'schedule_type' => $scheduleType,
            'schedule_source' => (string) ($row['schedule_source'] ?? ''),
            'schedule_periods' => attendance_decode_schedule_periods($row['schedule_periods_snapshot'] ?? null),
            'break_minutes' => max(0, (int) ($row['break_minutes'] ?? 0)),
            'scheduled_minutes' => $scheduledMinutes,
            'actual_regular_minutes' => $regularMinutes,
            'worked_minutes' => $workedMinutes,
            'late_minutes' => max(0, (int) ($row['late_minutes'] ?? 0)),
            'undertime_minutes' => max(0, (int) ($row['undertime_minutes'] ?? 0)),
            'potential_overtime_minutes' => $potentialOvertime,
            'attendance_approved_overtime_minutes' => $attendanceApproved,
            'overtime_request_status' => (string) ($row['ot_request_status'] ?? ''),
            'overtime_request_source' => (string) ($row['ot_request_source'] ?? ''),
            'overtime_request_approved_minutes' => $requestApproved,
            'approved_overtime_minutes' => $approvedOvertime,
            'holiday_id' => isset($row['holiday_id']) ? (int) $row['holiday_id'] : null,
            'leave_request_id' => isset($row['leave_request_id']) ? (int) $row['leave_request_id'] : null,
            'compensation' => $compensation,
            'equivalent_rates' => [
                'daily' => payroll_round_amount($dailyRate, $rule),
                'hourly' => round($hourlyRate, 4, PHP_ROUND_HALF_UP),
            ],
            'amounts' => array_map(
                static fn(float $amount): float => payroll_round_amount($amount, $rule),
                $rowAmounts
            ),
        ];
    }

    if ($representative === null) {
        throw new LogicException('A representative compensation snapshot could not be selected.');
    }

    $payType = (string) $representative['pay_type'];
    $firstDate = (string) $attendanceRows[0]['scan_date'];
    $lastDate = (string) $attendanceRows[count($attendanceRows) - 1]['scan_date'];
    $monthlyBasicSalary = $payType === 'Monthly'
        ? payroll_round_amount(
            payroll_monthly_calendar_basic(
                $employee,
                $history,
                $firstDate,
                $lastDate,
                (string) $calculationEmploymentType
            ),
            $rule
        )
        : payroll_round_amount((float) $totals['regular_pay'], $rule);
    $dailyRate = payroll_round_amount((float) $representative['daily_rate'], $rule);
    $regularPay = $monthlyBasicSalary;
    $overtimePay = payroll_round_amount($totals['overtime_pay'], $rule);
    $holidayPay = 0.0;
    $restDayPay = 0.0;
    $otherEarnings = 0.0;
    $grossPay = payroll_round_amount(
        $monthlyBasicSalary + $overtimePay,
        $rule
    );
    $lateDeduction = payroll_round_amount($totals['late_deduction'], $rule);
    $undertimeDeduction = payroll_round_amount($totals['undertime_deduction'], $rule);
    $halfDayDeduction = payroll_round_amount($totals['half_day_deduction'], $rule);
    $absenceDeduction = payroll_round_amount($totals['absence_deduction'], $rule);
    $cashAdvanceDeduction = 0.0;
    $otherDeductions = 0.0;
    $deductionSnapshot = [];
    foreach ($deductionEntries as $entry) {
        $type = trim((string) ($entry['deduction_type'] ?? ''));
        $amount = max(0, (float) ($entry['amount'] ?? 0));
        if ($amount <= 0) {
            continue;
        }
        if ($type !== 'Cash Advance') {
            throw new RuntimeException(
                'Employee ' . (string) ($employee['employee_no'] ?? $employee['id'] ?? '')
                . ' has an active unsupported ' . ($type !== '' ? $type : 'unnamed')
                . ' deduction on ' . (string) ($entry['deduction_date'] ?? 'this period')
                . '. Only Cash Advance source deductions are allowed; ask HR to review this entry.'
            );
        }
        $cashAdvanceDeduction += $amount;
        $deductionSnapshot[] = [
            'id' => isset($entry['id']) ? (int) $entry['id'] : null,
            'date' => (string) ($entry['deduction_date'] ?? ''),
            'type' => $type,
            'amount' => payroll_round_amount($amount, $rule),
            'description' => trim((string) ($entry['description'] ?? '')),
        ];
    }
    $cashAdvanceDeduction = payroll_round_amount($cashAdvanceDeduction, $rule);
    $otherDeductions = payroll_round_amount($otherDeductions, $rule);
    $totalDeductions = payroll_round_amount(
        $lateDeduction + $undertimeDeduction + $halfDayDeduction + $absenceDeduction + $cashAdvanceDeduction + $otherDeductions,
        $rule
    );
    if ($totalDeductions > $grossPay) {
        throw new RuntimeException(
            'Total deductions (' . number_format($totalDeductions, 2)
            . ') exceed Gross Pay (' . number_format($grossPay, 2)
            . '). Review attendance deductions and reduce Cash Advance before generating payroll.'
        );
    }
    // Keep the requested equation explicit and auditable. Successful payrolls
    // always reconcile every stored deduction component to the stored Net Pay.
    $netPay = payroll_round_amount(
        $grossPay
        - $lateDeduction
        - $undertimeDeduction
        - $halfDayDeduction
        - $absenceDeduction
        - $cashAdvanceDeduction
        - $otherDeductions,
        $rule
    );
    $approvedOvertimeMinutes = (int) $totals['approved_overtime_minutes'];

    $employeeName = trim(implode(' ', array_filter([
        trim((string) ($employee['first_name'] ?? '')),
        trim((string) ($employee['middle_name'] ?? '')),
        trim((string) ($employee['last_name'] ?? '')),
    ], static fn(string $part): bool => $part !== '')));
    $snapshot = [
        'version' => PAYROLL_CALCULATION_VERSION,
        'employee' => [
            'id' => (int) ($employee['id'] ?? 0),
            'employee_no' => (string) ($employee['employee_no'] ?? ''),
            'name' => $employeeName,
            'department' => (string) ($employee['department_name'] ?? 'Unassigned'),
            'position' => (string) ($employee['position'] ?? ''),
            'status_at_calculation' => (string) ($employee['employee_status'] ?? $employee['status'] ?? ''),
        ],
        'representative_compensation' => $representative,
        'basic_salary' => [
            'pay_type' => $payType,
            'approved_rate' => payroll_round_amount((float) $representative['basic_rate'], $rule),
            'daily_rate' => $dailyRate,
            'monthly_basic_salary' => $monthlyBasicSalary,
            'scheduled_days' => $periodScheduledDays,
            'scheduled_minutes' => $periodScheduledMinutes,
            'scheduled_hours' => round($periodScheduledMinutes / 60, 2, PHP_ROUND_HALF_UP),
            'eligible_regular_minutes' => (int) $totals['regular_minutes'],
            'monthly_schedule_workdays' => $monthlyScheduledDays,
            'formula' => match ($payType) {
                'Hourly' => 'scheduled_hours_x_effective_hourly_rate_minus_unpaid_time',
                'Monthly' => 'effective_monthly_rate_prorated_by_calendar_days_with_schedule_deductions',
                default => $calculationEmploymentType === 'Part-Time'
                    ? 'scheduled_workdays_x_effective_daily_rate_minus_unpaid_time'
                    : 'scheduled_workdays_x_effective_daily_rate_minus_unpaid_time',
            },
        ],
        'deduction_policy' => 'late_undertime_absence_including_unpaid_half_day_and_cash_advance',
        'net_pay_calculation' => [
            'formula' => 'gross_pay_minus_late_minus_undertime_minus_absence_unpaid_time_minus_cash_advance_equals_net_pay',
            'gross_pay' => $grossPay,
            'late_deduction' => $lateDeduction,
            'undertime_deduction' => $undertimeDeduction,
            'half_day_deduction' => $halfDayDeduction,
            'absence_unpaid_day_deduction' => $absenceDeduction,
            'cash_advance_deduction' => $cashAdvanceDeduction,
            'other_deductions' => $otherDeductions,
            'total_deductions' => $totalDeductions,
            'net_pay' => $netPay,
        ],
        'source_deductions' => $deductionSnapshot,
        'attendance' => $calculationRows,
    ];

    return [
        'employee_id' => (int) $employee['id'],
        'employment_type' => $representative['employment_type'],
        'pay_type' => $representative['pay_type'],
        'basic_rate' => payroll_round_amount((float) $representative['basic_rate'], $rule),
        'days_worked' => round((float) $totals['days_worked'], 2, PHP_ROUND_HALF_UP),
        'scheduled_days' => $periodScheduledDays,
        'scheduled_minutes' => $periodScheduledMinutes,
        'scheduled_hours' => round($periodScheduledMinutes / 60, 2, PHP_ROUND_HALF_UP),
        'regular_minutes' => (int) $totals['regular_minutes'],
        'regular_hours' => round((int) $totals['regular_minutes'] / 60, 2, PHP_ROUND_HALF_UP),
        'hourly_equivalent_rate' => round((float) $representative['hourly_rate'], 4, PHP_ROUND_HALF_UP),
        'monthly_basic_salary' => $monthlyBasicSalary,
        'regular_pay' => $regularPay,
        'late_minutes' => (int) $totals['late_minutes'],
        'late_deduction' => $lateDeduction,
        'undertime_minutes' => (int) $totals['undertime_minutes'],
        'undertime_deduction' => $undertimeDeduction,
        'half_day_deduction' => $halfDayDeduction,
        'absence_deduction' => $absenceDeduction,
        'cash_advance_deduction' => $cashAdvanceDeduction,
        'other_deductions' => $otherDeductions,
        'absence_days' => round((float) $totals['absence_days'], 2, PHP_ROUND_HALF_UP),
        'unpaid_leave_days' => round((float) $totals['unpaid_leave_days'], 2, PHP_ROUND_HALF_UP),
        'approved_overtime_minutes' => $approvedOvertimeMinutes,
        'overtime_hours' => round($approvedOvertimeMinutes / 60, 2, PHP_ROUND_HALF_UP),
        'ot_rate' => $approvedOvertimeMinutes > 0
            ? round($overtimePay / ($approvedOvertimeMinutes / 60), 4, PHP_ROUND_HALF_UP)
            : 0.0,
        'overtime_pay' => $overtimePay,
        'holiday_hours' => round((int) $totals['holiday_minutes'] / 60, 2, PHP_ROUND_HALF_UP),
        'holiday_pay' => $holidayPay,
        'rest_day_hours' => round((int) $totals['rest_day_minutes'] / 60, 2, PHP_ROUND_HALF_UP),
        'rest_day_pay' => $restDayPay,
        'other_earnings' => $otherEarnings,
        'gross_pay' => $grossPay,
        'total_deductions' => $totalDeductions,
        'net_pay' => $netPay,
        'calculation_snapshot' => payroll_json($snapshot),
        'attendance_ids' => $attendanceIds,
    ];
}

function payroll_assert_integrated_schema(PDO $pdo): void
{
    $required = [
        'employees' => ['employment_type', 'pay_type', 'basic_rate'],
        'employee_compensation_history' => ['effective_from', 'effective_to'],
        'attendance' => ['regular_minutes', 'undertime_minutes', 'approved_overtime_minutes', 'processed_at'],
        'overtime_requests' => ['approved_minutes', 'status'],
        'payroll_runs' => [
            'scope_employee_id',
            'payment_method',
            'payment_status',
            'payment_updated_at',
            'policy_snapshot',
            'reviewed_by',
            'finalized_by',
            'paid_at',
        ],
        'payroll_items' => ['monthly_basic_salary', 'regular_pay', 'late_deduction', 'undertime_deduction', 'half_day_deduction', 'absence_deduction', 'cash_advance_deduction', 'other_deductions', 'total_deductions', 'calculation_snapshot'],
        'employee_deduction_entries' => ['employee_id', 'deduction_date', 'deduction_type', 'amount', 'description', 'status', 'created_by'],
        'payroll_item_attendance' => ['payroll_item_id', 'attendance_id'],
        'activity_logs' => ['module', 'record_id', 'old_values', 'new_values'],
    ];
    $stmt = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME IN ('employees','employee_compensation_history',
               'attendance','overtime_requests','payroll_runs','payroll_items',
               'employee_deduction_entries','payroll_item_attendance','activity_logs')"
    );
    $available = [];
    foreach ($stmt->fetchAll() as $row) {
        $available[(string) $row['TABLE_NAME']][(string) $row['COLUMN_NAME']] = true;
    }
    foreach ($required as $table => $columns) {
        foreach ($columns as $column) {
            if (!isset($available[$table][$column])) {
                throw new RuntimeException(
                    "Payroll schema is missing {$table}.{$column}. Apply database/payroll_review_automation_update.sql."
                );
            }
        }
    }
}

function payroll_assert_actor(PDO $pdo, int $actorUserId): array
{
    if ($actorUserId < 1) {
        throw new InvalidArgumentException('A valid payroll actor is required.');
    }
    $stmt = $pdo->prepare('SELECT id, full_name, role FROM users WHERE id=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$actorUserId]);
    $actor = $stmt->fetch();
    if (!$actor) {
        throw new InvalidArgumentException('Payroll actor account was not found.');
    }
    if ((string) $actor['role'] !== 'Administrator') {
        throw new RuntimeException('Administrator access is required for payroll operations.');
    }
    return $actor;
}

function payroll_lock_name(PDO $pdo): string
{
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    return 'ucchr_pr_' . substr(hash('sha256', $database), 0, 48);
}

function payroll_acquire_lock(PDO $pdo): string
{
    $name = payroll_lock_name($pdo);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $stmt->execute([$name]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Another payroll operation is in progress. Try again shortly.');
    }
    return $name;
}

function payroll_release_lock(PDO $pdo, ?string $name): void
{
    if ($name === null) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    } catch (Throwable) {
        // Connection-scoped locks are automatically released on disconnect.
    }
}

function payroll_assert_no_overlap(
    PDO $pdo,
    string $start,
    string $end,
    ?int $exceptRunId = null,
    ?int $employeeId = null
): void {
    $sql = 'SELECT DISTINCT pr.id, pr.status, pr.period_start, pr.period_end
            FROM payroll_runs pr';
    if ($employeeId !== null) {
        $sql .= ' LEFT JOIN payroll_items pi ON pi.payroll_run_id=pr.id';
    } else {
        $sql .= ' JOIN payroll_items pi ON pi.payroll_run_id=pr.id
                  JOIN employees e ON e.id=pi.employee_id AND e.status="Active"';
    }
    $sql .= ' WHERE pr.period_start<=? AND pr.period_end>=?';
    $parameters = [$end, $start];
    if ($employeeId !== null) {
        $sql .= ' AND (pr.scope_employee_id=? OR pi.employee_id=?)';
        $parameters[] = $employeeId;
        $parameters[] = $employeeId;
    }
    if ($exceptRunId !== null) {
        $sql .= ' AND pr.id<>?';
        $parameters[] = $exceptRunId;
    }
    $sql .= ' ORDER BY pr.period_start, pr.id LIMIT 1 FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    $existing = $stmt->fetch();
    if ($existing) {
        $nextDate = (new DateTimeImmutable((string) $existing['period_end']))
            ->modify('+1 day')->format('Y-m-d');
        throw new RuntimeException(
            'Payroll period overlaps run #' . (int) $existing['id']
                . ' (' . $existing['period_start'] . ' through ' . $existing['period_end']
                . ', ' . $existing['status'] . '). Dates in that run cannot be paid twice. '
                . 'Choose a period beginning no earlier than ' . $nextDate
                . ' for this employee, or review other existing runs if they also cover the new period.'
        );
    }
}

function payroll_assert_no_foreign_attendance_links(
    PDO $pdo,
    string $start,
    string $end,
    ?int $exceptRunId = null,
    ?int $employeeId = null
): void {
    $sql = 'SELECT a.id, pi.payroll_run_id
            FROM payroll_item_attendance pia
            JOIN payroll_items pi ON pi.id=pia.payroll_item_id
            JOIN attendance a ON a.id=pia.attendance_id
            WHERE a.scan_date BETWEEN ? AND ?';
    $parameters = [$start, $end];
    if ($employeeId !== null) {
        $sql .= ' AND a.employee_id=?';
        $parameters[] = $employeeId;
    }
    if ($exceptRunId !== null) {
        $sql .= ' AND pi.payroll_run_id<>?';
        $parameters[] = $exceptRunId;
    }
    $sql .= ' ORDER BY a.id LIMIT 1 FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    $owned = $stmt->fetch();
    if ($owned) {
        throw new RuntimeException(
            'Attendance #' . (int) $owned['id'] . ' is already owned by payroll run #'
                . (int) $owned['payroll_run_id'] . '.'
        );
    }
}

/** @return array<int, array<string, mixed>> */
function payroll_load_attendance(PDO $pdo, string $start, string $end, ?int $employeeId = null): array
{
    $sql =
        'SELECT a.*,
                e.employee_no, e.first_name, e.middle_name, e.last_name,
                e.position, e.status AS employee_status, e.employment_type,
                e.pay_type, e.basic_rate, e.daily_rate, e.created_at AS employee_created_at,
                COALESCE(d.name, "Unassigned") AS department_name,
                otr.status AS ot_request_status,
                otr.approved_minutes AS ot_request_approved_minutes,
                otr.request_source AS ot_request_source
         FROM attendance a
         JOIN employees e ON e.id=a.employee_id
         LEFT JOIN departments d ON d.id=e.department_id
         LEFT JOIN overtime_requests otr
           ON otr.employee_id=a.employee_id
          AND otr.attendance_date=a.scan_date
          AND otr.request_source="Employee"
         WHERE a.scan_date BETWEEN ? AND ?';
    $parameters = [$start, $end];
    if ($employeeId !== null) {
        $sql .= ' AND a.employee_id=?';
        $parameters[] = $employeeId;
    } else {
        // A batch run must not pay rows belonging to an inactive employee.
        $sql .= ' AND e.status="Active"';
    }
    $sql .= ' ORDER BY a.employee_id, a.scan_date, a.id FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        throw new RuntimeException('No processed attendance exists for the selected payroll period.');
    }

    $seen = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (isset($seen[$id])) {
            throw new RuntimeException("Attendance #{$id} joined more than once; repair duplicate overtime mappings.");
        }
        $seen[$id] = true;
        $status = payroll_normalize_status($row);
        if (empty($row['processed_at']) || (int) ($row['processing_version'] ?? 0) < ATTENDANCE_PROCESSING_VERSION) {
            throw new RuntimeException("Attendance #{$id} was not processed by the current attendance engine.");
        }
        if (in_array($status, ['INCOMPLETE', 'UNSCHEDULED', 'ON_LEAVE'], true)) {
            throw new RuntimeException(
                "Attendance #{$id} is {$status}; resolve this scheduled work/leave record before payroll. "
                . "Historical incomplete Rest Days are handled automatically as REST_DAY."
            );
        }
        if (
            (string) ($row['schedule_type'] ?? '') === 'Work'
            && payroll_scheduled_minutes($row) <= 0
        ) {
            throw new RuntimeException("Attendance #{$id} has an invalid frozen work schedule.");
        }
    }
    return $rows;
}

/** @return array<int, array<int, array<string, mixed>>> */
function payroll_load_compensation_history(PDO $pdo, array $employeeIds, string $start, string $end): array
{
    $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
    if (!$employeeIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT * FROM employee_compensation_history
         WHERE employee_id IN ({$placeholders})
           AND effective_from<=?
         ORDER BY employee_id, effective_from DESC, id DESC
         FOR UPDATE"
    );
    $stmt->execute([...$employeeIds, $end]);
    $history = [];
    foreach ($stmt->fetchAll() as $row) {
        $history[(int) $row['employee_id']][] = $row;
    }
    return $history;
}

/** @return array<int, list<array<string, mixed>>> */
function payroll_load_deduction_entries(PDO $pdo, array $employeeIds, string $start, string $end): array
{
    $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
    if (!$employeeIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, employee_id, deduction_date, deduction_type, amount, description, status
         FROM employee_deduction_entries
         WHERE employee_id IN ({$placeholders})
           AND deduction_date BETWEEN ? AND ? AND status='Active'
         ORDER BY employee_id, deduction_date, id
         FOR UPDATE"
    );
    $stmt->execute([...$employeeIds, $start, $end]);
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['employee_id']][] = $row;
    }
    return $grouped;
}

/** @return array<int, array<string, mixed>> */
function payroll_build_calculations(
    PDO $pdo,
    string $start,
    string $end,
    array $policy,
    ?int $employeeId = null,
    float $previewCashAdvance = 0.0
): array {
    $scopeEmployeeId = $employeeId;
    if ($previewCashAdvance < 0 || !is_finite($previewCashAdvance)
        || ($previewCashAdvance > 0 && $scopeEmployeeId === null)) {
        throw new InvalidArgumentException('Preview Cash Advance requires one employee and a valid non-negative amount.');
    }
    $rows = payroll_load_attendance($pdo, $start, $end, $employeeId);
    $grouped = [];
    $employees = [];
    foreach ($rows as $row) {
        $employeeId = (int) $row['employee_id'];
        $grouped[$employeeId][] = $row;
        if (!isset($employees[$employeeId])) {
            $employees[$employeeId] = [
                'id' => $employeeId,
                'employee_no' => $row['employee_no'],
                'first_name' => $row['first_name'],
                'middle_name' => $row['middle_name'],
                'last_name' => $row['last_name'],
                'position' => $row['position'],
                'employee_status' => $row['employee_status'],
                'employment_type' => $row['employment_type'],
                'pay_type' => $row['pay_type'],
                'basic_rate' => $row['basic_rate'],
                'daily_rate' => $row['daily_rate'],
                'department_name' => $row['department_name'],
                '_compensation_history_required' => true,
            ];
        }
    }
    $history = payroll_load_compensation_history($pdo, array_keys($employees), $start, $end);
    $deductions = payroll_load_deduction_entries($pdo, array_keys($employees), $start, $end);
    if ($previewCashAdvance > 0 && $scopeEmployeeId !== null) {
        $deductions[$scopeEmployeeId][] = [
            'id' => null,
            'deduction_date' => $end,
            'deduction_type' => 'Cash Advance',
            'amount' => round($previewCashAdvance, 2, PHP_ROUND_HALF_UP),
            'description' => 'Proposed Cash Advance for this payroll draft',
        ];
    }
    $calculations = [];
    foreach ($employees as $employeeId => $employee) {
        $monthlyScheduledDays = [];
        foreach ($grouped[$employeeId] as $attendanceRow) {
            $workDate = (string) $attendanceRow['scan_date'];
            $effective = payroll_effective_compensation(
                $employee,
                $history[$employeeId] ?? [],
                $workDate
            );
            if ($effective['pay_type'] !== 'Monthly') {
                continue;
            }
            $monthKey = substr($workDate, 0, 7);
            if (isset($monthlyScheduledDays[$monthKey])) {
                continue;
            }
            $monthEstimate = payroll_expected_monthly_estimate(
                $pdo,
                $employeeId,
                'Monthly',
                1.0,
                new DateTimeImmutable($monthKey . '-01')
            );
            $monthWorkdays = (int) $monthEstimate['scheduled_days'];
            if ($monthWorkdays < 1) {
                throw new RuntimeException(
                    "Employee {$employee['employee_no']} has no assigned workdays in {$monthKey}; "
                    . 'assign a schedule before running Monthly payroll.'
                );
            }
            $monthlyScheduledDays[$monthKey] = $monthWorkdays;
        }
        $calculations[] = payroll_calculate_employee(
            $employee,
            $grouped[$employeeId],
            $history[$employeeId] ?? [],
            $policy,
            $deductions[$employeeId] ?? [],
            $monthlyScheduledDays
        );
    }
    return $calculations;
}

/** Refresh attendance for the entire run or only its selected employee. */
function payroll_process_attendance_scope(
    PDO $pdo,
    string $start,
    string $end,
    ?int $employeeId
): array {
    if ($employeeId === null) {
        $stmt = $pdo->prepare(
            'SELECT id FROM employees
             WHERE status="Active" AND DATE(created_at)<=?
             ORDER BY id FOR UPDATE'
        );
        $stmt->execute([$end]);
        $employeeIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$employeeIds) {
            throw new RuntimeException('No active employees existed in this payroll period.');
        }
        $summary = ['days' => 0, 'processed' => 0, 'deferred' => 0, 'skipped' => 0, 'deferred_dates' => []];
        foreach ($employeeIds as $activeEmployeeId) {
            $result = payroll_process_attendance_scope($pdo, $start, $end, $activeEmployeeId);
            foreach (['days', 'processed', 'deferred', 'skipped'] as $key) {
                $summary[$key] += (int) ($result[$key] ?? 0);
            }
            $summary['deferred_dates'] = array_merge(
                $summary['deferred_dates'],
                (array) ($result['deferred_dates'] ?? [])
            );
        }
        return $summary;
    }

    $period = payroll_validate_period($start, $end);
    $exists = $pdo->prepare(
        'SELECT id, created_at FROM employees WHERE id=? LIMIT 1 FOR UPDATE'
    );
    $exists->execute([$employeeId]);
    $employee = $exists->fetch();
    if (!$employee) {
        throw new InvalidArgumentException('The selected employee could not be found.');
    }

    // A payroll period may begin before a newly registered employee's first
    // eligible day (for example, a September 1-15 run for someone registered
    // September 5). Those dates are outside the employment relationship; they
    // are not unfinished attendance and must not block the whole payroll run.
    $employeeStart = !empty($employee['created_at'])
        ? DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $employee['created_at'], 0, 10))
        : false;
    if (!$employeeStart instanceof DateTimeImmutable) {
        throw new RuntimeException('The selected employee has an invalid registration date.');
    }

    $processed = 0;
    $deferred = 0;
    $skipped = 0;
    $deferredDates = [];
    $days = 0;
    $asOf = new DateTimeImmutable('now');
    for ($date = $period['start']; $date <= $period['end']; $date = $date->modify('+1 day')) {
        $days++;
        if ($date < $employeeStart) {
            $skipped++;
            continue;
        }
        $row = attendance_process_employee_date(
            $pdo,
            $employeeId,
            $date->format('Y-m-d'),
            $asOf,
            false
        );
        if ($row === null) {
            $deferred++;
            $deferredDates[] = $date->format('Y-m-d');
        } else {
            $processed++;
        }
    }

    return [
        'days' => $days,
        'processed' => $processed,
        'deferred' => $deferred,
        'skipped' => $skipped,
        'deferred_dates' => $deferredDates,
    ];
}

/** Explain why attendance processing stopped a payroll write. */
function payroll_deferred_attendance_message(array $processing, string $result): string
{
    $count = max(0, (int) ($processing['deferred'] ?? 0));
    $dates = array_values(array_unique(array_filter(
        (array) ($processing['deferred_dates'] ?? []),
        static fn(mixed $date): bool => is_string($date)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
    )));

    if ($dates) {
        $shown = array_slice($dates, 0, 5);
        $dateList = implode(', ', $shown);
        if (count($dates) > count($shown)) {
            $dateList .= ', and ' . (count($dates) - count($shown)) . ' more';
        }
        return $count . ' attendance day(s) cannot be finalized yet (' . $dateList . '). '
            . 'Complete the required Time Out/session scans after the assigned schedule ends, '
            . 'or choose a payroll end date before the open day; ' . $result . '.';
    }

    return $count . ' attendance day(s) cannot be finalized yet. Complete the required '
        . 'attendance sessions or choose a fully completed payroll period; ' . $result . '.';
}

/**
 * Build a compact, deterministic fingerprint of every mutable input that can
 * affect this payroll period. The full source rows stay in their own tables;
 * only a SHA-256 digest and row counts are stored with the run.
 *
 * @param array<int, array<string, mixed>> $calculations
 * @return array{version:int, algorithm:string, hash:string, counts:array<string,int>}
 */
function payroll_source_fingerprint(
    PDO $pdo,
    string $start,
    string $end,
    array $calculations
): array {
    $period = payroll_validate_period($start, $end);
    $normalizedCalculations = $calculations;
    usort(
        $normalizedCalculations,
        static fn(array $left, array $right): int => (int) $left['employee_id'] <=> (int) $right['employee_id']
    );
    $employeeIds = [];
    foreach ($normalizedCalculations as &$calculation) {
        $employeeIds[] = (int) $calculation['employee_id'];
        $snapshot = json_decode(
            (string) ($calculation['calculation_snapshot'] ?? ''),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($snapshot)) {
            throw new RuntimeException('A payroll calculation snapshot is invalid.');
        }
        $calculation['calculation_snapshot'] = $snapshot;
        $attendanceIds = array_values(array_map('intval', (array) ($calculation['attendance_ids'] ?? [])));
        sort($attendanceIds, SORT_NUMERIC);
        $calculation['attendance_ids'] = $attendanceIds;
    }
    unset($calculation);
    $employeeIds = array_values(array_unique($employeeIds));
    sort($employeeIds, SORT_NUMERIC);
    $employeePlaceholders = $employeeIds
        ? implode(',', array_fill(0, count($employeeIds), '?'))
        : '';

    $weekdays = [];
    for ($date = $period['start']; $date <= $period['end']; $date = $date->modify('+1 day')) {
        $weekdays[$date->format('l')] = true;
    }
    $weekdays = array_keys($weekdays);
    sort($weekdays, SORT_STRING);

    $rawAttendanceSql =
        'SELECT id, employee_id, fingerprint_id, attendance_date, action, scanned_at,
                device_id, command_uuid, source, event_key
         FROM attendance_logs
         WHERE attendance_date BETWEEN ? AND ?';
    $rawAttendanceParameters = [$start, $end];
    if ($employeePlaceholders !== '') {
        $rawAttendanceSql .= " AND employee_id IN ({$employeePlaceholders})";
        $rawAttendanceParameters = [...$rawAttendanceParameters, ...$employeeIds];
    }
    $rawAttendanceSql .= ' ORDER BY attendance_date, employee_id, action, id FOR UPDATE';
    $stmt = $pdo->prepare($rawAttendanceSql);
    $stmt->execute($rawAttendanceParameters);
    $rawAttendance = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT id, holiday_name, holiday_date, holiday_type, status
         FROM holidays
         WHERE holiday_date BETWEEN ? AND ? AND status="Active"
         ORDER BY holiday_date, id
         FOR UPDATE'
    );
    $stmt->execute([$start, $end]);
    $holidays = $stmt->fetchAll();

    $leaveSql =
        'SELECT id, employee_id, leave_type, start_date, end_date, status
         FROM leave_requests
         WHERE start_date<=? AND end_date>=? AND status="Approved"';
    $leaveParameters = [$end, $start];
    if ($employeePlaceholders !== '') {
        $leaveSql .= " AND employee_id IN ({$employeePlaceholders})";
        $leaveParameters = [...$leaveParameters, ...$employeeIds];
    }
    $leaveSql .= ' ORDER BY employee_id, start_date, end_date, id FOR UPDATE';
    $stmt = $pdo->prepare($leaveSql);
    $stmt->execute($leaveParameters);
    $leaveRequests = $stmt->fetchAll();

    $overtimeSql =
        'SELECT id, employee_id, attendance_id, attendance_date, potential_minutes,
                requested_minutes, approved_minutes, status, request_source
         FROM overtime_requests
         WHERE attendance_date BETWEEN ? AND ?
           AND request_source="Employee"';
    $overtimeParameters = [$start, $end];
    if ($employeePlaceholders !== '') {
        $overtimeSql .= " AND employee_id IN ({$employeePlaceholders})";
        $overtimeParameters = [...$overtimeParameters, ...$employeeIds];
    }
    $overtimeSql .= ' ORDER BY attendance_date, employee_id, id FOR UPDATE';
    $stmt = $pdo->prepare($overtimeSql);
    $stmt->execute($overtimeParameters);
    $overtimeRequests = $stmt->fetchAll();

    $defaultSchedules = [];
    $employeeSchedules = [];
    $employeeSchedulePeriods = [];
    $deductionEntries = [];
    if ($weekdays) {
        $weekdayPlaceholders = implode(',', array_fill(0, count($weekdays), '?'));
        $stmt = $pdo->prepare(
            "SELECT day_of_week, schedule_type, shift_start, shift_end, break_minutes
             FROM default_work_schedules
             WHERE day_of_week IN ({$weekdayPlaceholders})
             ORDER BY day_of_week
             FOR UPDATE"
        );
        $stmt->execute($weekdays);
        $defaultSchedules = $stmt->fetchAll();

        if ($employeeIds) {
            $stmt = $pdo->prepare(
                "SELECT employee_id, day_of_week, schedule_type, shift_start, shift_end, break_minutes
                 FROM work_schedules
                 WHERE employee_id IN ({$employeePlaceholders})
                   AND day_of_week IN ({$weekdayPlaceholders})
                 ORDER BY employee_id, day_of_week"
                    . ' FOR UPDATE'
            );
            $stmt->execute([...$employeeIds, ...$weekdays]);
            $employeeSchedules = $stmt->fetchAll();

            if (attendance_has_period_schedule_schema($pdo)) {
                $stmt = $pdo->prepare(
                    "SELECT ws.employee_id, ws.day_of_week, wsp.period_order,
                            wsp.period_start, wsp.period_end
                     FROM work_schedule_periods wsp
                     JOIN work_schedules ws ON ws.id=wsp.work_schedule_id
                     WHERE ws.employee_id IN ({$employeePlaceholders})
                       AND ws.day_of_week IN ({$weekdayPlaceholders})
                     ORDER BY ws.employee_id, ws.day_of_week, wsp.period_order, wsp.id"
                        . ' FOR UPDATE'
                );
                $stmt->execute([...$employeeIds, ...$weekdays]);
                $employeeSchedulePeriods = $stmt->fetchAll();
            }

            $stmt = $pdo->prepare(
                "SELECT id, employee_id, deduction_date, deduction_type, amount, description, status
                 FROM employee_deduction_entries
                 WHERE employee_id IN ({$employeePlaceholders})
                   AND deduction_date BETWEEN ? AND ?
                 ORDER BY employee_id, deduction_date, id"
                    . ' FOR UPDATE'
            );
            $stmt->execute([...$employeeIds, $start, $end]);
            $deductionEntries = $stmt->fetchAll();
        }
    }

    $payload = [
        'version' => PAYROLL_SOURCE_FINGERPRINT_VERSION,
        'period' => ['start' => $start, 'end' => $end],
        'calculations' => $normalizedCalculations,
        'raw_attendance' => $rawAttendance,
        'approved_leave' => $leaveRequests,
        'active_holidays' => $holidays,
        'overtime_requests' => $overtimeRequests,
        'default_schedules' => $defaultSchedules,
        'employee_schedules' => $employeeSchedules,
        'employee_schedule_periods' => $employeeSchedulePeriods,
        'deduction_entries' => $deductionEntries,
    ];
    $counts = [];
    foreach ($payload as $key => $rows) {
        if (is_array($rows) && $key !== 'period') {
            $counts[$key] = count($rows);
        }
    }

    return [
        'version' => PAYROLL_SOURCE_FINGERPRINT_VERSION,
        'algorithm' => 'sha256',
        'hash' => hash('sha256', payroll_json($payload)),
        'counts' => $counts,
    ];
}

/**
 * Compare the current source state with the digest captured by generation or
 * Draft recalculation. Validation errors are treated as stale source data;
 * database failures still bubble up without changing lifecycle state.
 *
 * @return array{matches:bool, expected:?array, current:?array, reason:?string}
 */
function payroll_validate_run_source(PDO $pdo, array $run): array
{
    try {
        $runSnapshot = json_decode(
            (string) ($run['policy_snapshot'] ?? ''),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        $runSnapshot = null;
    }
    $expected = is_array($runSnapshot) && is_array($runSnapshot['source_fingerprint'] ?? null)
        ? $runSnapshot['source_fingerprint']
        : null;
    $policy = is_array($runSnapshot) && is_array($runSnapshot['policy'] ?? null)
        ? $runSnapshot['policy']
        : null;
    $scopeEmployeeId = (int) ($run['scope_employee_id'] ?? 0);
    $scopeEmployeeId = $scopeEmployeeId > 0 ? $scopeEmployeeId : null;
    if (!is_array($expected) || !is_string($expected['hash'] ?? null) || $policy === null) {
        return [
            'matches' => false,
            'expected' => $expected,
            'current' => null,
            'reason' => 'This run predates source validation or has an incomplete snapshot.',
        ];
    }

    try {
        $calculations = payroll_build_calculations(
            $pdo,
            (string) $run['period_start'],
            (string) $run['period_end'],
            $policy,
            $scopeEmployeeId
        );
        $current = payroll_source_fingerprint(
            $pdo,
            (string) $run['period_start'],
            (string) $run['period_end'],
            $calculations
        );
    } catch (PDOException $error) {
        throw $error;
    } catch (Throwable $error) {
        return [
            'matches' => false,
            'expected' => $expected,
            'current' => null,
            'reason' => $error->getMessage(),
        ];
    }

    return [
        'matches' => hash_equals((string) $expected['hash'], (string) $current['hash']),
        'expected' => $expected,
        'current' => $current,
        'reason' => null,
    ];
}

function payroll_insert_items(PDO $pdo, int $runId, array $calculations): void
{
    $item = $pdo->prepare(
        'INSERT INTO payroll_items
            (payroll_run_id, employee_id, employment_type, pay_type, basic_rate,
             days_worked, regular_minutes, regular_hours, hourly_equivalent_rate, monthly_basic_salary, regular_pay,
             late_minutes, late_deduction, undertime_minutes, undertime_deduction, half_day_deduction, absence_deduction,
             cash_advance_deduction, other_deductions,
             absence_days, unpaid_leave_days,
             approved_overtime_minutes, overtime_hours, ot_rate, overtime_pay,
             holiday_hours, holiday_pay, rest_day_hours, rest_day_pay, other_earnings,
             gross_pay, total_deductions, net_pay, calculation_snapshot)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $link = $pdo->prepare(
        'INSERT INTO payroll_item_attendance (payroll_item_id, attendance_id) VALUES (?, ?)'
    );
    foreach ($calculations as $calculation) {
        $item->execute([
            $runId,
            $calculation['employee_id'],
            $calculation['employment_type'],
            $calculation['pay_type'],
            $calculation['basic_rate'],
            $calculation['days_worked'],
            $calculation['regular_minutes'],
            $calculation['regular_hours'],
            $calculation['hourly_equivalent_rate'],
            $calculation['monthly_basic_salary'],
            $calculation['regular_pay'],
            $calculation['late_minutes'],
            $calculation['late_deduction'],
            $calculation['undertime_minutes'],
            $calculation['undertime_deduction'],
            $calculation['half_day_deduction'],
            $calculation['absence_deduction'],
            $calculation['cash_advance_deduction'],
            $calculation['other_deductions'],
            $calculation['absence_days'],
            $calculation['unpaid_leave_days'],
            $calculation['approved_overtime_minutes'],
            $calculation['overtime_hours'],
            $calculation['ot_rate'],
            $calculation['overtime_pay'],
            $calculation['holiday_hours'],
            $calculation['holiday_pay'],
            $calculation['rest_day_hours'],
            $calculation['rest_day_pay'],
            $calculation['other_earnings'],
            $calculation['gross_pay'],
            $calculation['total_deductions'],
            $calculation['net_pay'],
            $calculation['calculation_snapshot'],
        ]);
        $itemId = (int) $pdo->lastInsertId();
        foreach ($calculation['attendance_ids'] as $attendanceId) {
            $link->execute([$itemId, $attendanceId]);
        }
    }
}

function payroll_write_audit(
    PDO $pdo,
    int $actorUserId,
    string $action,
    int $runId,
    string $description,
    ?array $oldValues,
    ?array $newValues
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs
            (user_id, action, module, record_id, description, old_values, new_values, ip_address, created_at)
         VALUES (?, ?, "Payroll", ?, ?, ?, ?, NULL, NOW())'
    );
    $stmt->execute([
        $actorUserId,
        substr($action, 0, 255),
        (string) $runId,
        $description,
        $oldValues === null ? null : payroll_json($oldValues),
        $newValues === null ? null : payroll_json($newValues),
    ]);
}

/**
 * Run the same attendance and salary pipeline used by Draft creation, but
 * roll back its attendance refresh so a validation preview never writes data.
 * The subsequent POST calculates again under the payroll advisory lock.
 *
 * @return array<string,mixed>
 */
function payroll_preview_employee(
    PDO $pdo,
    string $start,
    string $end,
    int $employeeId,
    float $cashAdvanceAmount = 0.0
): array
{
    payroll_validate_period($start, $end);
    if ($employeeId < 1 || $pdo->inTransaction()) {
        throw new InvalidArgumentException('Select one employee for payroll validation.');
    }
    $pdo->beginTransaction();
    try {
        payroll_assert_integrated_schema($pdo);
        $stmt = $pdo->prepare(
            'SELECT employee_no, status FROM employees WHERE id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        if (!$employee || (string) $employee['status'] !== 'Active') {
            throw new RuntimeException('This employee is not active and cannot be included in payroll.');
        }
        payroll_assert_no_overlap($pdo, $start, $end, null, $employeeId);
        $processing = payroll_process_attendance_scope($pdo, $start, $end, $employeeId);
        if ((int) $processing['deferred'] > 0) {
            throw new RuntimeException(payroll_deferred_attendance_message(
                $processing,
                'payroll validation is incomplete'
            ));
        }
        payroll_assert_no_foreign_attendance_links($pdo, $start, $end, null, $employeeId);
        $calculated = payroll_build_calculations(
            $pdo,
            $start,
            $end,
            payroll_load_policy($pdo),
            $employeeId,
            $cashAdvanceAmount
        );
        if (count($calculated) !== 1 || (int) $calculated[0]['employee_id'] !== $employeeId) {
            throw new RuntimeException('The employee calculation could not be isolated for validation.');
        }
        $pdo->rollBack();
        return $calculated[0];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Read-only validation of a mixed Full-Time/Part-Time payroll period. Each
 * employee is isolated by a savepoint so HR sees every actionable error in
 * one pass. No attendance projection or deduction is committed by preview.
 *
 * @return array{employees:list<array<string,mixed>>,ready:int,needs_attention:int,full_time:int,part_time:int}
 */
function payroll_preview_batch(PDO $pdo, string $start, string $end): array
{
    payroll_validate_period($start, $end);
    if ($pdo->inTransaction()) {
        throw new LogicException('Payroll batch preview must own its transaction.');
    }
    $pdo->beginTransaction();
    try {
        payroll_assert_integrated_schema($pdo);
        payroll_assert_no_foreign_attendance_links($pdo, $start, $end);
        $stmt = $pdo->prepare(
            'SELECT id, employee_no, first_name, last_name, employment_type
             FROM employees WHERE status="Active" AND DATE(created_at)<=?
             ORDER BY employment_type, last_name, first_name, id FOR UPDATE'
        );
        $stmt->execute([$end]);
        $employees = $stmt->fetchAll();
        if (!$employees) {
            throw new RuntimeException('No active employees existed in the selected payroll period.');
        }
        $result = ['employees' => [], 'ready' => 0, 'needs_attention' => 0, 'full_time' => 0, 'part_time' => 0];
        $policy = payroll_load_policy($pdo);
        foreach ($employees as $employee) {
            $employeeId = (int) $employee['id'];
            $employmentType = (string) $employee['employment_type'];
            if ($employmentType === 'Full-Time') {
                $result['full_time']++;
            } elseif ($employmentType === 'Part-Time') {
                $result['part_time']++;
            }
            $item = [
                'employee_id' => $employeeId,
                'employee_no' => (string) $employee['employee_no'],
                'name' => trim((string) $employee['first_name'] . ' ' . (string) $employee['last_name']),
                'employment_type' => $employmentType,
                'status' => 'NEEDS REVIEW',
                'reason' => '',
                'calculation' => null,
            ];
            $pdo->exec('SAVEPOINT payroll_preview_employee');
            try {
                payroll_assert_no_overlap($pdo, $start, $end, null, $employeeId);
                $processing = payroll_process_attendance_scope($pdo, $start, $end, $employeeId);
                if ((int) $processing['deferred'] > 0) {
                    throw new RuntimeException(payroll_deferred_attendance_message($processing, 'validation is incomplete'));
                }
                payroll_assert_no_foreign_attendance_links($pdo, $start, $end, null, $employeeId);
                $calculated = payroll_build_calculations($pdo, $start, $end, $policy, $employeeId);
                if (count($calculated) !== 1 || (int) $calculated[0]['employee_id'] !== $employeeId) {
                    throw new RuntimeException('The employee calculation could not be isolated safely.');
                }
                $item['status'] = 'READY';
                $item['calculation'] = $calculated[0];
                $result['ready']++;
            } catch (Throwable $error) {
                $item['reason'] = $error->getMessage();
                $result['needs_attention']++;
            } finally {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_preview_employee');
                $pdo->exec('RELEASE SAVEPOINT payroll_preview_employee');
            }
            $result['employees'][] = $item;
        }
        $pdo->rollBack();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** Generate a Pending Draft or an explicitly confirmed Paid payroll run. */
function payroll_generate_run(
    PDO $pdo,
    string $periodStart,
    string $periodEnd,
    int $actorUserId,
    ?string $notes = null,
    ?int $employeeId = null,
    string $paymentMethod = 'Cash',
    float $cashAdvanceAmount = 0.0,
    string $paymentStatus = 'Pending'
): int {
    payroll_validate_period($periodStart, $periodEnd);
    if ($employeeId !== null && $employeeId < 1) {
        throw new InvalidArgumentException('Select a valid employee for payroll.');
    }
    if (!in_array($paymentMethod, ['Cash', 'Bank Transfer'], true)) {
        throw new InvalidArgumentException('Select Cash or Bank Transfer as the payment method.');
    }
    if (!in_array($paymentStatus, ['Pending', 'Paid'], true)) {
        throw new InvalidArgumentException('Select Pending or Paid as the payment status.');
    }
    if (!is_finite($cashAdvanceAmount) || $cashAdvanceAmount < 0 || $cashAdvanceAmount > 999999999.99) {
        throw new InvalidArgumentException('Cash Advance must be between 0.00 and 999,999,999.99.');
    }
    $cashAdvanceAmount = round($cashAdvanceAmount, 2, PHP_ROUND_HALF_UP);
    if ($cashAdvanceAmount > 0 && $employeeId === null) {
        throw new InvalidArgumentException('Select one employee before adding a Cash Advance.');
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('payroll_generate_run must own its transaction.');
    }

    $lockName = null;
    $pdo->beginTransaction();
    try {
        $lockName = payroll_acquire_lock($pdo);
        payroll_assert_integrated_schema($pdo);
        payroll_assert_actor($pdo, $actorUserId);
        $scopeEmployee = null;
        if ($employeeId !== null) {
            $employeeStmt = $pdo->prepare(
                'SELECT id, employee_no, first_name, last_name, status, pay_type
                 FROM employees WHERE id=? LIMIT 1 FOR UPDATE'
            );
            $employeeStmt->execute([$employeeId]);
            $scopeEmployee = $employeeStmt->fetch();
            if (!$scopeEmployee || (string) $scopeEmployee['status'] !== 'Active') {
                throw new RuntimeException('Only an active employee can be selected for a new payroll run.');
            }
            // Validate pay type and rate from the dated HR-approved history,
            // not from the employee row's current display value.
        }
        payroll_assert_no_overlap($pdo, $periodStart, $periodEnd, null, $employeeId);

        // A Cash Advance entered in Generate Payroll is a first-class source
        // record. Creating it inside this transaction makes the operation
        // atomic: failed attendance processing or payroll calculation rolls
        // back both the payroll and its new Cash Advance.
        $cashAdvanceEntryId = null;
        if ($cashAdvanceAmount > 0) {
            $cashAdvanceStmt = $pdo->prepare(
                'INSERT INTO employee_deduction_entries
                    (employee_id, deduction_date, deduction_type, amount, description, status, created_by)
                 VALUES (?, ?, "Cash Advance", ?, ?, "Active", ?)'
            );
            $cashAdvanceStmt->execute([
                $employeeId,
                $periodEnd,
                $cashAdvanceAmount,
                "Entered during payroll generation for {$periodStart} through {$periodEnd}.",
                $actorUserId,
            ]);
            $cashAdvanceEntryId = (int) $pdo->lastInsertId();
        }

        $processing = payroll_process_attendance_scope($pdo, $periodStart, $periodEnd, $employeeId);
        if ((int) $processing['deferred'] > 0) {
            throw new RuntimeException(payroll_deferred_attendance_message(
                $processing,
                'payroll was not generated'
            ));
        }
        payroll_assert_no_foreign_attendance_links($pdo, $periodStart, $periodEnd, null, $employeeId);
        $policy = payroll_load_policy($pdo);
        $calculations = payroll_build_calculations($pdo, $periodStart, $periodEnd, $policy, $employeeId);
        if (
            $employeeId !== null
            && (count($calculations) !== 1 || (int) $calculations[0]['employee_id'] !== $employeeId)
        ) {
            throw new RuntimeException('The selected employee payroll calculation could not be isolated safely.');
        }
        if ($paymentStatus === 'Paid') {
            foreach ($calculations as $calculation) {
                if ((float) $calculation['net_pay'] <= 0) {
                    throw new RuntimeException(
                        'Employee #' . (int) $calculation['employee_id']
                        . ' has no Net Pay and cannot be recorded as Paid. Review attendance and deductions first.'
                    );
                }
            }
        }
        $sourceFingerprint = payroll_source_fingerprint(
            $pdo,
            $periodStart,
            $periodEnd,
            $calculations
        );

        $runSnapshot = [
            'version' => PAYROLL_CALCULATION_VERSION,
            'policy' => $policy,
            'source_fingerprint' => $sourceFingerprint,
            'notes' => ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
            'scope' => $scopeEmployee ? [
                'employee_id' => $employeeId,
                'employee_no' => (string) $scopeEmployee['employee_no'],
                'employee_name' => trim((string) $scopeEmployee['first_name'] . ' ' . (string) $scopeEmployee['last_name']),
            ] : ['employee_id' => null, 'employee_no' => null, 'employee_name' => 'All eligible employees'],
            'payment' => ['method' => $paymentMethod, 'status' => $paymentStatus],
            'cash_advance' => [
                'amount' => $cashAdvanceAmount,
                'source_entry_id' => $cashAdvanceEntryId,
                'deduction_date' => $cashAdvanceEntryId !== null ? $periodEnd : null,
            ],
            'attendance_processing' => $processing,
        ];
        if ($paymentStatus === 'Paid') {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_runs
                    (period_start, period_end, scope_employee_id, processed_by, processed_at,
                     status, payment_method, payment_status, payment_updated_at,
                     reviewed_by, reviewed_at, approved_by, approved_at,
                     finalized_by, finalized_at, paid_at, policy_snapshot)
                 VALUES (?, ?, ?, ?, NOW(), "Paid", ?, "Paid", NOW(),
                         ?, NOW(), ?, NOW(), ?, NOW(), NOW(), ?)'
            );
            $stmt->execute([
                $periodStart, $periodEnd, $employeeId, $actorUserId,
                $paymentMethod, $actorUserId, $actorUserId, $actorUserId,
                payroll_json($runSnapshot),
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_runs
                    (period_start, period_end, scope_employee_id, processed_by, processed_at,
                     status, payment_method, payment_status, payment_updated_at, policy_snapshot)
                 VALUES (?, ?, ?, ?, NOW(), "Draft", ?, "Pending", NOW(), ?)'
            );
            $stmt->execute([
                $periodStart, $periodEnd, $employeeId, $actorUserId,
                $paymentMethod, payroll_json($runSnapshot),
            ]);
        }
        $runId = (int) $pdo->lastInsertId();
        payroll_insert_items($pdo, $runId, $calculations);
        payroll_write_audit(
            $pdo,
            $actorUserId,
            $paymentStatus === 'Paid' ? 'Generated paid payroll' : 'Generated payroll draft',
            $runId,
            'Generated ' . ($paymentStatus === 'Paid' ? 'Paid' : 'Draft') . ' payroll for '
                . ($scopeEmployee ? (string) $scopeEmployee['employee_no'] : 'all eligible employees')
                . " from {$periodStart} through {$periodEnd} using {$paymentMethod}."
                . ($paymentStatus === 'Paid' ? ' HR/Admin confirmed that payment was already made.' : ''),
            null,
            [
                'status' => $paymentStatus === 'Paid' ? 'Paid' : 'Draft',
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'cash_advance_amount' => $cashAdvanceAmount,
                'cash_advance_source_entry_id' => $cashAdvanceEntryId,
                'employee_id' => $employeeId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        );
        $pdo->commit();
        payroll_release_lock($pdo, $lockName);
        return $runId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        payroll_release_lock($pdo, $lockName);
        throw $error;
    }
}

/** Rebuild a Draft in place. Finalized history and consumed rows remain immutable. */
function payroll_recalculate_draft(PDO $pdo, int $runId, int $actorUserId): void
{
    if ($runId < 1) {
        throw new InvalidArgumentException('A valid payroll run is required.');
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('payroll_recalculate_draft must own its transaction.');
    }

    $lockName = null;
    $pdo->beginTransaction();
    try {
        $lockName = payroll_acquire_lock($pdo);
        payroll_assert_integrated_schema($pdo);
        payroll_assert_actor($pdo, $actorUserId);
        $stmt = $pdo->prepare('SELECT * FROM payroll_runs WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$runId]);
        $run = $stmt->fetch();
        if (!$run) {
            throw new InvalidArgumentException('Payroll run was not found.');
        }
        if ((string) $run['status'] !== 'Draft') {
            throw new RuntimeException('Only a Draft payroll run can be recalculated.');
        }
        $periodStart = (string) $run['period_start'];
        $periodEnd = (string) $run['period_end'];
        $scopeEmployeeId = (int) ($run['scope_employee_id'] ?? 0);
        $scopeEmployeeId = $scopeEmployeeId > 0 ? $scopeEmployeeId : null;
        payroll_validate_period($periodStart, $periodEnd);
        payroll_assert_no_overlap($pdo, $periodStart, $periodEnd, $runId, $scopeEmployeeId);

        $processing = payroll_process_attendance_scope($pdo, $periodStart, $periodEnd, $scopeEmployeeId);
        if ((int) $processing['deferred'] > 0) {
            throw new RuntimeException(payroll_deferred_attendance_message(
                $processing,
                'the Draft was not changed'
            ));
        }
        payroll_assert_no_foreign_attendance_links($pdo, $periodStart, $periodEnd, $runId, $scopeEmployeeId);
        $policy = payroll_load_policy($pdo);
        $calculations = payroll_build_calculations($pdo, $periodStart, $periodEnd, $policy, $scopeEmployeeId);
        $sourceFingerprint = payroll_source_fingerprint(
            $pdo,
            $periodStart,
            $periodEnd,
            $calculations
        );

        $previousSnapshot = json_decode((string) ($run['policy_snapshot'] ?? ''), true);
        $notes = is_array($previousSnapshot) ? ($previousSnapshot['notes'] ?? null) : null;
        $scopeSnapshot = is_array($previousSnapshot) ? ($previousSnapshot['scope'] ?? null) : null;
        $runSnapshot = [
            'version' => PAYROLL_CALCULATION_VERSION,
            'policy' => $policy,
            'source_fingerprint' => $sourceFingerprint,
            'notes' => $notes,
            'scope' => is_array($scopeSnapshot)
                ? $scopeSnapshot
                : ['employee_id' => $scopeEmployeeId],
            'payment' => [
                'method' => (string) ($run['payment_method'] ?? 'Cash'),
                'status' => (string) ($run['payment_status'] ?? 'Pending'),
            ],
            'cash_advance' => is_array($previousSnapshot['cash_advance'] ?? null)
                ? $previousSnapshot['cash_advance']
                : null,
            'attendance_processing' => $processing,
        ];
        $stmt = $pdo->prepare('DELETE FROM payroll_items WHERE payroll_run_id=?');
        $stmt->execute([$runId]);
        payroll_insert_items($pdo, $runId, $calculations);
        $stmt = $pdo->prepare(
            'UPDATE payroll_runs
             SET processed_by=?, processed_at=NOW(), policy_snapshot=?, updated_at=NOW()
             WHERE id=? AND status="Draft"'
        );
        $stmt->execute([$actorUserId, payroll_json($runSnapshot), $runId]);
        // MySQL reports zero affected rows when recalculation occurs within the
        // same second and every stored value is identical.  The run is already
        // locked, so verify its state instead of treating that no-op as a race.
        $verify = $pdo->prepare('SELECT status FROM payroll_runs WHERE id=? LIMIT 1');
        $verify->execute([$runId]);
        if ((string) $verify->fetchColumn() !== 'Draft') {
            throw new RuntimeException('Payroll Draft changed concurrently and was not recalculated.');
        }
        payroll_write_audit(
            $pdo,
            $actorUserId,
            'Recalculated payroll draft',
            $runId,
            "Recalculated Draft payroll for {$periodStart} through {$periodEnd}.",
            ['processed_by' => (int) $run['processed_by'], 'policy_snapshot' => $run['policy_snapshot']],
            ['processed_by' => $actorUserId, 'policy_snapshot' => $runSnapshot]
        );
        $pdo->commit();
        payroll_release_lock($pdo, $lockName);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        payroll_release_lock($pdo, $lockName);
        throw $error;
    }
}

/**
 * Delete an unreviewed Draft so its period may be generated again. Official
 * or formerly reviewed payroll is never erased. Items/attendance ownership
 * links cascade; source attendance remains untouched. An auto-created Cash
 * Advance is removed only when it still exactly matches the Draft snapshot.
 *
 * @return array{period_start:string,period_end:string,scope_employee_id:?int,items:int,cash_advance_removed:bool}
 */
function payroll_delete_draft(PDO $pdo, int $runId, int $actorUserId, string $reason): array
{
    $reason = trim($reason);
    if ($runId < 1 || $reason === '' || mb_strlen($reason) > 1000) {
        throw new InvalidArgumentException('Select a Draft and enter a deletion reason of at most 1,000 characters.');
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('payroll_delete_draft must own its transaction.');
    }

    $lockName = null;
    $pdo->beginTransaction();
    try {
        $lockName = payroll_acquire_lock($pdo);
        payroll_assert_integrated_schema($pdo);
        payroll_assert_actor($pdo, $actorUserId);
        $stmt = $pdo->prepare('SELECT * FROM payroll_runs WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$runId]);
        $run = $stmt->fetch();
        if (!$run) {
            throw new InvalidArgumentException('Payroll run was not found.');
        }
        if ((string) $run['status'] !== 'Draft' || (string) $run['payment_status'] !== 'Pending') {
            throw new RuntimeException('Only an unpaid Draft can be deleted. Approved, Finalized, Paid, and Released payroll history is protected.');
        }
        $reviewHistory = $pdo->prepare(
            'SELECT COUNT(*) FROM activity_logs
             WHERE module="Payroll" AND record_id=?
               AND action IN ("Transitioned payroll status","Returned payroll to draft")'
        );
        $reviewHistory->execute([(string) $runId]);
        if ((int) $reviewHistory->fetchColumn() > 0 || $run['reviewed_at'] !== null || $run['approved_at'] !== null) {
            throw new RuntimeException('This Draft previously entered review. Use Recalculate Draft instead; its review history cannot be deleted.');
        }

        $totals = $pdo->prepare(
            'SELECT COUNT(*) AS item_count, COALESCE(SUM(net_pay),0) AS net_pay
             FROM payroll_items WHERE payroll_run_id=? FOR UPDATE'
        );
        $totals->execute([$runId]);
        $itemSummary = $totals->fetch();
        $snapshot = json_decode((string) ($run['policy_snapshot'] ?? ''), true);
        $cashAdvance = is_array($snapshot) && is_array($snapshot['cash_advance'] ?? null)
            ? $snapshot['cash_advance'] : [];
        $sourceId = (int) ($cashAdvance['source_entry_id'] ?? 0);
        $cashAdvanceRemoved = false;
        if ($sourceId > 0) {
            $entryStmt = $pdo->prepare(
                'SELECT id, employee_id, deduction_date, deduction_type, amount, description, status
                 FROM employee_deduction_entries WHERE id=? LIMIT 1 FOR UPDATE'
            );
            $entryStmt->execute([$sourceId]);
            $entry = $entryStmt->fetch();
            $expectedDescription = 'Entered during payroll generation for '
                . $run['period_start'] . ' through ' . $run['period_end'] . '.';
            if (!$entry
                || (int) $entry['employee_id'] !== (int) $run['scope_employee_id']
                || (string) $entry['deduction_date'] !== (string) $run['period_end']
                || (string) $entry['deduction_type'] !== 'Cash Advance'
                || (string) $entry['status'] !== 'Active'
                || (string) $entry['description'] !== $expectedDescription
                || abs((float) $entry['amount'] - (float) ($cashAdvance['amount'] ?? -1)) > 0.005) {
                throw new RuntimeException('The Draft Cash Advance source changed. Review it before deleting this Draft to avoid a duplicate deduction.');
            }
            $deleteEntry = $pdo->prepare('DELETE FROM employee_deduction_entries WHERE id=?');
            $deleteEntry->execute([$sourceId]);
            if ($deleteEntry->rowCount() !== 1) {
                throw new RuntimeException('Cash Advance changed concurrently; the Draft was not deleted.');
            }
            $cashAdvanceRemoved = true;
        }

        $deleteRun = $pdo->prepare('DELETE FROM payroll_runs WHERE id=? AND status="Draft" AND payment_status="Pending"');
        $deleteRun->execute([$runId]);
        if ($deleteRun->rowCount() !== 1) {
            throw new RuntimeException('Payroll Draft changed concurrently; it was not deleted.');
        }
        $result = [
            'period_start' => (string) $run['period_start'],
            'period_end' => (string) $run['period_end'],
            'scope_employee_id' => $run['scope_employee_id'] === null ? null : (int) $run['scope_employee_id'],
            'items' => (int) ($itemSummary['item_count'] ?? 0),
            'cash_advance_removed' => $cashAdvanceRemoved,
        ];
        payroll_write_audit(
            $pdo,
            $actorUserId,
            'Deleted payroll draft',
            $runId,
            "Deleted unreviewed Draft #{$runId} for recalculation. Reason: {$reason}",
            [
                'status' => 'Draft',
                'period_start' => $result['period_start'],
                'period_end' => $result['period_end'],
                'scope_employee_id' => $result['scope_employee_id'],
                'item_count' => $result['items'],
                'net_pay' => (float) ($itemSummary['net_pay'] ?? 0),
                'cash_advance_source_entry_id' => $sourceId ?: null,
            ],
            ['deleted' => true, 'reason' => $reason, 'cash_advance_removed' => $cashAdvanceRemoved]
        );
        $pdo->commit();
        payroll_release_lock($pdo, $lockName);
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        payroll_release_lock($pdo, $lockName);
        throw $error;
    }
}

/**
 * Return a review-stage payroll to Draft so its source records can be corrected.
 * Finalized, Paid, and legacy Released runs deliberately have no reverse path.
 */
function payroll_return_to_draft(
    PDO $pdo,
    int $runId,
    int $actorUserId,
    ?string $note = null
): void {
    if ($runId < 1) {
        throw new InvalidArgumentException('A valid payroll run is required.');
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('payroll_return_to_draft must own its transaction.');
    }

    $lockName = null;
    $pdo->beginTransaction();
    try {
        $lockName = payroll_acquire_lock($pdo);
        payroll_assert_integrated_schema($pdo);
        payroll_assert_actor($pdo, $actorUserId);
        $stmt = $pdo->prepare('SELECT * FROM payroll_runs WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$runId]);
        $run = $stmt->fetch();
        if (!$run) {
            throw new InvalidArgumentException('Payroll run was not found.');
        }
        $fromStatus = (string) $run['status'];
        if (!in_array($fromStatus, ['For Review', 'Approved'], true)) {
            throw new RuntimeException('Only a For Review or Approved payroll can return to Draft. Finalized and paid history is immutable.');
        }

        $update = $pdo->prepare(
            'UPDATE payroll_runs
             SET status="Draft", reviewed_by=NULL, reviewed_at=NULL,
                 approved_by=NULL, approved_at=NULL, updated_at=NOW()
             WHERE id=? AND status=?'
        );
        $update->execute([$runId, $fromStatus]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Payroll status changed concurrently; it was not returned to Draft.');
        }
        payroll_write_audit(
            $pdo,
            $actorUserId,
            'Returned payroll to draft',
            $runId,
            "Returned payroll from {$fromStatus} to Draft for correction."
                . (($note !== null && trim($note) !== '') ? ' Note: ' . trim($note) : ''),
            ['status' => $fromStatus],
            ['status' => 'Draft', 'note' => ($note !== null && trim($note) !== '') ? trim($note) : null]
        );
        $pdo->commit();
        payroll_release_lock($pdo, $lockName);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        payroll_release_lock($pdo, $lockName);
        throw $error;
    }
}

/** Update disbursement method without changing calculated payroll amounts. */
function payroll_update_payment_method(
    PDO $pdo,
    int $runId,
    string $paymentMethod,
    int $actorUserId
): void {
    if ($runId < 1 || !in_array($paymentMethod, ['Cash', 'Bank Transfer'], true)) {
        throw new InvalidArgumentException('Select a valid payroll run and payment method.');
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('payroll_update_payment_method must own its transaction.');
    }

    $lockName = null;
    $pdo->beginTransaction();
    try {
        $lockName = payroll_acquire_lock($pdo);
        payroll_assert_integrated_schema($pdo);
        payroll_assert_actor($pdo, $actorUserId);
        $stmt = $pdo->prepare('SELECT id, status, payment_method FROM payroll_runs WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$runId]);
        $run = $stmt->fetch();
        if (!$run) {
            throw new InvalidArgumentException('Payroll run was not found.');
        }
        if (in_array((string) $run['status'], ['Paid', 'Released'], true)) {
            throw new RuntimeException('The payment method of paid payroll history is immutable.');
        }

        $pdo->prepare(
            'UPDATE payroll_runs SET payment_method=?, payment_updated_at=NOW(), updated_at=NOW() WHERE id=?'
        )->execute([$paymentMethod, $runId]);
        payroll_write_audit(
            $pdo,
            $actorUserId,
            'Updated payroll payment method',
            $runId,
            "Changed payroll payment method to {$paymentMethod}.",
            ['payment_method' => (string) $run['payment_method']],
            ['payment_method' => $paymentMethod]
        );
        $pdo->commit();
        payroll_release_lock($pdo, $lockName);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        payroll_release_lock($pdo, $lockName);
        throw $error;
    }
}

/** Advance a run through the only supported lifecycle. */
function payroll_transition_run(
    PDO $pdo,
    int $runId,
    string $toStatus,
    int $actorUserId,
    ?string $note = null
): void {
    if ($runId < 1) {
        throw new InvalidArgumentException('A valid payroll run is required.');
    }
    $allowedNext = [
        'Draft' => 'For Review',
        'For Review' => 'Approved',
        'Approved' => 'Finalized',
        'Finalized' => 'Paid',
    ];
    if (!in_array($toStatus, array_values($allowedNext), true)) {
        throw new InvalidArgumentException('Unsupported payroll target status.');
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('payroll_transition_run must own its transaction.');
    }

    $lockName = null;
    $pdo->beginTransaction();
    try {
        $lockName = payroll_acquire_lock($pdo);
        payroll_assert_integrated_schema($pdo);
        payroll_assert_actor($pdo, $actorUserId);
        $stmt = $pdo->prepare('SELECT * FROM payroll_runs WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$runId]);
        $run = $stmt->fetch();
        if (!$run) {
            throw new InvalidArgumentException('Payroll run was not found.');
        }
        $fromStatus = (string) $run['status'];
        if (in_array($fromStatus, ['Paid', 'Released'], true)) {
            throw new RuntimeException("{$fromStatus} payroll history is immutable.");
        }
        if (($allowedNext[$fromStatus] ?? null) !== $toStatus) {
            throw new RuntimeException("Payroll can only advance from {$fromStatus} to " . ($allowedNext[$fromStatus] ?? 'no further status') . '.');
        }
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM payroll_items WHERE payroll_run_id=?');
        $countStmt->execute([$runId]);
        if ((int) $countStmt->fetchColumn() < 1) {
            throw new RuntimeException('An empty payroll run cannot advance.');
        }

        $sourceValidation = payroll_validate_run_source($pdo, $run);
        if (!$sourceValidation['matches']) {
            $reason = trim((string) ($sourceValidation['reason'] ?? ''));
            throw new RuntimeException(
                'Payroll source data changed after this calculation. Return the run to Draft and recalculate it before continuing.'
                    . ($reason !== '' ? ' ' . $reason : '')
            );
        }

        $sql = match ($toStatus) {
            'For Review' => 'UPDATE payroll_runs SET status="For Review", updated_at=NOW() WHERE id=? AND status="Draft"',
            'Approved' => 'UPDATE payroll_runs SET status="Approved", reviewed_by=?, reviewed_at=NOW(), approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id=? AND status="For Review"',
            'Finalized' => 'UPDATE payroll_runs SET status="Finalized", finalized_by=?, finalized_at=NOW(), updated_at=NOW() WHERE id=? AND status="Approved"',
            'Paid' => 'UPDATE payroll_runs SET status="Paid", payment_status="Paid", payment_updated_at=NOW(), paid_at=NOW(), updated_at=NOW() WHERE id=? AND status="Finalized"',
        };
        $parameters = match ($toStatus) {
            'For Review', 'Paid' => [$runId],
            'Approved' => [$actorUserId, $actorUserId, $runId],
            'Finalized' => [$actorUserId, $runId],
        };
        $stmt = $pdo->prepare($sql);
        $stmt->execute($parameters);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Payroll status changed concurrently; no transition was applied.');
        }
        payroll_write_audit(
            $pdo,
            $actorUserId,
            'Transitioned payroll status',
            $runId,
            "Changed payroll status from {$fromStatus} to {$toStatus}."
                . (($note !== null && trim($note) !== '') ? ' Note: ' . trim($note) : ''),
            ['status' => $fromStatus],
            [
                'status' => $toStatus,
                'payment_status' => $toStatus === 'Paid' ? 'Paid' : (string) ($run['payment_status'] ?? 'Pending'),
                'note' => ($note !== null && trim($note) !== '') ? trim($note) : null,
            ]
        );
        $pdo->commit();
        payroll_release_lock($pdo, $lockName);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        payroll_release_lock($pdo, $lockName);
        throw $error;
    }
}
