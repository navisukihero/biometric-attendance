<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}

$employeeId = (int) $employee['employee_id'];
$payslipStmt = $pdo->prepare(
    'SELECT pi.id, pi.pay_type, pi.basic_rate, pi.days_worked, pi.regular_hours,
            pi.monthly_basic_salary, pi.regular_pay, pi.overtime_hours, pi.overtime_pay, pi.holiday_pay,
            pi.rest_day_pay, pi.late_minutes, pi.late_deduction,
            pi.undertime_minutes, pi.undertime_deduction,
            pi.half_day_deduction, pi.absence_deduction, pi.absence_days, pi.unpaid_leave_days,
            pi.cash_advance_deduction, pi.other_deductions, pi.total_deductions,
            pi.gross_pay, pi.net_pay,
            pr.period_start, pr.period_end, pr.processed_at, pr.status AS payroll_status,
            pr.payment_method, pr.payment_status
     FROM payroll_items pi
     JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
     WHERE pi.employee_id=?
     ORDER BY pr.period_end DESC, pi.id DESC
     LIMIT 100'
);
$payslipStmt->execute([$employeeId]);
$payslips = $payslipStmt->fetchAll();

$latest = $payslips[0] ?? null;
$releasedTotal = 0.0;
foreach ($payslips as $payslip) {
    if (in_array((string) $payslip['payroll_status'], ['Approved', 'Finalized', 'Paid', 'Released'], true)) {
        $releasedTotal += (float) $payslip['net_pay'];
    }
}
?>
<section class="schedule-page employee-payslips-page">
    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('payslip', 'heading-icon') ?>My Payslips</h2>
                <p class="muted">Track generated payroll for <?= e($employee['employee_no']) ?>. Official printing unlocks after approval.</p>
            </div>
            <span class="badge badge-green"><?= count($payslips) ?> available</span>
        </div>

        <?php if ($latest): ?>
            <div class="metric-strip">
                <div><small>Latest period</small><strong class="stat-date"><?= e(date('M j', strtotime((string) $latest['period_start'])) . '–' . date('M j, Y', strtotime((string) $latest['period_end']))) ?></strong></div>
                <div><small>Latest gross pay</small><strong><?= e(money($latest['gross_pay'])) ?></strong></div>
                <div><small>Latest net pay</small><strong class="text-green"><?= e(money($latest['net_pay'])) ?></strong></div>
                <div><small>Total available net pay</small><strong><?= e(money($releasedTotal)) ?></strong></div>
            </div>
        <?php endif; ?>

        <div class="note-box">Payroll records are read-only. Pending calculations remain visible for tracking; Print / Save PDF becomes available only after HR approval.<br><strong>Net Pay calculation:</strong> <?= e(ucchr_payroll_net_formula()) ?>.</div>

        <div class="table-scroll">
            <table class="data-table employee-payslip-table">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Approved Rate</th>
                        <th>Calculated Basic Salary</th>
                        <th>Attendance</th>
                        <th>Approved Overtime</th>
                        <th>Gross Pay</th>
                        <th>Total Deductions</th>
                        <th>Net Pay</th>
                        <th>Payslip</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payslips as $payslip): ?>
                        <?php
                        $canPrint = in_array((string) $payslip['payroll_status'], ['Approved', 'Finalized', 'Paid', 'Released'], true);
                        $payslipPayType = in_array((string) ($payslip['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true) ? (string) $payslip['pay_type'] : 'Daily';
                        $payslipUnit = $payslipPayType === 'Hourly' ? 'hour' : ($payslipPayType === 'Monthly' ? 'month' : 'day');
                        $payslipFormula = ucchr_payroll_basic_salary_formula($payslip);
                        ?>
                        <tr>
                            <td><strong><?= e(date('M j', strtotime((string) $payslip['period_start'])) . '–' . date('M j, Y', strtotime((string) $payslip['period_end']))) ?></strong><small class="cell-subtitle"><?= e((string) $payslip['payroll_status']) ?> · generated <?= e(date('M j, Y', strtotime((string) $payslip['processed_at']))) ?></small></td>
                            <td><strong><?= e(money($payslip['basic_rate'])) ?></strong><small class="cell-subtitle"><?= e($payslipPayType) ?> · per <?= e($payslipUnit) ?></small></td>
                            <td><?= e(money($payslip['monthly_basic_salary'])) ?><small class="cell-subtitle"><?= e($payslipFormula) ?></small></td>
                            <td><?= e(number_format((float) $payslip['days_worked'], 2)) ?> day eq.<small class="cell-subtitle"><?= e(number_format((float) $payslip['regular_hours'], 2)) ?>h recorded</small></td>
                            <td><?= e(number_format((float) $payslip['overtime_hours'], 2)) ?>h<small class="cell-subtitle"><?= e(money($payslip['overtime_pay'])) ?></small></td>
                            <td><?= e(money($payslip['gross_pay'])) ?></td>
                            <td class="text-red">−<?= e(money($payslip['total_deductions'])) ?><small class="cell-subtitle">Late <?= e(money($payslip['late_deduction'])) ?> · Undertime <?= e(money($payslip['undertime_deduction'])) ?> · Absence / unpaid time <?= e(money((float) $payslip['half_day_deduction'] + (float) $payslip['absence_deduction'])) ?> · Cash Advance <?= e(money($payslip['cash_advance_deduction'])) ?><?php if ((float) $payslip['other_deductions'] > 0): ?> · Legacy other <?= e(money($payslip['other_deductions'])) ?><?php endif; ?></small></td>
                            <td><strong class="text-green"><?= e(money($payslip['net_pay'])) ?></strong></td>
                            <td><?php if ($canPrint): ?><a class="btn btn-mini btn-outline" href="employee-payslip.php?id=<?= (int) $payslip['id'] ?>" target="_blank" rel="noopener"><?= ui_icon('print', 'button-icon') ?>Print / Save PDF</a><?php else: ?><span class="badge badge-amber">Processing</span><?php endif; ?><small class="cell-subtitle"><?= e((string) $payslip['payroll_status']) ?> · <?= e((string) $payslip['payment_status']) ?> via <?= e((string) $payslip['payment_method']) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$payslips): ?><tr>
                            <td colspan="9" class="empty-state">No payroll has been generated for your account yet.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
