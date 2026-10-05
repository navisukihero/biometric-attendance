<?php

declare(strict_types=1);

if (!isset($overtimeDocument) || !is_array($overtimeDocument) || !$overtimeDocument) {
    http_response_code(404);
    exit('Overtime request document is unavailable.');
}

$documentStatus = (string) $overtimeDocument['status'];
$decisionRecorded = !empty($overtimeDocument['approval_date']);
$requestNumber = 'OT-' . str_pad((string) (int) $overtimeDocument['id'], 6, '0', STR_PAD_LEFT);
?>
<article class="overtime-document" role="document" aria-label="Overtime request <?= e($requestNumber) ?>">
    <header class="overtime-document-header">
        <img src="assets/images/ucclogo.jpg" alt="UCC Logo">
        <div>
            <p>Republic of the Philippines</p>
            <strong>Ubay Community College</strong>
            <small>Human Resources · Biometric Attendance and Payroll System</small>
        </div>
        <span class="overtime-document-mark">OFFICIAL<br>REQUEST</span>
    </header>

    <div class="overtime-document-title">
        <p>Human Resources Office</p>
        <h1>OVERTIME REQUEST FORM</h1>
        <span><?= e($requestNumber) ?></span>
    </div>

    <section class="overtime-document-grid" aria-label="Employee and request details">
        <div class="wide"><small>Employee Name</small><strong><?= e((string) $overtimeDocument['employee_name']) ?></strong></div>
        <div><small>Employee ID</small><strong><?= e((string) $overtimeDocument['employee_no']) ?></strong></div>
        <div><small>Department</small><strong><?= e((string) ($overtimeDocument['department'] ?: 'Unassigned')) ?></strong></div>
        <div><small>Position</small><strong><?= e((string) ($overtimeDocument['position'] ?: 'Not specified')) ?></strong></div>
        <div><small>Overtime Work Date</small><strong><?= e(date('F j, Y', strtotime((string) $overtimeDocument['attendance_date']))) ?></strong></div>
        <div><small>Requested Overtime Hours</small><strong><?= e(ucchr_overtime_minutes_label((int) $overtimeDocument['requested_minutes'])) ?></strong></div>
        <div><small>Date &amp; Time Requested</small><strong><?= e(date('F j, Y · g:i:s A', strtotime((string) $overtimeDocument['created_at']))) ?></strong></div>
    </section>

    <section class="overtime-document-reason">
        <small>Reason for Overtime</small>
        <p><?= nl2br(e((string) ($overtimeDocument['reason'] ?: 'No reason supplied.'))) ?></p>
    </section>

    <section class="overtime-document-decision">
        <div class="overtime-decision-heading">
            <div><small>HR/Admin Decision</small><strong><?= e($documentStatus) ?></strong></div>
            <span class="overtime-status-stamp overtime-status-<?= e(strtolower($documentStatus)) ?>"><?= e(strtoupper($documentStatus)) ?></span>
        </div>
        <div class="overtime-decision-grid">
            <div><small>Approving / Reviewing Person</small><strong><?= $overtimeDocument['approver_name'] ? e((string) $overtimeDocument['approver_name']) : 'Pending HR/Admin review' ?></strong></div>
            <div><small>Approval / Rejection Date &amp; Time</small><strong><?= $decisionRecorded ? e(date('F j, Y · g:i:s A', strtotime((string) $overtimeDocument['approval_date']))) : 'Not yet decided' ?></strong></div>
            <div><small>Approved Overtime</small><strong><?= $documentStatus === 'Approved' ? e(ucchr_overtime_minutes_label((int) $overtimeDocument['approved_minutes'])) : '—' ?></strong></div>
            <div><small>Decision Note</small><strong><?= $overtimeDocument['decision_note'] ? e((string) $overtimeDocument['decision_note']) : '—' ?></strong></div>
        </div>
    </section>

    <section class="overtime-document-certification">
        <p>I certify that the information in this request is accurate and that the overtime work is subject to HR/Admin review, attendance validation, and the institution's payroll rules.</p>
    </section>

    <footer class="overtime-document-signatures">
        <div><span></span><strong>Employee Signature</strong><small><?= e((string) $overtimeDocument['employee_name']) ?></small></div>
        <div><span></span><strong>HR/Admin Signature</strong><small><?= $overtimeDocument['approver_name'] ? e((string) $overtimeDocument['approver_name']) : 'Name and signature' ?></small></div>
    </footer>

    <p class="overtime-document-footer">System-generated from synchronized employee, biometric attendance, and overtime approval records. Generated <?= e(date('F j, Y · g:i:s A')) ?>.</p>
</article>