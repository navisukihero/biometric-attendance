<?php

declare(strict_types=1);

$itemColumns = function_exists('attendance_table_columns') ? attendance_table_columns($pdo, 'payroll_items') : [];
$integratedPayslips = isset($itemColumns['monthly_basic_salary'], $itemColumns['regular_pay'], $itemColumns['undertime_deduction'], $itemColumns['half_day_deduction'], $itemColumns['absence_deduction'], $itemColumns['total_deductions']);
$items = $pdo->query(
    'SELECT pi.*, e.employee_no, e.first_name, e.last_name,
            pr.period_start, pr.period_end, pr.status AS payroll_status,
            pr.payment_method, pr.payment_status
     FROM payroll_items pi
     JOIN employees e ON e.id=pi.employee_id
     JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
     ORDER BY pr.id DESC, e.last_name, e.first_name
     LIMIT 200'
)->fetchAll();
$printableRuns = $pdo->query(
    'SELECT pr.id, pr.period_start, pr.period_end, pr.status, COUNT(pi.id) payslip_count
     FROM payroll_runs pr
     JOIN payroll_items pi ON pi.payroll_run_id=pr.id
     WHERE pr.status IN ("Approved","Finalized","Paid","Released")
     GROUP BY pr.id, pr.period_start, pr.period_end, pr.status
     ORDER BY pr.id DESC
     LIMIT 50'
)->fetchAll();
$statusClass = static fn(string $status): string => in_array($status, ['Paid', 'Released'], true)
    ? 'badge-green'
    : (in_array($status, ['Approved', 'Finalized'], true) ? 'badge-blue' : 'badge-amber');
