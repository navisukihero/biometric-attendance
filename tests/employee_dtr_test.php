<?php

declare(strict_types=1);

define('UCCHR_SESSION_NAME', 'UCCHR_DTR_TEST_SESSION');

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/employee_auth.php';
require __DIR__ . '/../includes/employee_dtr.php';

function dtr_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $validRange = employee_dtr_resolve_range('2026-09-01', '2026-09-15');
    dtr_assert($validRange['error'] === '', 'A valid DTR range must be accepted');
    dtr_assert(
        (int) $validRange['start']->diff($validRange['end'])->days + 1 === 15,
        'The example DTR range must contain 15 calendar rows'
    );
    dtr_assert(
        employee_dtr_resolve_range('2026-09-15', '2026-09-01')['error'] !== '',
        'A reversed DTR range must be rejected'
    );
    dtr_assert(
        employee_dtr_resolve_range('2025-01-01', '2026-01-02')['error'] !== '',
        'A DTR range longer than 366 days must be rejected'
    );

    $account = $pdo->query('SELECT id FROM employee_accounts ORDER BY id LIMIT 1')->fetch();
    dtr_assert(is_array($account), 'At least one employee account is required for the DTR test');
    $employee = employee_account_by_id($pdo, (int) $account['id']);
    dtr_assert((int) ($employee['employee_id'] ?? 0) > 0, 'DTR test employee must resolve');

    $dtr = employee_dtr_build($pdo, $employee, $validRange['start'], $validRange['end']);
    dtr_assert(count($dtr['rows']) === 15, 'DTR must include every calendar date in the selected range');
    dtr_assert($dtr['rows'][0]['date'] === '2026-09-01', 'DTR must begin on Range Start');
    dtr_assert($dtr['rows'][14]['date'] === '2026-09-15', 'DTR must end on Range End');

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM attendance
         WHERE employee_id=? AND scan_date BETWEEN ? AND ?
           AND (time_in IS NOT NULL OR time_out IS NOT NULL)'
    );
    $countStmt->execute([(int) $employee['employee_id'], '2026-09-01', '2026-09-15']);
    dtr_assert(
        (int) $dtr['totals']['attendance_days'] === (int) $countStmt->fetchColumn(),
        'DTR scan-day total must match this employee’s processed attendance rows'
    );

    $pageSource = file_get_contents(__DIR__ . '/../employee_pages/dtr.php');
    $printSource = file_get_contents(__DIR__ . '/../employee-dtr-print.php');
    $helperSource = file_get_contents(__DIR__ . '/../includes/employee_dtr.php');
    dtr_assert(is_string($pageSource) && is_string($printSource) && is_string($helperSource), 'DTR sources must be readable');
    dtr_assert(
        !str_contains($pageSource, '$_POST') && !str_contains($printSource, '$_POST'),
        'Employee DTR routes must remain read-only'
    );
    dtr_assert(
        !str_contains($pageSource, "\$_GET['employee_id']")
            && !str_contains($printSource, "\$_GET['employee_id']"),
        'Employee DTR routes must not accept an employee_id parameter'
    );
    dtr_assert(
        str_contains(preg_replace('/\s+/', ' ', $helperSource) ?: $helperSource, 'WHERE employee_id=? AND scan_date BETWEEN ? AND ?'),
        'DTR query must remain prepared and employee-scoped'
    );

    echo "employee_dtr_test: PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, "employee_dtr_test: FAIL - {$error->getMessage()}\n");
    exit(1);
}
