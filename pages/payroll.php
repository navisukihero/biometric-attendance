<?php

declare(strict_types=1);

$payrollRunColumns = function_exists('attendance_table_columns') ? attendance_table_columns($pdo, 'payroll_runs') : [];
$payrollItemColumns = function_exists('attendance_table_columns') ? attendance_table_columns($pdo, 'payroll_items') : [];
$deductionEntryColumns = function_exists('attendance_table_columns') ? attendance_table_columns($pdo, 'employee_deduction_entries') : [];
$integratedPayrollReady = isset(
    $payrollRunColumns['scope_employee_id'],
    $payrollRunColumns['payment_method'],
    $payrollRunColumns['payment_status'],
    $payrollRunColumns['policy_snapshot'],
    $payrollRunColumns['approved_at'],
    $payrollItemColumns['monthly_basic_salary'],
    $payrollItemColumns['regular_pay'],
    $payrollItemColumns['undertime_deduction'],
    $payrollItemColumns['half_day_deduction'],
    $payrollItemColumns['absence_deduction'],
    $payrollItemColumns['cash_advance_deduction'],
    $payrollItemColumns['other_deductions'],
    $payrollItemColumns['total_deductions'],
    $deductionEntryColumns['deduction_date'],
    $deductionEntryColumns['deduction_type'],
    $deductionEntryColumns['amount'],
    $deductionEntryColumns['description'],
    $deductionEntryColumns['status'],
    $deductionEntryColumns['created_by']
);

