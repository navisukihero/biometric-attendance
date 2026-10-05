<?php
$weekly = [];
for ($i = 4; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} weekdays"));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM attendance WHERE scan_date=?');
    $stmt->execute([$day]);
    $weekly[] = ['label' => date('D', strtotime($day)), 'count' => (int) $stmt->fetchColumn()];
}
$maxAttendance = max(1, ...array_column($weekly, 'count'));
$hasIntegratedPayroll = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="payroll_items" AND COLUMN_NAME="total_deductions"'
)->fetchColumn() === 1;
$deductionExpression = $hasIntegratedPayroll
    ? 'COALESCE(SUM(pi.late_deduction),0)'
    : '0';
$payroll = $pdo->query(
    "SELECT pr.*, COALESCE(SUM(pi.net_pay),0) net, {$deductionExpression} deductions
     FROM payroll_runs pr
     LEFT JOIN payroll_items pi ON pi.payroll_run_id=pr.id
     GROUP BY pr.id ORDER BY pr.id DESC LIMIT 1"
)->fetch();
$net = (float) ($payroll['net'] ?? 0);
$deductions = (float) ($payroll['deductions'] ?? 0);
$total = max(1, $net + $deductions);
$deductPercent = round($deductions / $total * 100, 1);
$reportFrom = date('Y-m-01');
$reportTo = date('Y-m-d');
$dateQuery = '&from=' . rawurlencode($reportFrom) . '&to=' . rawurlencode($reportTo);
?>
<section class="reports-grid">
    <article class="panel chart-panel attendance-chart">
        <h2><?= ui_icon('attendance', 'heading-icon') ?>Attendance<br>summary — this week</h2>
        <div class="bar-chart"><?php foreach ($weekly as $day): ?><div class="bar-item"><span class="bar-value"><?= $day['count'] ?></span>
                    <div class="bar" style="height:<?= max(12, ($day['count'] / $maxAttendance) * 130) ?>px"></div><small><?= e($day['label']) ?></small>
                </div><?php endforeach; ?></div>
        <div class="legend"><span><i class="legend-red"></i> On target</span><span><i class="legend-pink"></i> Below target</span></div>
    </article>
    <article class="panel chart-panel payroll-chart">
        <h2><?= ui_icon('payroll', 'heading-icon') ?>Payroll cost — <?= $payroll ? date('M j', strtotime($payroll['period_start'])) . '–' . date('j, Y', strtotime($payroll['period_end'])) : 'No run yet' ?></h2>
        <div class="donut-layout">
            <div class="donut" style="--deduct:<?= $deductPercent ?>%"><span><?= number_format(100 - $deductPercent, 1) ?>%</span></div>
            <ul>
                <li><i class="legend-red"></i><span>Net pay disbursed<strong><?= money($net) ?></strong></span></li>
                <li><i class="legend-pink"></i><span>Late deductions<strong><?= money($deductions) ?></strong></span></li>
            </ul>
        </div>
    </article>
    <article class="panel export-panel">
        <h2><?= ui_icon('download', 'heading-icon') ?>Export reports</h2>
        <p class="muted">Attendance exports below cover <?= e(date('M j', strtotime($reportFrom))) ?>–<?= e(date('M j, Y', strtotime($reportTo))) ?>. Payroll exports preserve each run's frozen values and status.</p>
        <div class="export-grid">
            <div class="export-card"><span class="export-icon blue"><?= ui_icon('attendance') ?></span>
                <h3>Processed<br>attendance</h3>
                <p>Expected/actual time and computed minutes</p><div class="export-actions"><a class="btn btn-outline" href="export.php?type=attendance<?= e($dateQuery) ?>&amp;format=word"><?= ui_icon('payslip', 'button-icon') ?>Export Word</a></div>
            </div>
            <div class="export-card"><span class="export-icon blue"><?= ui_icon('fingerprint') ?></span>
                <h3>Raw biometric<br>events</h3>
                <p>Append-only ESP32 Time In/Time Out source</p><div class="export-actions"><a class="btn btn-outline" href="export.php?type=raw_attendance<?= e($dateQuery) ?>&amp;format=word"><?= ui_icon('payslip', 'button-icon') ?>Export Word</a></div>
            </div>
            <div class="export-card"><span class="export-icon red"><?= ui_icon('warning') ?></span>
                <h3>Late<br>report</h3>
                <p>Grace-aware late minutes by employee</p><div class="export-actions"><a class="btn btn-outline" href="export.php?type=late<?= e($dateQuery) ?>&amp;format=word"><?= ui_icon('payslip', 'button-icon') ?>Export Word</a></div>
            </div>
            <div class="export-card"><span class="export-icon red"><?= ui_icon('arrow-left') ?></span>
                <h3>Undertime<br>report</h3>
                <p>Early departures kept separate from late</p><div class="export-actions"><a class="btn btn-outline" href="export.php?type=undertime<?= e($dateQuery) ?>&amp;format=word"><?= ui_icon('payslip', 'button-icon') ?>Export Word</a></div>
            </div>
            <div class="export-card"><span class="export-icon red"><?= ui_icon('x') ?></span>
                <h3>Absence<br>report</h3>
                <p>Scheduled days after holiday and leave checks</p><div class="export-actions"><a class="btn btn-outline" href="export.php?type=absence<?= e($dateQuery) ?>&amp;format=word"><?= ui_icon('payslip', 'button-icon') ?>Export Word</a></div>
            </div>
            <div class="export-card"><span class="export-icon green"><?= ui_icon('overtime') ?></span>
                <h3>Overtime<br>report</h3>
                <p>Employee-requested, HR-approved minutes</p><div class="export-actions"><a class="btn btn-outline" href="export.php?type=overtime<?= e($dateQuery) ?>&amp;format=word"><?= ui_icon('payslip', 'button-icon') ?>Export Word</a></div>
            </div>
            <div class="export-card"><span class="export-icon green"><?= ui_icon('holiday') ?></span>
                <h3>Holiday work<br>report</h3>
                <p>Holiday and rest-day classifications</p><div class="export-actions"><a class="btn btn-outline" href="export.php?type=holiday<?= e($dateQuery) ?>&amp;format=word"><?= ui_icon('payslip', 'button-icon') ?>Export Word</a></div>
            </div>
            <div class="export-card"><span class="export-icon green"><?= ui_icon('payroll') ?></span>
                <h3>Payroll<br>register</h3>
                <p>Regular, overtime, holiday, late, and net pay</p><div class="export-actions"><a class="btn btn-outline" href="export.php?type=payroll&amp;format=word"><?= ui_icon('payslip', 'button-icon') ?>Export Word</a></div>
            </div>
        </div>
    </article>
</section>
