<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/attendance_schedule.php';
require_once __DIR__ . '/includes/report_word.php';
require_login($pdo);

/** @return array{0:?string,1:?string} */
function report_date_range(): array
{
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    if ($from === '' && $to === '') {
        return [null, null];
    }
    $valid = static function (string $value): bool {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    };
    if (!$valid($from) || !$valid($to) || $from > $to) {
        http_response_code(422);
        exit('Choose a valid From and To date.');
    }
    return [$from, $to];
}

function report_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() === 1;
}

/**
 * @param list<string> $headings
 * @param list<array<int, scalar|null>> $rows
 */
function report_csv(string $filename, array $headings, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to create the report.');
    }
    // UTF-8 BOM keeps employee names and the peso sign readable in Excel.
    fwrite($output, "\xEF\xBB\xBF");
    $sanitize = static function (mixed $cell): mixed {
        if (!is_string($cell) || $cell === '') {
            return $cell;
        }

        // Spreadsheet applications may execute a cell as a formula when an
        // imported value starts with =, +, -, or @. Leading control/space
        // characters can hide the same payload. Prefix untrusted text with an
        // apostrophe so names, positions, reasons, and identifiers stay data.
        if (
            preg_match('/^[\t\r\n]/', $cell) === 1
            || preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) === 1
        ) {
            return "'" . $cell;
        }

        return $cell;
    };
    $sanitizeRow = static fn(array $row): array => array_map($sanitize, $row);

    fputcsv($output, $sanitizeRow($headings));
    foreach ($rows as $row) {
        fputcsv($output, $sanitizeRow($row));
    }
    fclose($output);
    exit;
}

/** @param list<string> $headings @param list<array<int, scalar|null>> $rows */
function report_download(string $type, string $filename, array $headings, array $rows): never
{
    if (strtolower(trim((string) ($_GET['format'] ?? 'csv'))) === 'word') {
        $titles = [
            'attendance' => 'Processed Attendance Report',
            'raw_attendance' => 'Raw Biometric Events Report',
            'late' => 'Late Report',
            'undertime' => 'Undertime Report',
            'absence' => 'Absence Report',
            'overtime' => 'Overtime Report',
            'holiday' => 'Holiday Work Report',
            'payroll' => 'Payroll Register',
        ];
        [$from, $to] = report_date_range();
        $period = $from === null ? 'Period: All available records' : 'Period: ' . $from . ' to ' . $to;
        report_word(substr($filename, 0, -4) . '.docx', $titles[$type], $period, $headings, $rows);
    }
    report_csv($filename, $headings, $rows);
}

function report_number(mixed $value, int $decimals = 2): string
{
    return number_format((float) $value, $decimals, '.', '');
}

function report_schedule_periods(mixed $snapshot): string
{
    $periods = attendance_decode_schedule_periods($snapshot);
    if (!$periods) {
        return '';
    }
    return implode('; ', array_map(
        static fn(array $period): string => substr($period['period_start'], 0, 5)
            . '–' . substr($period['period_end'], 0, 5),
        $periods
    ));
}

$type = strtolower(trim((string) ($_GET['type'] ?? 'attendance')));
$allowedTypes = [
    'attendance',
    'raw_attendance',
    'late',
    'undertime',
    'absence',
    'overtime',
    'holiday',
    'payroll',
    'payslip',
];
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    exit('Unknown report type.');
}
$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'word'], true) || ($type === 'payslip' && $format === 'word')) {
    http_response_code(400);
    exit('Unknown report format.');
}
// Preserve old bookmarked payslip URLs while sending them directly to the
// compact 1/8-page print layout. The former full-page preview is retired.
if ($type === 'payslip') {
    $payslipId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
    if ($payslipId < 1) {
        http_response_code(422);
        exit('Choose a valid payslip.');
    }
    header('Location: payslip-print.php?id=' . $payslipId . '&autoprint=1', true, 302);
    exit;
}

$integratedAttendance = report_has_column($pdo, 'attendance', 'regular_minutes');
$integratedPayroll = report_has_column($pdo, 'payroll_items', 'regular_pay')
    && report_has_column($pdo, 'payroll_items', 'monthly_basic_salary')
    && report_has_column($pdo, 'payroll_items', 'half_day_deduction')
    && report_has_column($pdo, 'payroll_items', 'absence_deduction');

