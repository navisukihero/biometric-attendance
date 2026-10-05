<?php

declare(strict_types=1);

if (!isset($dtr) || !is_array($dtr)) {
    http_response_code(404);
    exit('DTR document is unavailable.');
}

$dtrTotals = $dtr['totals'];
?>
<article class="dtr-document" role="document" aria-label="Daily Time Record for <?= e((string) $dtr['employee']['name']) ?>">
    <header class="dtr-document-header">
        <img src="assets/images/ucclogo.jpg" alt="UCC Logo">
        <div>
            <p>Republic of the Philippines</p>
            <strong>Ubay Community College</strong>
            <small>Biometric Attendance and Payroll System</small>
        </div>
        <span class="dtr-document-mark">SYSTEM<br>GENERATED</span>
    </header>

    <div class="dtr-document-title">
        <h1>DAILY TIME RECORD</h1>
        <p>Official employee attendance summary · View only</p>
    </div>

    <section class="dtr-identity-grid">
        <div><small>Employee Name</small><strong><?= e((string) $dtr['employee']['name']) ?></strong></div>
        <div><small>Employee ID</small><strong><?= e((string) $dtr['employee']['employee_no']) ?></strong></div>
        <div><small>Department</small><strong><?= e((string) $dtr['employee']['department']) ?></strong></div>
        <div><small>Position</small><strong><?= e((string) $dtr['employee']['position']) ?></strong></div>
        <div class="dtr-period-field"><small>Date Covered</small><strong><?= e((string) $dtr['period_label']) ?></strong></div>
        <div><small>Date Generated</small><strong><?= e((string) $dtr['generated_at']) ?></strong></div>
    </section>

    <section class="dtr-summary-strip" aria-label="DTR totals">
        <div><small>Days with scans</small><strong><?= (int) $dtrTotals['attendance_days'] ?></strong></div>
        <div><small>Total worked</small><strong><?= e(employee_dtr_minutes((int) $dtrTotals['worked_minutes'])) ?></strong></div>
        <div><small>Total late</small><strong><?= e(employee_dtr_minutes((int) $dtrTotals['late_minutes'])) ?></strong></div>
        <div><small>Total undertime</small><strong><?= e(employee_dtr_minutes((int) $dtrTotals['undertime_minutes'])) ?></strong></div>
        <div><small>Approved OT</small><strong><?= e(employee_dtr_minutes((int) $dtrTotals['approved_overtime_minutes'])) ?></strong></div>
    </section>

    <table class="dtr-record-table">
        <thead>
            <tr>
                <th>Date / Day</th>
                <th>Expected</th>
                <th>Time In</th>
                <th>Time Out</th>
                <th>Worked</th>
                <th>Late</th>
                <th>Undertime</th>
                <th>Approved OT</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dtr['rows'] as $row): ?>
                <tr class="<?= $row['has_record'] ? '' : 'dtr-empty-row' ?>">
                    <td><strong><?= e((string) $row['date_label']) ?></strong><small><?= e((string) $row['day']) ?></small></td>
                    <td><?= e((string) $row['expected']) ?></td>
                    <td><?= e((string) $row['time_in']) ?></td>
                    <td><?= e((string) $row['time_out']) ?></td>
                    <td><?= e((string) $row['worked']) ?></td>
                    <td><?= e((string) $row['late']) ?></td>
                    <td><?= e((string) $row['undertime']) ?></td>
                    <td><?= e((string) $row['approved_overtime']) ?></td>
                    <td><strong><?= e((string) $row['status']) ?></strong><?php if ($row['detail'] !== ''): ?><small><?= e((string) $row['detail']) ?></small><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <section class="dtr-document-notes">
        <p><strong>Attendance summary:</strong> <?= (int) $dtrTotals['late_count'] ?> late day(s), <?= (int) $dtrTotals['undertime_count'] ?> undertime day(s), and <?= (int) $dtrTotals['incomplete_count'] ?> incomplete day(s).</p>
        <p>This DTR is generated directly from the processed biometric attendance records maintained by HR/Admin. Blank dates have no processed attendance row. Contact HR/Admin if a record requires review.</p>
    </section>

    <footer class="dtr-signatures">
        <div><span></span><strong>Employee Signature</strong></div>
        <div><span></span><strong>HR/Admin Verification</strong></div>
    </footer>
</article>