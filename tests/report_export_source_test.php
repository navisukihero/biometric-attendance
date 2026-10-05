<?php

declare(strict_types=1);

/**
 * Non-mutating source regression checks for administrator reports/exports.
 *
 * The test intentionally avoids loading config/database.php, so it can run on
 * a development machine before MariaDB or the additive migration is ready.
 */

function report_source_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function report_source_read(string $path, string $label): string
{
    $source = file_get_contents($path);
    report_source_assert(is_string($source), $label . ' source must be readable');
    return $source;
}

$root = realpath(__DIR__ . '/..');
report_source_assert(is_string($root), 'Project root must resolve');

try {
    $reports = report_source_read($root . '/pages/reports.php', 'Reports page');
    $attendancePage = report_source_read($root . '/pages/attendance.php', 'Attendance page');
    $export = report_source_read($root . '/export.php', 'Export endpoint');
    $payslipsPage = report_source_read($root . '/pages/payslips.php', 'Payslips page');
    $payslipPrint = report_source_read($root . '/payslip-print.php', 'Compact payslip print endpoint');
    $normalizedExport = preg_replace('/\s+/', ' ', $export) ?? $export;

    report_source_assert(
        str_contains($export, 'require_login($pdo);'),
        'Every administrator export must require an authenticated administrator'
    );

    preg_match('/\$allowedTypes\s*=\s*\[([^\]]*)\]/s', $export, $allowedMatch);
    report_source_assert(isset($allowedMatch[1]), 'Export endpoint must use an explicit type allowlist');
    preg_match_all('/[\'\"]([a-z_]+)[\'\"]/', (string) $allowedMatch[1], $allowedTypeMatches);
    $allowedTypes = $allowedTypeMatches[1] ?? [];
    sort($allowedTypes);
    $expectedAllowed = [
        'absence',
        'attendance',
        'holiday',
        'late',
        'overtime',
        'payroll',
        'payslip',
        'raw_attendance',
        'undertime',
    ];
    sort($expectedAllowed);
    report_source_assert(
        $allowedTypes === $expectedAllowed,
        'Export allowlist must contain every approved report type and no arbitrary route'
    );

    preg_match_all('/export\.php\?type=([a-z_]+)/', $reports, $reportLinkMatches);
    $linkedTypes = array_values(array_unique($reportLinkMatches[1] ?? []));
    sort($linkedTypes);
    $expectedLinked = [
        'absence',
        'attendance',
        'holiday',
        'late',
        'overtime',
        'payroll',
        'raw_attendance',
        'undertime',
    ];
    sort($expectedLinked);
    report_source_assert(
        $linkedTypes === $expectedLinked,
        'Reports page must link every administrator summary/export type'
    );
    preg_match_all('/href="export\.php\?type=([a-z_]+)[^"]*?&amp;format=word"/', $reports, $wordLinkMatches);
    $wordLinkedTypes = $wordLinkMatches[1] ?? [];
    sort($wordLinkedTypes);
    report_source_assert(
        $wordLinkedTypes === $expectedLinked,
        'Each report card must have its own Word export link'
    );
    foreach ($linkedTypes as $linkedType) {
        report_source_assert(
            in_array($linkedType, $allowedTypes, true),
            'Reports page must not link an export type rejected by export.php: ' . $linkedType
        );
    }

    // Date input is data, never SQL syntax. It must be strict ISO format,
    // ordered, and passed through placeholders to attendance/raw-log queries.
    report_source_assert(
        str_contains($export, "DateTimeImmutable::createFromFormat('!Y-m-d', \$value)")
            && str_contains($export, '$from > $to')
            && str_contains($export, 'http_response_code(422)'),
        'Date-range exports must reject invalid or reversed ISO date ranges'
    );
    report_source_assert(
        str_contains($normalizedExport, 'a.scan_date BETWEEN ? AND ?')
            && str_contains($normalizedExport, 'al.attendance_date BETWEEN ? AND ?'),
        'Processed and raw attendance date filters must use prepared placeholders'
    );

    foreach (
        [
            'a.break_minutes',
            'a.regular_minutes',
            'a.late_minutes',
            'a.undertime_minutes',
            'a.approved_overtime_minutes',
            'a.day_classification',
        ] as $attendanceField
    ) {
        report_source_assert(
            str_contains($export, $attendanceField),
            'Processed attendance export must include ' . $attendanceField
        );
    }
    report_source_assert(
        str_contains($normalizedExport, 'FROM attendance_logs al')
            && str_contains($export, 'al.event_key')
            && str_contains($export, 'al.command_uuid'),
        'Raw biometric export must use the append-only event log and its trace identifiers'
    );
    $rawStart = strpos($export, "if (\$type === 'raw_attendance')");
    $payrollStart = strpos($export, "if (\$type === 'payroll')", $rawStart === false ? 0 : $rawStart);
    report_source_assert($rawStart !== false && $payrollStart !== false, 'Raw attendance export block must be locatable');
    $rawBlock = substr($export, (int) $rawStart, (int) $payrollStart - (int) $rawStart);
    report_source_assert(
        str_contains($rawBlock, "\$where = '';") && !str_contains($rawBlock, 'pr.'),
        'Unfiltered raw attendance export must not reference a payroll alias that is absent from its query'
    );
    report_source_assert(
        !str_contains($attendancePage, 'Raw Biometric Transactions')
            && !str_contains($attendancePage, 'raw-attendance-panel'),
        'Attendance Log must hide the duplicate raw biometric transaction panel while backend exports remain available'
    );

    $requiredFilters = [
        'late' => 'a.late_minutes>0',
        'undertime' => 'a.undertime_minutes>0',
        'absence' => 'a.status IN("Absent","ABSENT")',
        'overtime' => 'a.approved_overtime_minutes>0',
        'holiday' => 'a.holiday_id IS NOT NULL',
    ];
    $compactExport = preg_replace('/\s+/', '', $export) ?? $export;
    foreach ($requiredFilters as $type => $filter) {
        report_source_assert(
            str_contains($compactExport, preg_replace('/\s+/', '', $filter) ?? $filter),
            ucfirst($type) . ' report must retain its dedicated server-side filter'
        );
    }
    report_source_assert(
        !str_contains($export, 'a.potential_overtime_minutes')
            && !str_contains(strtolower($attendancePage), 'potential ot')
            && !str_contains(strtolower($reports), 'potential ot')
            && str_contains($attendancePage, 'Approved OT')
            && str_contains($reports, 'Employee-requested, HR-approved minutes'),
        'Attendance and report UI/exports must expose Approved OT only, never Potential OT'
    );

    foreach (
        [
            'pi.employment_type',
            'pi.pay_type',
            'pi.basic_rate',
            'pi.regular_pay',
            'pi.late_deduction',
            'pi.undertime_deduction',
            'pi.half_day_deduction',
            'pi.absence_deduction',
            'pi.cash_advance_deduction',
            'pi.other_deductions',
            'pi.total_deductions',
            'pi.overtime_pay',
            'pi.holiday_pay',
            'pi.rest_day_pay',
            'pi.gross_pay',
            'pi.net_pay',
        ] as $payrollField
    ) {
        report_source_assert(
            str_contains($export, $payrollField),
            'Payroll register must include frozen calculation field ' . $payrollField
        );
    }
    report_source_assert(
        str_contains($payslipPrint, 'pr.status AS payroll_status')
            && str_contains($payslipPrint, "'basic_pay' => (float) (\$row['regular_pay'] ?? 0)")
            && str_contains($payslipPrint, "'overtime_pay' => (float) (\$row['overtime_pay'] ?? 0)")
            && str_contains($payslipPrint, "'total_deductions' => (float) (\$row['total_deductions'] ?? 0)")
            && str_contains($payslipPrint, "'net_pay' => (float) (\$row['net_pay'] ?? 0)")
            && str_contains($payslipPrint, 'array_chunk($payslips, 8)')
            && str_contains($payslipPrint, 'grid-template-columns: repeat(2')
            && str_contains($payslipPrint, 'grid-template-rows: repeat(4')
            && str_contains($payslipPrint, "'4.25in 2.75in'")
            && str_contains($payslipPrint, "'A4 portrait'")
            && preg_match('/@top-left\s*\{\s*content:\s*"";\s*\}/', $payslipPrint) === 1
            && preg_match('/@bottom-right\s*\{\s*content:\s*"";\s*\}/', $payslipPrint) === 1
            && str_contains($payslipPrint, 'printCleanPayslips()')
            && str_contains($payslipPrint, "document.title = '\\u200B';")
            && str_contains($payslipPrint, '$itemIds')
            && str_contains($payslipsPage, 'name="ids[]"')
            && str_contains($payslipsPage, '>Status</th>')
            && !str_contains($payslipsPage, 'Status / Slip'),
        'Printable payslips must support selected employees, 2 × 4 A4 bulk sheets, and 1/8 Letter individual slips'
    );
    report_source_assert(
        !str_contains($export, "\$type === 'deductions'")
            && !str_contains($export, "\$type === 'adjustments'")
            && !str_contains($reports, 'export.php?type=deductions'),
        'Retired deduction summary export and its legacy alias must stay unavailable'
    );

    report_source_assert(
        str_contains($export, "header('Content-Type: text/csv; charset=utf-8')")
            && str_contains($export, "header('X-Content-Type-Options: nosniff')")
            && str_contains($export, 'preg_match(\'/^[\\x00-\\x20]*[=+\\-@]/\'')
            && str_contains($export, 'fputcsv($output, $sanitizeRow($headings))')
            && str_contains($export, 'fputcsv($output, $sanitizeRow($row))'),
        'CSV reports must use explicit response headers, formula sanitization, and PHP CSV encoding'
    );
    report_source_assert(
        !str_contains($reports, '<form') && !str_contains($reports, '$_POST'),
        'Reports overview must remain view/export-only'
    );

    echo "report_export_source_test: PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, "report_export_source_test: FAIL - {$error->getMessage()}\n");
    exit(1);
}
