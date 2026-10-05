<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../includes/overtime_requests.php';

$employeeId = (int) $employee['employee_id'];
$overtimeStmt = $pdo->prepare(
    'SELECT id, attendance_date, potential_minutes, requested_minutes,
            approved_minutes, status, request_source, reason,
            approval_date, decision_note, created_at,
            (SELECT full_name FROM users WHERE id=overtime_requests.approved_by) AS approver_name
     FROM overtime_requests
     WHERE employee_id=? AND request_source="Employee"
     ORDER BY attendance_date DESC, id DESC
     LIMIT 100'
);
$overtimeStmt->execute([$employeeId]);
$overtimeRequests = $overtimeStmt->fetchAll();

$eligibleStmt = $pdo->prepare(
    'SELECT a.scan_date, a.potential_overtime_minutes
     FROM attendance a
     LEFT JOIN overtime_requests overtime
       ON overtime.employee_id=a.employee_id
      AND overtime.attendance_date=a.scan_date
     WHERE a.employee_id=?
       AND a.time_out IS NOT NULL
       AND a.potential_overtime_minutes > 0
       AND NOT EXISTS (
            SELECT 1
            FROM payroll_item_attendance pia
            JOIN payroll_items pi ON pi.id=pia.payroll_item_id
            JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
            WHERE pia.attendance_id=a.id
              AND pr.status IN ("For Review", "Approved", "Finalized", "Paid", "Released")
       )
       AND (
            overtime.id IS NULL
            OR overtime.status="Cancelled"
       )
     ORDER BY a.scan_date DESC
     LIMIT 60'
);
$eligibleStmt->execute([$employeeId]);
$eligibleAttendance = $eligibleStmt->fetchAll();

$pendingCount = 0;
$approvedMinutes = 0;
foreach ($overtimeRequests as $request) {
    if ((string) $request['status'] === 'Pending') {
        $pendingCount++;
    }
    if ((string) $request['status'] === 'Approved') {
        $approvedMinutes += max(0, (int) $request['approved_minutes']);
    }
}
$availableMinutes = array_sum(array_map(
    static fn(array $attendance): int => max(0, (int) $attendance['potential_overtime_minutes']),
    $eligibleAttendance
));

$formatMinutes = static fn(int $minutes): string => ucchr_overtime_minutes_label($minutes);
$statusBadge = static function (string $status): string {
    return match ($status) {
        'Approved' => 'badge-green',
        'Rejected' => 'badge-red',
        'Pending' => 'badge-amber',
        default => 'badge-gray',
    };
};
?>
<section class="schedule-page employee-overtime-page">
    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('overtime', 'heading-icon') ?>Submit Overtime Request</h2>
                <p class="muted">Enter only the hours requested and the reason. Your account and submission time are recorded automatically.</p>
            </div>
            <span class="badge badge-blue"><?= count($eligibleAttendance) ?> available</span>
        </div>

        <div class="metric-strip">
            <div><small>Pending requests</small><strong><?= $pendingCount ?></strong></div>
            <div><small>Available to request</small><strong><?= e($formatMinutes($availableMinutes)) ?></strong></div>
            <div><small>Approved overtime</small><strong class="text-green"><?= e($formatMinutes($approvedMinutes)) ?></strong></div>
            <div><small>Total requests</small><strong><?= count($overtimeRequests) ?></strong></div>
        </div>

        <div class="note-box overtime-auto-link-note">
            <strong>Attendance verification:</strong> The system securely attaches your manual request to your newest eligible attendance record with enough recorded overtime. HR/Admin approval is required before it becomes payable.
            <?php if ($eligibleAttendance): ?>
                <span>Latest available record: <?= e(date('M j, Y', strtotime((string) $eligibleAttendance[0]['scan_date']))) ?> · up to <?= e($formatMinutes((int) $eligibleAttendance[0]['potential_overtime_minutes'])) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($eligibleAttendance): ?>
            <form method="post" action="employee-portal.php?page=overtime" class="employee-form employee-overtime-request-form" data-submit-lock>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="submit_overtime_request">
                <div class="form-grid">
                    <label>Requested Overtime Hours
                        <input type="number" name="requested_hours" min="0.02" max="24" step="0.01" inputmode="decimal" placeholder="Example: 1.5" required>
                        <small class="field-help">Use decimal hours when needed, such as 1.5 for 1 hour and 30 minutes.</small>
                    </label>
                    <label>Reason for Overtime
                        <textarea name="reason" maxlength="1000" rows="4" placeholder="Briefly describe the overtime work completed" required></textarea>
                    </label>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit" data-submit-label="Submitting…"><?= ui_icon('upload', 'button-icon') ?>Submit Overtime Request</button>
                </div>
            </form>
        <?php else: ?>
            <div class="empty-state">There is no eligible recorded overtime available for a new request. Completed biometric Time In/Time Out records will appear here automatically when overtime is recorded.</div>
        <?php endif; ?>
    </article>

    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('audit', 'heading-icon') ?>My Overtime History</h2>
                <p class="muted">Only requests linked to your employee account are shown.</p>
            </div>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Work Date</th>
                        <th>Requested Hours</th>
                        <th>Reason</th>
                        <th>Submitted</th>
                        <th>Current Status</th>
                        <th>Decision Date &amp; Time</th>
                        <th>Form</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overtimeRequests as $request): ?>
                        <?php $requestStatus = (string) $request['status']; ?>
                        <tr>
                            <td>
                                <strong><?= e(date('M j, Y', strtotime((string) $request['attendance_date']))) ?></strong>
                                <small class="cell-subtitle">Attendance-linked</small>
                            </td>
                            <td><?= e($formatMinutes((int) $request['requested_minutes'])) ?></td>
                            <td><?= $request['reason'] ? e((string) $request['reason']) : '—' ?></td>
                            <td><?= e(date('M j, Y', strtotime((string) $request['created_at']))) ?><small class="cell-subtitle"><?= e(date('g:i:s A', strtotime((string) $request['created_at']))) ?></small></td>
                            <td>
                                <span class="badge <?= e($statusBadge($requestStatus)) ?>"><?= e($requestStatus) ?></span>
                                <?php if ($requestStatus === 'Approved'): ?><small class="cell-subtitle"><?= e($formatMinutes((int) $request['approved_minutes'])) ?> approved</small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($request['approval_date']): ?>
                                    <?= e(date('M j, Y', strtotime((string) $request['approval_date']))) ?>
                                    <small class="cell-subtitle"><?= e(date('g:i:s A', strtotime((string) $request['approval_date']))) ?><?= $request['approver_name'] ? ' · ' . e((string) $request['approver_name']) : '' ?></small>
                                    <?php if ($request['decision_note']): ?><small class="cell-subtitle"><?= e((string) $request['decision_note']) ?></small><?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">Awaiting HR/Admin review</span>
                                <?php endif; ?>
                            </td>
                            <td><a class="btn btn-mini btn-outline" href="employee-overtime-print.php?id=<?= (int) $request['id'] ?>" target="_blank" rel="noopener"><?= ui_icon('print', 'button-icon') ?>View / Print</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$overtimeRequests): ?>
                        <tr>
                            <td colspan="7" class="empty-state">You have no overtime requests yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>