if (in_array($type, ['attendance', 'late', 'undertime', 'absence', 'overtime', 'holiday'], true)) {
    [$from, $to] = report_date_range();
    $where = [];
    $parameters = [];
    if ($from !== null && $to !== null) {
        $where[] = 'a.scan_date BETWEEN ? AND ?';
        $parameters[] = $from;
        $parameters[] = $to;
    }
    if ($type === 'late') {
        $where[] = 'a.late_minutes>0';
    } elseif ($type === 'undertime') {
        $where[] = $integratedAttendance ? 'a.undertime_minutes>0' : '1=0';
    } elseif ($type === 'absence') {
        $where[] = 'a.status IN ("Absent","ABSENT")';
    } elseif ($type === 'overtime') {
        $where[] = $integratedAttendance ? 'a.approved_overtime_minutes>0' : '1=0';
    } elseif ($type === 'holiday') {
        $where[] = $integratedAttendance
            ? '(a.holiday_id IS NOT NULL OR a.day_classification LIKE "%Holiday%")'
            : '1=0';
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    if ($integratedAttendance) {
        $stmt = $pdo->prepare(
            'SELECT e.employee_no, CONCAT_WS(" ",e.first_name,NULLIF(e.middle_name,""),e.last_name) employee,
                    COALESCE(d.name,"—") department, a.scan_date,
                    a.expected_time_in, a.expected_time_out, a.schedule_periods_snapshot, a.time_in, a.time_out,
                    a.break_minutes, a.worked_minutes, a.regular_minutes, a.late_minutes,
                    a.undertime_minutes, a.approved_overtime_minutes,
                    a.day_classification, a.status, a.source
             FROM attendance a
             JOIN employees e ON e.id=a.employee_id
             LEFT JOIN departments d ON d.id=e.department_id'
                . $whereSql .
                ' ORDER BY a.scan_date DESC, e.last_name, e.first_name'
        );
        $stmt->execute($parameters);
        $rows = array_map(
            static fn(array $row): array => [
                $row['employee_no'],
                $row['employee'],
                $row['department'],
                $row['scan_date'],
                $row['expected_time_in'],
                $row['expected_time_out'],
                report_schedule_periods($row['schedule_periods_snapshot']),
                $row['time_in'],
                $row['time_out'],
                (int) $row['break_minutes'],
                (int) $row['worked_minutes'],
                (int) $row['regular_minutes'],
                (int) $row['late_minutes'],
                (int) $row['undertime_minutes'],
                (int) $row['approved_overtime_minutes'],
                $row['day_classification'],
                $row['status'],
                $row['source'],
            ],
            $stmt->fetchAll()
        );
        report_download($type,
            $type . '-report-' . date('Y-m-d') . '.csv',
            [
                'Employee ID',
                'Employee',
                'Department',
                'Date',
                'Expected In',
                'Expected Out',
                'Expected Periods',
                'Actual In',
                'Actual Out',
                'Break Minutes',
                'Worked Minutes',
                'Regular Minutes',
                'Late Minutes',
                'Undertime Minutes',
                'Approved OT Minutes',
                'Day Classification',
                'Attendance Status',
                'Source'
            ],
            $rows
        );
    }

    $stmt = $pdo->prepare(
        'SELECT e.employee_no, CONCAT_WS(" ",e.first_name,NULLIF(e.middle_name,""),e.last_name) employee,
                COALESCE(d.name,"—") department, a.scan_date, a.expected_time_in,
                a.expected_time_out, a.time_in, a.time_out, a.worked_minutes,
                a.late_minutes, 0 AS approved_overtime_minutes, a.status, a.source
         FROM attendance a
         JOIN employees e ON e.id=a.employee_id
         LEFT JOIN departments d ON d.id=e.department_id'
            . $whereSql .
            ' ORDER BY a.scan_date DESC, e.last_name, e.first_name'
    );
    $stmt->execute($parameters);
    $rows = array_map(static fn(array $row): array => array_values($row), $stmt->fetchAll());
    report_download($type,
        $type . '-report-' . date('Y-m-d') . '.csv',
        [
            'Employee ID',
            'Employee',
            'Department',
            'Date',
            'Expected In',
            'Expected Out',
            'Actual In',
            'Actual Out',
            'Worked Minutes',
            'Late Minutes',
            'Approved OT Minutes',
            'Status',
            'Source'
        ],
        $rows
    );
}

if ($type === 'raw_attendance') {
    if (!report_has_column($pdo, 'attendance_logs', 'event_key')) {
        http_response_code(409);
        exit('Raw attendance reporting becomes available after the integrated migration is installed.');
    }
    [$from, $to] = report_date_range();
    $where = '';
    $parameters = [];
    if ($from !== null && $to !== null) {
        $where = ' WHERE al.attendance_date BETWEEN ? AND ?';
        $parameters = [$from, $to];
    }
    $stmt = $pdo->prepare(
        'SELECT e.employee_no,
                CONCAT_WS(" ",e.first_name,NULLIF(e.middle_name,""),e.last_name) employee,
                al.attendance_date, al.action, al.scanned_at, al.fingerprint_id,
                al.device_id, al.command_uuid, al.source, al.event_key
         FROM attendance_logs al
         JOIN employees e ON e.id=al.employee_id'
            . $where .
            ' ORDER BY al.scanned_at DESC, al.id DESC'
    );
    $stmt->execute($parameters);
    $rows = array_map(static fn(array $row): array => array_values($row), $stmt->fetchAll());
    report_download($type,
        'raw-biometric-events-' . date('Y-m-d') . '.csv',
        [
            'Employee ID',
            'Employee',
            'Attendance Date',
            'Action',
            'Scanned At',
            'Fingerprint Slot',
            'Device ID',
            'Command UUID',
            'Source',
            'Event Key'
        ],
        $rows
    );
}

if ($type === 'payroll') {
    [$from, $to] = report_date_range();
    $where = '';
    $parameters = [];
    if ($from !== null && $to !== null) {
        $where .= ' AND pr.period_start>=? AND pr.period_end<=?';
        $parameters = [$from, $to];
    }
    if ($integratedPayroll) {
        $stmt = $pdo->prepare(
            'SELECT pr.id run_id, pr.period_start, pr.period_end, pr.status,
                    e.employee_no, CONCAT_WS(" ",e.first_name,NULLIF(e.middle_name,""),e.last_name) employee,
                    pi.employment_type, pi.pay_type, pi.basic_rate, pi.monthly_basic_salary, pi.regular_pay, pi.regular_minutes,
                    pi.regular_hours, pi.hourly_equivalent_rate,
                    pi.late_minutes, pi.late_deduction, pi.undertime_minutes,
                    pi.undertime_deduction, pi.half_day_deduction, pi.absence_deduction, pi.cash_advance_deduction,
                    pi.other_deductions,
                    pi.approved_overtime_minutes, pi.overtime_hours,
                    pi.ot_rate, pi.overtime_pay, pi.holiday_hours, pi.holiday_pay,
                    pi.rest_day_hours, pi.rest_day_pay, pi.other_earnings, pi.gross_pay,
                    pi.total_deductions, pi.net_pay
             FROM payroll_items pi
             JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
             JOIN employees e ON e.id=pi.employee_id'
                . $where .
                ' ORDER BY pr.id DESC, e.last_name, e.first_name'
        );
        $stmt->execute($parameters);
        $rawRows = $stmt->fetchAll();
        $rows = [];
        foreach ($rawRows as $row) {
            foreach (
                [
                    'basic_rate',
                    'monthly_basic_salary',
                    'regular_pay',
                    'regular_hours',
                    'hourly_equivalent_rate',
                    'late_deduction',
                    'undertime_deduction',
                    'half_day_deduction',
                    'absence_deduction',
                    'cash_advance_deduction',
                    'other_deductions',
                    'overtime_hours',
                    'ot_rate',
                    'overtime_pay',
                    'holiday_hours',
                    'holiday_pay',
                    'rest_day_hours',
                    'rest_day_pay',
                    'other_earnings',
                    'gross_pay',
                    'total_deductions',
                    'net_pay'
                ] as $numericColumn
            ) {
                $row[$numericColumn] = report_number($row[$numericColumn], 2);
            }
            $rows[] = array_values($row);
        }
        report_download($type,
            'payroll-register-' . date('Y-m-d') . '.csv',
            [
                'Run',
                'Period Start',
                'Period End',
                'Status',
                'Employee ID',
                'Employee',
                'Employment Type',
                'Pay Type',
                'Approved Rate',
                'Calculated Basic Salary',
                'Regular Pay Snapshot',
                'Regular Minutes',
                'Regular Hours',
                'Hourly Equivalent',
                'Late Minutes',
                'Late Deduction',
                'Undertime Minutes',
                'Undertime Deduction',
                'Half-Day Deduction',
                'Absence / Unpaid-Day Deduction',
                'Cash Advance',
                'Other Deductions',
                'Approved OT Minutes',
                'Approved OT Hours',
                'OT Rate',
                'OT Pay',
                'Holiday Hours',
                'Holiday Pay',
                'Rest-Day Hours',
                'Rest-Day Pay',
                'Other Earnings',
                'Gross Pay',
                'Total Deductions',
                'Net Pay'
            ],
            $rows
        );
    }

    $stmt = $pdo->prepare(
        'SELECT pr.period_start, pr.period_end, e.employee_no,
                CONCAT_WS(" ",e.first_name,NULLIF(e.middle_name,""),e.last_name) employee,
                pi.days_worked, pi.gross_pay, pi.net_pay
         FROM payroll_items pi
         JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
         JOIN employees e ON e.id=pi.employee_id'
            . $where .
            ' ORDER BY pr.id DESC, e.last_name, e.first_name'
    );
    $stmt->execute($parameters);
    $rows = array_map(static fn(array $row): array => array_values($row), $stmt->fetchAll());
    report_download($type,
        'payroll-register-' . date('Y-m-d') . '.csv',
        ['Period Start', 'Period End', 'Employee ID', 'Employee', 'Days', 'Gross', 'Net Pay'],
        $rows
    );
}