$activeEmployees = [];
$incompleteCompensation = 0;
if ($integratedPayrollReady) {
    $activeEmployees = $pdo->query(
        'SELECT e.id, e.employee_no, e.first_name, e.middle_name, e.last_name,
                e.position, e.employment_type, e.pay_type, e.basic_rate,
                COALESCE(d.name,"Unassigned") AS department_name
         FROM employees e
         LEFT JOIN departments d ON d.id=e.department_id
         WHERE e.status="Active"
         ORDER BY e.last_name, e.first_name, e.employee_no'
    )->fetchAll();
    foreach ($activeEmployees as $employeeRow) {
        if (
            !in_array((string) ($employeeRow['employment_type'] ?? ''), ['Full-Time', 'Part-Time'], true)
            || !in_array((string) ($employeeRow['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true)
            || (float) ($employeeRow['basic_rate'] ?? 0) <= 0
        ) {
            $incompleteCompensation++;
        }
    }
}
$payrollGenerationReady = false;

$runs = [];
$selectedRun = null;
$selectedItems = [];
$selectedRunEverReviewed = false;
$selectedRunHasLegacyOther = false;
try {
    if ($integratedPayrollReady) {
        $runs = $pdo->query(
            'SELECT pr.*, u.full_name,
                    se.employee_no AS scope_employee_no,
                    CONCAT_WS(" ",se.first_name,se.last_name) AS scope_employee_name,
                    COUNT(pi.id) employee_count,
                    COALESCE(SUM(pi.days_worked),0) total_days,
                    COALESCE(SUM(pi.regular_hours),0) regular_hours,
                    COALESCE(SUM(pi.overtime_hours),0) overtime_hours,
                    COALESCE(SUM(pi.overtime_pay),0) overtime_pay,
                    COALESCE(SUM(pi.gross_pay),0) gross,
                    COALESCE(SUM(pi.late_deduction),0) late_deduction,
                    COALESCE(SUM(pi.undertime_deduction),0) undertime_deduction,
                    COALESCE(SUM(pi.half_day_deduction),0) half_day_deduction,
                    COALESCE(SUM(pi.absence_deduction),0) absence_deduction,
                    COALESCE(SUM(pi.cash_advance_deduction),0) cash_advance_deduction,
                    COALESCE(SUM(pi.other_deductions),0) other_deductions,
                    COALESCE(SUM(pi.total_deductions),0) deductions,
                    COALESCE(SUM(pi.net_pay),0) net
             FROM payroll_runs pr
             LEFT JOIN users u ON u.id=pr.processed_by
             LEFT JOIN employees se ON se.id=pr.scope_employee_id
             LEFT JOIN payroll_items pi ON pi.payroll_run_id=pr.id
             GROUP BY pr.id
             ORDER BY pr.id DESC'
        )->fetchAll();
    }

    $selectedRunId = max(0, (int) ($_GET['run_id'] ?? 0));
    foreach ($runs as $run) {
        if ((int) $run['id'] === $selectedRunId) {
            $selectedRun = $run;
            break;
        }
    }
    if ($integratedPayrollReady && $selectedRun) {
        $reviewHistoryStmt = $pdo->prepare(
            'SELECT 1 FROM activity_logs
             WHERE module="Payroll" AND record_id=?
               AND action IN ("Transitioned payroll status","Returned payroll to draft")
             LIMIT 1'
        );
        $reviewHistoryStmt->execute([(string) $selectedRun['id']]);
        $selectedRunEverReviewed = (bool) $reviewHistoryStmt->fetchColumn();
        $itemStmt = $pdo->prepare(
            'SELECT pi.*, e.employee_no, e.first_name, e.middle_name, e.last_name,
                    e.position, COALESCE(d.name,"Unassigned") department_name
             FROM payroll_items pi
             JOIN employees e ON e.id=pi.employee_id
             LEFT JOIN departments d ON d.id=e.department_id
             WHERE pi.payroll_run_id=?
             ORDER BY e.last_name, e.first_name, e.employee_no'
        );
        $itemStmt->execute([(int) $selectedRun['id']]);
        $selectedItems = $itemStmt->fetchAll();
        $selectedRunHasLegacyOther = array_reduce(
            $selectedItems,
            static fn(bool $found, array $item): bool => $found || (float) ($item['other_deductions'] ?? 0) > 0,
            false
        );
    }
} catch (Throwable) {
    $integratedPayrollReady = false;
    $payrollGenerationReady = false;
}

$today = new DateTimeImmutable('today');
$todayValue = $today->format('Y-m-d');
// Default to the last fully elapsed day. Administrators can still explicitly
// choose today after all assigned sessions have closed, but an open current
// workday should not be the default source of a blocked payroll draft.
$defaultCompletedEnd = $today->modify('-1 day');
$defaultStart = $defaultCompletedEnd->modify('first day of this month')->format('Y-m-d');
$defaultEnd = $defaultCompletedEnd->format('Y-m-d');
$periodStart = trim((string) ($_GET['period_start'] ?? $defaultStart));
$periodEnd = trim((string) ($_GET['period_end'] ?? $defaultEnd));
$dateValueIsValid = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
};
$previewDatesValid = $dateValueIsValid($periodStart)
    && $dateValueIsValid($periodEnd)
    && $periodStart <= $periodEnd
    && $periodEnd <= $todayValue;

$view = (string) ($_GET['view'] ?? 'dashboard');
$showGenerator = $view === 'generate';
$employmentFilter = (string) ($_GET['employment_filter'] ?? 'All');
if (!in_array($employmentFilter, ['All', 'Full-Time', 'Part-Time'], true)) {
    $employmentFilter = 'All';
}
$filteredEmployees = array_values(array_filter(
    $activeEmployees,
    static fn(array $row): bool => $employmentFilter === 'All'
        || (string) $row['employment_type'] === $employmentFilter
));
$selectedEmployeeId = max(0, (int) ($_GET['employee_id'] ?? ($activeEmployees[0]['id'] ?? 0)));
if ($selectedEmployeeId === 0 && $employmentFilter !== 'All' && $filteredEmployees) {
    $selectedEmployeeId = (int) $filteredEmployees[0]['id'];
}
$batchSelected = $selectedEmployeeId === 0;
$selectedEmployee = null;
foreach ($filteredEmployees as $employeeRow) {
    if ((int) $employeeRow['id'] === $selectedEmployeeId) {
        $selectedEmployee = $employeeRow;
        break;
    }
}
if (!$batchSelected && $selectedEmployee === null && $filteredEmployees) {
    $selectedEmployee = $filteredEmployees[0];
    $selectedEmployeeId = (int) $selectedEmployee['id'];
}
$batchPreview = null;
$batchPreviewError = '';
$selectedCompensationReady = $selectedEmployee !== null;
$payrollGenerationReady = false;
$validationRequested = (string) ($_GET['validate'] ?? '') === '1';
$cashAdvancePreviewRaw = trim((string) ($_GET['cash_advance_amount'] ?? '0.00'));
$validatedCalculation = null;

$preview = [
    'attendance_days' => 0,
    'regular_minutes' => 0,
    'approved_overtime_minutes' => 0,
    'existing_cash_advance' => 0.0,
    'late_minutes' => 0,
    'potential_overtime_minutes' => 0,
    'expected_period_salary' => 0.0,
    'calculated_basic_salary' => 0.0,
    'salary_formula' => 'Validate the selected period to calculate pay',
    'scheduled_days' => 0,
    'scheduled_minutes' => 0,
    'ot_rate' => 0.0,
    'schedule_error' => '',
    'suggested_start' => '',
];
if ($showGenerator && $validationRequested && $selectedEmployee && $previewDatesValid) {
    try {
        if (!$selectedCompensationReady) {
            throw new RuntimeException('Complete this employee’s Employment Type, Pay Type, and Approved Rate in Employee Records.');
        }
        if ($cashAdvancePreviewRaw === '' || !is_numeric($cashAdvancePreviewRaw)
            || !is_finite((float) $cashAdvancePreviewRaw)
            || (float) $cashAdvancePreviewRaw < 0
            || (float) $cashAdvancePreviewRaw > 999999999.99) {
            throw new InvalidArgumentException('Cash Advance must be a valid amount from 0.00 to 999,999,999.99.');
        }
        $validatedCalculation = payroll_preview_employee(
            $pdo,
            $periodStart,
            $periodEnd,
            $selectedEmployeeId,
            round((float) $cashAdvancePreviewRaw, 2, PHP_ROUND_HALF_UP)
        );
        $calculationSnapshot = json_decode(
            (string) $validatedCalculation['calculation_snapshot'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $cashAdvanceStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount),0)
             FROM employee_deduction_entries
             WHERE employee_id=? AND deduction_date BETWEEN ? AND ?
               AND deduction_type="Cash Advance" AND status="Active"'
        );
        $cashAdvanceStmt->execute([$selectedEmployeeId, $periodStart, $periodEnd]);
        $preview['existing_cash_advance'] = (float) $cashAdvanceStmt->fetchColumn();
        $preview['attendance_days'] = count((array) ($calculationSnapshot['attendance'] ?? []));
        $preview['regular_minutes'] = (int) $validatedCalculation['regular_minutes'];
        $preview['approved_overtime_minutes'] = (int) $validatedCalculation['approved_overtime_minutes'];
        $preview['late_minutes'] = (int) $validatedCalculation['late_minutes'];
        $preview['calculated_basic_salary'] = (float) $validatedCalculation['monthly_basic_salary'];
        $preview['scheduled_days'] = (int) $validatedCalculation['scheduled_days'];
        $preview['scheduled_minutes'] = (int) $validatedCalculation['scheduled_minutes'];
        $preview['ot_rate'] = (float) $validatedCalculation['ot_rate'];
        $preview['salary_formula'] = ucchr_payroll_basic_salary_formula($validatedCalculation);
        // The estimate and Basic Salary come from the same validated server
        // calculation; never present two competing amounts for one period.
        $preview['scheduled_pay_estimate'] = (float) $validatedCalculation['monthly_basic_salary'];
        $preview['effective_rates'] = [];
        foreach ((array) ($calculationSnapshot['attendance'] ?? []) as $calculationDay) {
            if (($calculationDay['schedule_type'] ?? '') !== 'Work') {
                continue;
            }
            $rate = (float) ($calculationDay['compensation']['basic_rate'] ?? 0);
            $preview['effective_rates'][number_format($rate, 4, '.', '')] = $rate;
        }
        $preview['rate_effective_date'] = (string) ($calculationSnapshot['representative_compensation']['effective_from'] ?? '');
        $preview['rate_effective_to'] = (string) ($calculationSnapshot['representative_compensation']['effective_to'] ?? '');
        $payrollGenerationReady = true;
    } catch (Throwable $error) {
        $preview['ot_rate'] = 0.0;
        $preview['schedule_error'] = $error->getMessage();
        $payrollGenerationReady = false;
        if (str_contains($error->getMessage(), 'Payroll period overlaps run #')) {
            $coveredEndStmt = $pdo->prepare(
                'SELECT MAX(pr.period_end)
                 FROM payroll_runs pr
                 JOIN payroll_items pi ON pi.payroll_run_id=pr.id
                 WHERE pi.employee_id=? AND pr.period_start<=? AND pr.period_end>=?'
            );
            $coveredEndStmt->execute([$selectedEmployeeId, $periodEnd, $periodStart]);
            $coveredEnd = $coveredEndStmt->fetchColumn();
            if ($coveredEnd) {
                $candidate = (new DateTimeImmutable((string) $coveredEnd))->modify('+1 day')->format('Y-m-d');
                if ($candidate <= $periodEnd) {
                    $preview['suggested_start'] = $candidate;
                }
            }
        }
    }
}
if ($showGenerator && $validationRequested && $batchSelected && $previewDatesValid) {
    try {
        if ($cashAdvancePreviewRaw !== '' && (!is_numeric($cashAdvancePreviewRaw) || (float) $cashAdvancePreviewRaw !== 0.0)) {
            throw new InvalidArgumentException('For a batch run, record Cash Advances for each employee before running payroll. The Additional Cash Advance field must be 0.00.');
        }
        $batchPreview = payroll_preview_batch($pdo, $periodStart, $periodEnd);
        $payrollGenerationReady = $batchPreview['ready'] > 0 && $batchPreview['needs_attention'] === 0;
    } catch (Throwable $error) {
        $batchPreviewError = $error->getMessage();
    }
}

$runTotals = ['net' => 0.0, 'paid' => 0.0, 'pending' => 0.0, 'items' => 0];
foreach ($runs as $run) {
    $net = (float) $run['net'];
    $runTotals['net'] += $net;
    $runTotals['items'] += (int) $run['employee_count'];
    $isPaid = (string) ($run['payment_status'] ?? '') === 'Paid'
        || in_array((string) $run['status'], ['Paid', 'Released'], true);
    $runTotals[$isPaid ? 'paid' : 'pending'] += $net;
}

$nextStatus = [
    'Draft' => 'For Review',
    'For Review' => 'Approved',
    'Approved' => 'Finalized',
    'Finalized' => 'Paid',
];
$transitionLabel = [
    'For Review' => 'Submit for Review',
    'Approved' => 'Approve Payroll',
    'Finalized' => 'Finalize Payroll',
    'Paid' => 'Mark as Paid',
];
$statusClass = static fn(string $status): string => match ($status) {
    'Paid', 'Released' => 'badge-green',
    'Finalized', 'Approved' => 'badge-blue',
    'For Review' => 'badge-amber',
    default => 'badge-gray',
};
$periodLabel = static fn(array $run): string => date('M j', strtotime((string) $run['period_start']))
    . '–' . date('j, Y', strtotime((string) $run['period_end']));
$minutesLabel = static function (int $minutes): string {
    $minutes = max(0, $minutes);
    return intdiv($minutes, 60) . 'h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm';
};
?>

<section class="payroll-hub">
    <article class="panel payroll-hero">
        <div class="payroll-hero-copy">
            <span class="payroll-eyebrow"><?= ui_icon('payroll', 'section-icon') ?>Payroll workspace</span>
            <h2>Run Payroll</h2>
        </div>
        <div class="payroll-hero-actions">
            <?php if ($showGenerator): ?>
                <a class="btn btn-outline" href="app.php?page=payroll"><?= ui_icon('arrow-left', 'button-icon') ?>Back to Run Payroll</a>
            <?php else: ?>
                <a class="btn btn-primary btn-large" href="app.php?page=payroll&amp;view=generate"><?= ui_icon('payroll', 'button-icon') ?>Run Payroll</a>
            <?php endif; ?>
            <a class="btn btn-outline" href="app.php?page=payslips"><?= ui_icon('payslip', 'button-icon') ?>Payslips</a>
        </div>
    </article>

    <?php if (!$integratedPayrollReady): ?>
        <div class="note-box payroll-system-note"><strong>Database update required:</strong> apply the ordered migrations in <code>DEPLOYMENT.md</code>, including <code>database/attendance_status_payroll_update.sql</code>, to enable immutable attendance and payroll snapshots.</div>
    <?php elseif ($incompleteCompensation > 0): ?>
        <div class="note-box payroll-system-note"><strong>HR confirmation required:</strong> <?= $incompleteCompensation ?> active employee<?= $incompleteCompensation === 1 ? '' : 's' ?> still <?= $incompleteCompensation === 1 ? 'needs' : 'need' ?> a valid Employment Type, Pay Type, and Approved Rate.</div>
    <?php endif; ?>


    <?php if (!$showGenerator): ?>
        <div class="payroll-kpi-grid">
            <article class="payroll-kpi payroll-kpi-primary"><span class="payroll-kpi-icon"><?= ui_icon('payroll') ?></span><small>Total net payroll</small><strong><?= e(money($runTotals['net'])) ?></strong>
                <p><?= (int) $runTotals['items'] ?> employee payroll record<?= (int) $runTotals['items'] === 1 ? '' : 's' ?></p>
            </article>
            <article class="payroll-kpi payroll-kpi-paid"><span class="payroll-kpi-icon"><?= ui_icon('check-circle') ?></span><small>Paid payroll</small><strong><?= e(money($runTotals['paid'])) ?></strong>
                <p>Completed disbursements</p>
            </article>
            <article class="payroll-kpi payroll-kpi-pending"><span class="payroll-kpi-icon"><?= ui_icon('overtime') ?></span><small>Pending payroll</small><strong><?= e(money($runTotals['pending'])) ?></strong>
                <p>Awaiting review or payment</p>
            </article>
            <article class="payroll-kpi payroll-kpi-runs"><span class="payroll-kpi-icon"><?= ui_icon('audit') ?></span><small>Payroll runs</small><strong><?= count($runs) ?></strong>
                <p>Complete calculation history</p>
            </article>
        </div>

        <article class="panel standard-panel payroll-dashboard-table-panel">
            <div class="panel-title-row">
                <div>
                    <h2><?= ui_icon('activity', 'heading-icon') ?>Recent Payroll</h2>
                    <p class="muted">Payment and approval progress remain synchronized with Payslips and the Employee Portal.</p>
                </div>
                <div class="button-row"><a class="btn btn-outline" href="app.php?page=payroll_settings"><?= ui_icon('payroll-settings', 'button-icon') ?>Payroll Settings</a></div>
            </div>
            <div class="table-scroll">
                <table class="data-table payroll-dashboard-table">
                    <thead>
                        <tr>
                            <th>Employee / Run</th>
                            <th>Pay Period</th>
                            <th>Gross Pay</th>
                            <th>Net Pay</th>
                            <th>Payment</th>
                            <th>Approval</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $run): ?>
                            <?php
                            $runScope = trim((string) ($run['scope_employee_name'] ?? ''));
                            $employeeLabel = $runScope !== '' ? $runScope : ((int) $run['employee_count'] . ' employees');
                            $employeeSubLabel = $runScope !== ''
                                ? (string) ($run['scope_employee_no'] ?? '')
                                : 'Batch payroll run';
                            ?>
                            <tr>
                                <td><strong><?= e($employeeLabel) ?></strong><small class="cell-subtitle"><?= e($employeeSubLabel) ?> · Run #<?= (int) $run['id'] ?></small></td>
                                <td><?= e($periodLabel($run)) ?><small class="cell-subtitle"><?= e(number_format((float) $run['regular_hours'], 2)) ?> regular h · <?= e(number_format((float) $run['overtime_hours'], 2)) ?> OT h</small></td>
                                <td><?= e(money($run['gross'])) ?></td>
                                <td class="text-green"><strong><?= e(money($run['net'])) ?></strong></td>
                                <td><span class="badge <?= (string) $run['payment_status'] === 'Paid' ? 'badge-green' : 'badge-amber' ?>"><?= e((string) $run['payment_status']) ?></span><small class="cell-subtitle"><?= e((string) $run['payment_method']) ?></small></td>
                                <td><span class="badge <?= e($statusClass((string) $run['status'])) ?>"><?= e((string) $run['status']) ?></span></td>
                                <td><a class="btn btn-mini btn-outline" href="app.php?page=payroll&amp;run_id=<?= (int) $run['id'] ?>#payroll-review"><?= ui_icon('view', 'button-icon') ?>Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$runs): ?><tr>
                                <td colspan="7" class="empty-state">No payroll has been generated. Select Run Payroll to create the first employee payroll.</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php else: ?>
        <div class="payroll-generator-grid">
            <article class="panel standard-panel payroll-generator-panel">
                <div class="panel-title-row">
                    <div>
                        <h2><?= ui_icon('employees', 'heading-icon') ?>Generate Employee Payroll</h2>
                        <p class="muted">Choose the employee and completed payroll period. Existing data loads automatically.</p>
                    </div>
                    <span class="badge badge-blue">Step 1 · Employee &amp; period</span>
                </div>

                <form method="get" action="app.php" class="payroll-preview-form" data-payroll-preview-form>
                    <input type="hidden" name="page" value="payroll">
                    <input type="hidden" name="view" value="generate">
                    <input type="hidden" name="validate" value="1">
                    <label>Employee Type
                        <select name="employment_filter">
                            <?php foreach (['All', 'Full-Time', 'Part-Time'] as $typeOption): ?>
                                <option value="<?= e($typeOption) ?>" <?= $employmentFilter === $typeOption ? 'selected' : '' ?>><?= e($typeOption === 'All' ? 'All Employees' : $typeOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Employee / Run Scope
                        <select name="employee_id" data-payroll-employee required>
                            <?php if ($employmentFilter === 'All'): ?><option value="0" <?= $batchSelected ? 'selected' : '' ?>>All active employees · mixed payroll</option><?php endif; ?>
                            <?php if (!$activeEmployees): ?><option value="">No active employees available</option><?php endif; ?>
                            <?php foreach ($filteredEmployees as $employeeRow): ?>
                                <option value="<?= (int) $employeeRow['id'] ?>" <?= (int) $employeeRow['id'] === $selectedEmployeeId ? 'selected' : '' ?>><?= e($employeeRow['employee_no'] . ' · ' . trim($employeeRow['first_name'] . ' ' . $employeeRow['last_name']) . ' — ' . $employeeRow['position']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Period start<input type="date" name="period_start" max="<?= e($todayValue) ?>" value="<?= e($periodStart) ?>" required></label>
                    <label>Period end<input type="date" name="period_end" max="<?= e($todayValue) ?>" value="<?= e($periodEnd) ?>" required></label>
                    <label class="span-2">Additional Cash Advance (₱)<input type="number" name="cash_advance_amount" min="0" max="999999999.99" step="0.01" inputmode="decimal" value="<?= e($cashAdvancePreviewRaw) ?>" required><small>Enter 0.00 for a mixed-employee run. Existing approved deductions are included automatically.</small></label>
                    <button class="btn btn-outline span-2" type="submit"><?= ui_icon('refresh', 'button-icon') ?>Validate Payroll</button>
                </form>

                <?php if (!$previewDatesValid): ?>
                    <div class="note-box">Choose a valid completed date range. Payroll cannot include a future date.</div>
                <?php elseif ($selectedEmployee): ?>
                    <div class="payroll-employee-banner">
                        <span class="avatar avatar-large"><?= e(strtoupper(substr((string) $selectedEmployee['first_name'], 0, 1) . substr((string) $selectedEmployee['last_name'], 0, 1))) ?></span>
                        <div><strong><?= e(trim($selectedEmployee['first_name'] . ' ' . $selectedEmployee['middle_name'] . ' ' . $selectedEmployee['last_name'])) ?></strong><small><?= e($selectedEmployee['employee_no']) ?> · <?= e($selectedEmployee['department_name']) ?> · <?= e($selectedEmployee['position']) ?></small></div>
                    </div>

                    <?php if (!$selectedCompensationReady): ?>
                        <div class="note-box payroll-system-note"><strong>Employee pay setup required:</strong> set this employee's Employment Type, Pay Type, and Approved Rate in Employee Records before generating payroll.</div>
                    <?php endif; ?>

                    <div class="payroll-auto-grid">
                        <?php
                        $selectedPayType = (string) ($validatedCalculation['pay_type'] ?? $selectedEmployee['pay_type']);
                        $selectedRateUnit = $selectedPayType === 'Hourly' ? 'hour' : ($selectedPayType === 'Monthly' ? 'month' : 'day');
                        $currentPayType = (string) $selectedEmployee['pay_type'];
                        $currentRateUnit = $currentPayType === 'Hourly' ? 'hour' : ($currentPayType === 'Monthly' ? 'month' : 'day');
                        $effectiveRates = (array) ($preview['effective_rates'] ?? []);
                        ?>
                        <label>Current Employee Records Rate<input value="<?= e(money($selectedEmployee['basic_rate'])) ?> / <?= e($currentRateUnit) ?>" readonly></label>
                        <label>Effective Approved Rate for this period<input value="<?= $validatedCalculation ? e(count($effectiveRates) > 1 ? 'Multiple dated rates' : money((float) (reset($effectiveRates) ?: $validatedCalculation['basic_rate'])) . ' / ' . $selectedRateUnit) : 'Validate to load approved rate' ?>" readonly><?php if ($validatedCalculation && count($effectiveRates) > 1): ?><small><?= e(implode(', ', array_map(static fn(float $rate): string => money($rate) . ' / ' . $selectedRateUnit, array_values($effectiveRates)))) ?>. The formula uses each workday’s effective rate.</small><?php endif; ?></label>
                        <label>Rate effective dates<input value="<?= count($effectiveRates) > 1 ? 'Multiple dates in selected period' : e((string) ($preview['rate_effective_date'] ?? 'Validate to confirm')) . (!empty($preview['rate_effective_to']) ? ' – ' . e($preview['rate_effective_to']) : '') ?>" readonly></label>
                        <label>Calculated Basic Salary<input value="<?= $validatedCalculation ? e(money((float) $preview['calculated_basic_salary'])) : 'Validate to calculate' ?>" readonly></label>
                        <label>Salary formula<output class="payroll-formula-output"><?= e((string) $preview['salary_formula']) ?></output></label>
                        <label>Employment setup<input value="<?= e((string) $selectedEmployee['employment_type']) ?> · <?= e($selectedPayType) ?>" readonly></label>
                        <label>Scheduled workdays<input value="<?= (int) $preview['scheduled_days'] ?> day<?= (int) $preview['scheduled_days'] === 1 ? '' : 's' ?>" readonly></label>
                        <label>Scheduled hours<input value="<?= e(number_format((int) $preview['scheduled_minutes'] / 60, 2)) ?> hours" readonly></label>
                        <?php if ($validatedCalculation): ?><label><?= $selectedPayType === 'Monthly' ? 'Period basic pay estimate' : 'Scheduled pay estimate' ?><input value="<?= e(money((float) $preview['scheduled_pay_estimate'])) ?>" readonly></label><?php endif; ?>
                        <label>Verified regular hours<input value="<?= e(number_format((int) $preview['regular_minutes'] / 60, 2)) ?> hours" readonly></label>
                        <label>Approved overtime<input value="<?= e(number_format((int) $preview['approved_overtime_minutes'] / 60, 2)) ?> hours" readonly></label>
                        <label>Regular OT rate<input value="<?= e(money((float) $preview['ot_rate'])) ?> / hour" readonly></label>
                        <label>Attendance records<input value="<?= (int) $preview['attendance_days'] ?> processed day<?= (int) $preview['attendance_days'] === 1 ? '' : 's' ?>" readonly></label>
                    </div>

                    <div class="payroll-data-note">
                        <?= ui_icon('fingerprint') ?>
                        <span><strong>Authoritative preview:</strong> validation runs the same server-side attendance and payroll calculations as Draft creation, then rolls back its preview transaction. Full-Time and Part-Time use separate pay rules.</span>
                    </div>

                    <?php if ((string) $preview['schedule_error'] !== ''): ?><div class="note-box"><strong>Needs review · <?= e((string) $selectedEmployee['employee_no']) ?> · <?= e($periodStart) ?>–<?= e($periodEnd) ?>:</strong> <?= e((string) $preview['schedule_error']) ?></div><?php endif; ?>
                    <?php if ($preview['suggested_start'] !== ''): ?><a class="btn btn-outline" href="<?= e('app.php?' . http_build_query(['page' => 'payroll', 'view' => 'generate', 'employee_id' => $selectedEmployeeId, 'employment_filter' => $employmentFilter, 'period_start' => $preview['suggested_start'], 'period_end' => $periodEnd, 'cash_advance_amount' => $cashAdvancePreviewRaw, 'validate' => '1'])) ?>">Use unpaid dates: <?= e($preview['suggested_start']) ?>–<?= e($periodEnd) ?></a><?php endif; ?>
                    <?php if ($validatedCalculation): ?>
                        <?php if ((float) $validatedCalculation['net_pay'] <= 0): ?><div class="note-box"><strong>Zero net pay:</strong> This validated period has no amount payable after attendance and allowed deductions. Review its absent/unpaid days and Cash Advance before generating payroll. Zero-pay results cannot be marked Paid.</div><?php endif; ?>
                        <div class="table-scroll"><table class="data-table" aria-label="Detailed validated payroll calculation"><tbody>
                            <tr><th>Pay type / approved rate</th><td><?= e($validatedCalculation['employment_type']) ?> · <?= e($validatedCalculation['pay_type']) ?> · <?= e(money($validatedCalculation['basic_rate'])) ?> / <?= e($selectedRateUnit) ?></td></tr>
                            <tr><th>Verified days / hours</th><td><?= e(number_format((float) $validatedCalculation['days_worked'], 2)) ?> days · <?= e(number_format((float) $validatedCalculation['regular_hours'], 2)) ?> hours</td></tr>
                            <tr><th>Late / undertime</th><td><?= e(ucchr_minutes_label((int) $validatedCalculation['late_minutes'])) ?> / <?= e(ucchr_minutes_label((int) $validatedCalculation['undertime_minutes'])) ?></td></tr>
                            <tr><th>Absences / unpaid leave</th><td><?= e(number_format((float) $validatedCalculation['absence_days'], 2)) ?> / <?= e(number_format((float) $validatedCalculation['unpaid_leave_days'], 2)) ?> days</td></tr>
                            <tr><th>Basic Pay</th><td><?= e(money($validatedCalculation['monthly_basic_salary'])) ?></td></tr>
                            <tr><th>Approved OT</th><td><?= e(money($validatedCalculation['overtime_pay'])) ?></td></tr>
                            <tr><th>Gross Pay</th><td><strong><?= e(money($validatedCalculation['gross_pay'])) ?></strong></td></tr>
                            <tr><th>Late deduction</th><td><?= e(money($validatedCalculation['late_deduction'])) ?></td></tr>
                            <tr><th>Undertime deduction</th><td><?= e(money($validatedCalculation['undertime_deduction'])) ?></td></tr>
                            <tr><th>Absence / unpaid time (includes half-day)</th><td><?= e(money((float) $validatedCalculation['half_day_deduction'] + (float) $validatedCalculation['absence_deduction'])) ?></td></tr>
                            <tr><th>Cash Advance</th><td><?= e(money($validatedCalculation['cash_advance_deduction'])) ?></td></tr>
                            <tr><th>Net Pay</th><td><strong><?= e(money($validatedCalculation['net_pay'])) ?></strong></td></tr>
                        </tbody></table></div>
                        <div class="payroll-review-totals" aria-label="Validated payroll preview">
                            <div><small>Status</small><strong>READY</strong></div>
                            <div><small>Basic pay</small><strong><?= e(money($validatedCalculation['monthly_basic_salary'])) ?></strong></div>
                            <div><small>Approved OT</small><strong><?= e(money($validatedCalculation['overtime_pay'])) ?></strong></div>
                            <div><small>Gross pay</small><strong><?= e(money($validatedCalculation['gross_pay'])) ?></strong></div>
                            <div><small>Late / Undertime</small><strong><?= e(money((float) $validatedCalculation['late_deduction'] + (float) $validatedCalculation['undertime_deduction'])) ?></strong></div>
                            <div><small>Absence / unpaid time</small><strong><?= e(money((float) $validatedCalculation['half_day_deduction'] + (float) $validatedCalculation['absence_deduction'])) ?></strong></div>
                            <div><small>Cash Advance</small><strong><?= e(money($validatedCalculation['cash_advance_deduction'])) ?></strong></div>
                            <div><small>Net pay</small><strong><?= e(money($validatedCalculation['net_pay'])) ?></strong></div>
                        </div>
                    <?php elseif (!$validationRequested): ?>
                        <div class="note-box">Select the period and Cash Advance, then choose <strong>Validate Payroll</strong> to see the exact calculation and any required action before generating payroll.</div>
                    <?php endif; ?>

                    <form method="post" class="payroll-generate-form" data-submit-lock data-payroll-generate-form>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="run_payroll">
                        <input type="hidden" name="employee_id" value="<?= (int) $selectedEmployeeId ?>">
                        <input type="hidden" name="period_start" value="<?= e($periodStart) ?>">
                        <input type="hidden" name="period_end" value="<?= e($periodEnd) ?>">
                        <input type="hidden" name="cash_advance_amount" value="<?= e($cashAdvancePreviewRaw) ?>">
                        <div class="payroll-payment-heading">
                            <div>
                                <h3><?= ui_icon('payslip', 'section-icon') ?>Payment Details</h3>
                                <p>Choose Pending for a Draft or Paid to record an already completed payment.</p>
                            </div><span class="badge badge-blue">Step 2 · Payment</span>
                        </div>
                        <div class="payroll-payment-grid">
                            <label>Payment method
                                <select name="payment_method" required>
                                    <option value="Cash">Cash</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                </select>
                            </label>
                            <label>Payment status
                                <select name="payment_status" data-payment-status required>
                                    <option value="Pending">Pending</option>
                                    <option value="Paid">Paid</option>
                                </select>
                            </label>
                            <label class="span-2">Payroll note<textarea name="notes" maxlength="1000" rows="3" placeholder="Optional payroll note or payment reference"></textarea></label>
                        </div>
                        <div class="payroll-submit-summary"><span><?= e(date('M j, Y', strtotime($periodStart))) ?> – <?= e(date('M j, Y', strtotime($periodEnd))) ?></span><strong><?= e(trim($selectedEmployee['first_name'] . ' ' . $selectedEmployee['last_name'])) ?></strong><small><?= e(ucchr_payroll_net_formula()) ?>. The exact values are saved as an immutable calculation snapshot.</small></div>
                        <div class="payroll-generate-actions"><a class="btn btn-outline" href="app.php?page=payroll">Cancel</a><button class="btn btn-primary btn-large" type="submit" data-submit-label="Saving payroll…" <?= !$payrollGenerationReady ? 'disabled' : '' ?>><?= ui_icon('payroll', 'button-icon') ?>Generate Payroll</button></div>
                    </form>
                <?php elseif ($batchSelected): ?>
                    <div class="payroll-data-note"><?= ui_icon('employees') ?><span><strong>Mixed payroll:</strong> each active employee is validated separately with the Full-Time or Part-Time engine. A batch Draft is created only when every employee is ready.</span></div>
                    <?php if ($batchPreviewError !== ''): ?><div class="note-box"><strong>Batch validation could not finish:</strong> <?= e($batchPreviewError) ?></div><?php endif; ?>
                    <?php if ($batchPreview): ?>
                        <?php $zeroPayEmployees = count(array_filter($batchPreview['employees'], static fn(array $entry): bool => $entry['calculation'] !== null && (float) $entry['calculation']['net_pay'] <= 0)); ?>
                        <div class="payroll-review-totals" aria-label="Batch validation summary">
                            <div><small>Employees</small><strong><?= count($batchPreview['employees']) ?></strong></div>
                            <div><small>Full-Time</small><strong><?= (int) $batchPreview['full_time'] ?></strong></div>
                            <div><small>Part-Time</small><strong><?= (int) $batchPreview['part_time'] ?></strong></div>
                            <div><small>Ready</small><strong><?= (int) $batchPreview['ready'] ?></strong></div>
                            <div><small>Needs attention</small><strong><?= (int) $batchPreview['needs_attention'] ?></strong></div>
                        </div>
                        <?php if ($zeroPayEmployees > 0): ?><div class="note-box"><strong>Review zero-pay results:</strong> <?= $zeroPayEmployees ?> employee<?= $zeroPayEmployees === 1 ? ' has' : 's have' ?> no net amount payable for this period. Check attendance and unpaid-time details before generating payroll. This mixed run cannot be marked Paid yet.</div><?php endif; ?>
                        <div class="table-scroll"><table class="data-table"><thead><tr><th>Employee</th><th>Type / Pay</th><th>Basic</th><th>Approved OT</th><th>Net Pay</th><th>Status / Required Action</th></tr></thead><tbody>
                        <?php foreach ($batchPreview['employees'] as $batchItem): $calc = $batchItem['calculation']; ?>
                            <tr><td><strong><?= e($batchItem['name']) ?></strong><small class="cell-subtitle"><?= e($batchItem['employee_no']) ?></small></td><td><?= e($calc['employment_type'] ?? $batchItem['employment_type']) ?><?= $calc ? ' · ' . e($calc['pay_type']) : '' ?></td><td><?= $calc ? e(money($calc['monthly_basic_salary'])) : '—' ?></td><td><?= $calc ? e(money($calc['overtime_pay'])) : '—' ?></td><td><?= $calc ? e(money($calc['net_pay'])) : '—' ?></td><td><span class="badge <?= $calc ? 'badge-green' : 'badge-amber' ?>"><?= e($batchItem['status']) ?></span><?php if (!$calc): ?><small class="cell-subtitle"><?= e($batchItem['reason']) ?></small><?php endif; ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php elseif (!$validationRequested): ?><div class="note-box">Choose <strong>Validate Payroll</strong> to check all active employees before generating mixed payroll.</div><?php endif; ?>
                    <form method="post" data-submit-lock data-payroll-generate-form class="payroll-generate-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="run_payroll">
                        <input type="hidden" name="scope" value="all">
                        <input type="hidden" name="employee_id" value="0">
                        <input type="hidden" name="period_start" value="<?= e($periodStart) ?>">
                        <input type="hidden" name="period_end" value="<?= e($periodEnd) ?>">
                        <input type="hidden" name="cash_advance_amount" value="0.00">
                        <div class="payroll-payment-grid">
                            <label>Payment method<select name="payment_method"><option value="Cash">Cash</option><option value="Bank Transfer">Bank Transfer</option></select></label>
                            <label>Payment status<select name="payment_status" data-payment-status required><option value="Pending">Pending</option><option value="Paid">Paid</option></select></label>
                        </div>
                        <label>Payroll note<textarea name="notes" maxlength="1000" rows="2" placeholder="Optional"></textarea></label>
                        <div class="payroll-generate-actions"><button class="btn btn-primary btn-large" type="submit" data-submit-label="Saving payroll…" <?= !$payrollGenerationReady ? 'disabled' : '' ?>><?= ui_icon('payroll', 'button-icon') ?>Generate Mixed Payroll</button></div>
                    </form>
                <?php else: ?>
                    <div class="empty-state">Select an active employee to load payroll information.</div>
                <?php endif; ?>
            </article>

            <aside class="panel standard-panel payroll-process-panel">
                <span class="payroll-process-icon"><?= ui_icon('activity') ?></span>
                <h2>Connected payroll flow</h2>
                <ol class="payroll-process-list">
                    <li><span>1</span>
                        <div><strong>Employee and period</strong><small>Select one active employee and completed dates.</small></div>
                    </li>
                    <li><span>2</span>
                        <div><strong>Automatic calculation</strong><small>The effective Daily, Hourly, or Monthly rate, attendance, approved OT, and deductions are validated.</small></div>
                    </li>
                    <li><span>3</span>
                        <div><strong>Payment &amp; Cash Advance</strong><small>Choose the payment details and add a Cash Advance only when applicable.</small></div>
                    </li>
                    <li><span>4</span>
                        <div><strong>Synchronized result</strong><small>Pending payroll is tracked; approved payroll becomes an official employee payslip.</small></div>
                    </li>
                </ol>
                <div class="payroll-process-stats">
                    <div><small>Recorded regular time</small><strong><?= e($minutesLabel((int) $preview['regular_minutes'])) ?></strong></div>
                    <div><small>Approved overtime</small><strong><?= e($minutesLabel((int) $preview['approved_overtime_minutes'])) ?></strong></div>
                    <div><small>Late recorded</small><strong><?= e(ucchr_minutes_label((int) $preview['late_minutes'])) ?></strong></div>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</section>

<?php if ($selectedRun): ?>
    <article class="panel standard-panel payroll-review-panel" id="payroll-review">
        <?php $selectedStatus = (string) $selectedRun['status'];
        $selectedTarget = $nextStatus[$selectedStatus] ?? null; ?>
        <div class="panel-title-row">
            <div>
                <h2><?= ui_icon('reports', 'heading-icon') ?>Payroll Review #<?= (int) $selectedRun['id'] ?></h2>
                <p class="muted"><?= e($periodLabel($selectedRun)) ?> · Generated <?= e(date('M j, Y g:i A', strtotime((string) $selectedRun['processed_at']))) ?></p>
            </div>
            <div class="button-row"><span class="badge <?= e($statusClass($selectedStatus)) ?>"><?= e($selectedStatus) ?></span><span class="badge <?= (string) $selectedRun['payment_status'] === 'Paid' ? 'badge-green' : 'badge-amber' ?>"><?= e((string) $selectedRun['payment_status']) ?> · <?= e((string) $selectedRun['payment_method']) ?></span></div>
        </div>
        <div class="payroll-review-totals">
            <div><small>Employees</small><strong><?= (int) $selectedRun['employee_count'] ?></strong></div>
            <div><small>Gross pay</small><strong><?= e(money($selectedRun['gross'])) ?></strong></div>
            <div><small>Total deductions</small><strong class="text-red">−<?= e(money($selectedRun['deductions'])) ?></strong></div>
            <div><small>Net payroll</small><strong class="text-green"><?= e(money($selectedRun['net'])) ?></strong></div>
        </div>
        <div class="payroll-data-note">
            <?= ui_icon('payroll') ?>
            <span><strong>Net Pay calculation:</strong> <?= e(ucchr_payroll_net_formula()) ?>.</span>
        </div>
        <div class="table-scroll">
            <table class="data-table payroll-review-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Approved Rate</th>
                        <th>Calculated Basic Salary</th>
                        <th>Attendance</th>
                        <th>Approved OT / Rate</th>
                        <th>Gross Pay</th>
                        <th>Late</th>
                        <th>Undertime</th>
                        <th>Absence / Unpaid Time</th>
                        <th>Cash Advance</th>
                        <?php if ($selectedRunHasLegacyOther): ?><th>Legacy Other</th><?php endif; ?>
                        <th>Total Deductions</th>
                        <th>Net Pay</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($selectedItems as $item): ?>
                        <tr>
                            <td><strong><?= e(trim($item['first_name'] . ' ' . $item['middle_name'] . ' ' . $item['last_name'])) ?></strong><small class="cell-subtitle"><?= e((string) $item['employee_no']) ?> · <?= e((string) $item['department_name']) ?></small></td>
                            <?php
                            $itemPayType = in_array((string) ($item['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true) ? (string) $item['pay_type'] : 'Daily';
                            $itemUnit = $itemPayType === 'Hourly' ? 'hour' : ($itemPayType === 'Monthly' ? 'month' : 'day');
                            $itemFormula = ucchr_payroll_basic_salary_formula($item);
                            ?>
                            <td><strong><?= e(money($item['basic_rate'])) ?></strong><small class="cell-subtitle"><?= e($itemPayType) ?> · per <?= e($itemUnit) ?> · <?= e((string) ($item['employment_type'] ?: 'Legacy record')) ?></small></td>
                            <td><strong><?= e(money($item['monthly_basic_salary'])) ?></strong><small class="cell-subtitle"><?= e($itemFormula) ?></small></td>
                            <td><?= e(number_format((float) $item['regular_hours'], 2)) ?>h<small class="cell-subtitle"><?= e(number_format((float) $item['days_worked'], 2)) ?> attended day equivalent</small></td>
                            <td><?= e(number_format((float) $item['overtime_hours'], 2)) ?>h<small class="cell-subtitle text-green"><?= e(money($item['overtime_pay'])) ?> · <?= e(money($item['ot_rate'])) ?>/h</small></td>
                            <td><strong><?= e(money($item['gross_pay'])) ?></strong></td>
                            <td class="text-red">−<?= e(money($item['late_deduction'])) ?><small class="cell-subtitle"><?= e(ucchr_minutes_label((int) $item['late_minutes'])) ?></small></td>
                            <td class="text-red">−<?= e(money($item['undertime_deduction'])) ?><small class="cell-subtitle"><?= e(ucchr_minutes_label((int) $item['undertime_minutes'])) ?></small></td>
                            <td class="text-red">−<?= e(money((float) $item['half_day_deduction'] + (float) $item['absence_deduction'])) ?><small class="cell-subtitle"><?= e(number_format((float) $item['absence_days'], 2)) ?> absent · <?= e(number_format((float) $item['unpaid_leave_days'], 2)) ?> unpaid leave · half-days included</small></td>
                            <td class="text-red">−<?= e(money($item['cash_advance_deduction'])) ?></td>
                            <?php if ($selectedRunHasLegacyOther): ?><td class="text-red">−<?= e(money($item['other_deductions'])) ?></td><?php endif; ?>
                            <td class="text-red"><strong>−<?= e(money($item['total_deductions'])) ?></strong></td>
                            <td class="text-green"><strong><?= e(money($item['net_pay'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$selectedItems): ?><tr>
                            <td colspan="<?= $selectedRunHasLegacyOther ? 13 : 12 ?>" class="empty-state">This run has no payroll items.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="payroll-review-actions">
            <?php if (!in_array($selectedStatus, ['Paid', 'Released'], true)): ?>
                <form method="post" class="inline-form" data-submit-lock><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_payroll_payment_method"><input type="hidden" name="run_id" value="<?= (int) $selectedRun['id'] ?>"><select name="payment_method" aria-label="Payment method">
                        <option value="Cash" <?= (string) $selectedRun['payment_method'] === 'Cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="Bank Transfer" <?= (string) $selectedRun['payment_method'] === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                    </select><button class="btn btn-outline" type="submit"><?= ui_icon('settings', 'button-icon') ?>Update Payment Method</button></form>
            <?php endif; ?>
            <?php if ($selectedStatus === 'Draft'): ?>
                <form method="post" class="inline-form" data-submit-lock><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="recalculate_payroll"><input type="hidden" name="run_id" value="<?= (int) $selectedRun['id'] ?>"><button class="btn btn-outline" type="submit"><?= ui_icon('refresh', 'button-icon') ?>Recalculate Draft</button></form>
                <?php if (!$selectedRunEverReviewed && (string) $selectedRun['payment_status'] === 'Pending'): ?><form method="post" class="inline-form" data-submit-lock onsubmit="return confirm('Delete this unpaid Draft and its payroll items? Attendance records will be kept. This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_payroll_draft">
                    <input type="hidden" name="run_id" value="<?= (int) $selectedRun['id'] ?>">
                    <input name="reason" maxlength="1000" placeholder="Required deletion reason" aria-label="Reason for deleting Draft" required>
                    <button class="btn btn-outline" type="submit"><?= ui_icon('trash', 'button-icon') ?>Delete Draft &amp; Re-run</button>
                </form><?php endif; ?>
            <?php endif; ?>
            <?php if (in_array($selectedStatus, ['For Review', 'Approved'], true)): ?><form method="post" class="inline-form" data-submit-lock onsubmit="return confirm('Return this payroll to Draft for correction?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="return_payroll_to_draft"><input type="hidden" name="run_id" value="<?= (int) $selectedRun['id'] ?>"><input name="note" maxlength="1000" placeholder="Required correction reason" required><button class="btn btn-outline" type="submit"><?= ui_icon('arrow-left', 'button-icon') ?>Return to Draft</button></form><?php endif; ?>
            <?php if ($selectedTarget): ?><form method="post" class="inline-form" data-submit-lock onsubmit="return confirm('Move payroll #<?= (int) $selectedRun['id'] ?> to <?= e($selectedTarget) ?>?');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="transition_payroll"><input type="hidden" name="run_id" value="<?= (int) $selectedRun['id'] ?>"><input type="hidden" name="target_status" value="<?= e($selectedTarget) ?>"><input name="note" maxlength="1000" placeholder="Optional review note"><button class="btn btn-primary" type="submit"><?= ui_icon('check-circle', 'button-icon') ?><?= e($transitionLabel[$selectedTarget]) ?></button></form><?php elseif (in_array($selectedStatus, ['Paid', 'Released'], true)): ?><a class="btn btn-primary" href="app.php?page=payslips"><?= ui_icon('payslip', 'button-icon') ?>Open Official Payslip</a><?php endif; ?>
        </div>
    </article>
<?php endif; ?>