?>
<article class="panel standard-panel">
    <div class="panel-title-row">
        <div>
            <h2><?= ui_icon('payslip', 'heading-icon') ?>Employee Payslips &amp; Payment Status</h2>
            <p class="muted">Generated payroll appears immediately; printing becomes available after approval.</p>
        </div>
        <div class="payslip-toolbar-actions">
            <?php if ($printableRuns): ?>
                <form class="payslip-print-form" method="get" action="payslip-print.php" target="_blank">
                    <label for="payslip-run">Payroll period</label>
                    <select id="payslip-run" name="run_id" aria-label="Payroll period to print">
                        <?php foreach ($printableRuns as $run): ?>
                            <option value="<?= (int) $run['id'] ?>">#<?= (int) $run['id'] ?> · <?= e(date('M j', strtotime((string) $run['period_start']))) ?>–<?= e(date('j, Y', strtotime((string) $run['period_end']))) ?> · <?= (int) $run['payslip_count'] ?> slip<?= (int) $run['payslip_count'] === 1 ? '' : 's' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="autoprint" value="1">
                    <button class="btn btn-primary" type="submit"><?= ui_icon('print', 'button-icon') ?>Print Full Period</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-outline" href="export.php?type=payroll"><?= ui_icon('download', 'button-icon') ?>Export Register</a>
        </div>
    </div>
    <form id="payslip-bulk-form" method="get" action="payslip-print.php" target="_blank">
        <input type="hidden" name="autoprint" value="1">
        <div class="payslip-bulk-bar">
            <div>
                <strong>Bulk printing</strong>
                <span id="payslip-selection-count" class="muted" aria-live="polite">Select approved employee payslips below.</span>
            </div>
            <button id="payslip-print-selected" class="btn btn-primary" type="submit" disabled><?= ui_icon('print', 'button-icon') ?>Print Selected</button>
        </div>
        <div class="table-scroll">
            <table class="data-table">
            <thead>
                <tr>
                    <th class="payslip-select-column"><input id="payslip-select-all" type="checkbox" aria-label="Select all printable payslips"></th>
                    <th>Employee / Period</th>
                    <th>Approved Rate</th>
                    <th>Calculated Basic Salary</th>
                    <th>Approved OT</th>
                    <th>Gross</th>
                    <th>Deductions</th>
                    <th>Net</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $regularPay = $integratedPayslips
                        ? (float) $item['regular_pay']
                        : max(0, (float) $item['gross_pay'] - (float) $item['overtime_pay']);
                    $lateDeduction = $integratedPayslips ? (float) $item['late_deduction'] : 0.0;
                    $runStatus = (string) $item['payroll_status'];
                    $canPrint = in_array($runStatus, ['Approved', 'Finalized', 'Paid', 'Released'], true);
                    $itemPayType = in_array((string) ($item['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true) ? (string) $item['pay_type'] : 'Daily';
                    $itemUnit = $itemPayType === 'Hourly' ? 'hour' : ($itemPayType === 'Monthly' ? 'month' : 'day');
                    $itemFormula = ucchr_payroll_basic_salary_formula($item);
                    ?>
                    <tr>
                        <td class="payslip-select-column"><?php if ($canPrint): ?><input class="payslip-select" type="checkbox" name="ids[]" value="<?= (int) $item['id'] ?>" aria-label="Select payslip for <?= e($item['first_name'] . ' ' . $item['last_name']) ?>"><?php else: ?><span aria-hidden="true">—</span><?php endif; ?></td>
                        <td><strong><?= e($item['first_name'] . ' ' . $item['last_name']) ?></strong><small class="cell-subtitle"><?= e($item['employee_no']) ?> · <?= e(date('M j', strtotime((string) $item['period_start']))) ?>–<?= e(date('j, Y', strtotime((string) $item['period_end']))) ?></small></td>
                        <td><strong><?= $integratedPayslips ? e(money($item['basic_rate'])) : 'Legacy' ?></strong><small class="cell-subtitle"><?= e($itemPayType) ?> · per <?= e($itemUnit) ?></small></td>
                        <td><?= e(money($integratedPayslips ? $item['monthly_basic_salary'] : $regularPay)) ?><small class="cell-subtitle"><?= $integratedPayslips ? e($itemFormula) : 'Legacy regular pay' ?></small></td>
                        <td><?= e(number_format((float) $item['overtime_hours'], 2)) ?>h<small class="cell-subtitle text-green"><?= e(money($item['overtime_pay'])) ?></small></td>
                        <td><?= e(money($item['gross_pay'])) ?></td>
                        <td class="text-red">−<?= e(money($item['total_deductions'])) ?><small class="cell-subtitle">Late <?= e(money($lateDeduction)) ?> · Undertime <?= e(money($item['undertime_deduction'] ?? 0)) ?> · Absence / unpaid time <?= e(money((float) ($item['half_day_deduction'] ?? 0) + (float) ($item['absence_deduction'] ?? 0))) ?> · Cash Advance <?= e(money($item['cash_advance_deduction'] ?? 0)) ?><?php if ((float) ($item['other_deductions'] ?? 0) > 0): ?> · Legacy other <?= e(money($item['other_deductions'])) ?><?php endif; ?></small></td>
                        <td class="text-green"><strong><?= e(money($item['net_pay'])) ?></strong></td>
                        <td><span class="badge <?= e($statusClass($runStatus)) ?>"><?= e($runStatus) ?></span><small class="cell-subtitle"><?= e((string) $item['payment_status']) ?> · <?= e((string) $item['payment_method']) ?></small><?php if ($canPrint): ?><a class="btn btn-mini btn-outline" href="payslip-print.php?id=<?= (int) $item['id'] ?>&amp;autoprint=1" target="_blank" rel="noopener"><?= ui_icon('print', 'button-icon') ?>Print</a><?php else: ?><small class="cell-subtitle">Awaiting approval</small><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?><tr>
                        <td colspan="9" class="empty-state">No payroll has been generated yet.</td>
                    </tr><?php endif; ?>
            </tbody>
            </table>
        </div>
    </form>
</article>
<script>
    (() => {
        const form = document.getElementById('payslip-bulk-form');
        if (!form) return;
        const selectAll = document.getElementById('payslip-select-all');
        const selections = Array.from(form.querySelectorAll('.payslip-select'));
        const submit = document.getElementById('payslip-print-selected');
        const countLabel = document.getElementById('payslip-selection-count');
        const update = () => {
            const selected = selections.filter((checkbox) => checkbox.checked).length;
            submit.disabled = selected === 0;
            countLabel.textContent = selected === 0
                ? 'Select approved employee payslips below.'
                : `${selected} payslip${selected === 1 ? '' : 's'} selected · ${selected === 1 ? '1/8 Letter paper' : 'A4, up to 8 per page'}`;
            selectAll.checked = selections.length > 0 && selected === selections.length;
            selectAll.indeterminate = selected > 0 && selected < selections.length;
        };
        selectAll.addEventListener('change', () => {
            selections.forEach((checkbox) => { checkbox.checked = selectAll.checked; });
            update();
        });
        selections.forEach((checkbox) => checkbox.addEventListener('change', update));
        update();
    })();
</script>
