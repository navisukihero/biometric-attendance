<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}

$employeeId = (int) $employee['employee_id'];
$leaveStmt = $pdo->prepare(
    'SELECT id, leave_type, start_date, end_date, reason, status,
            date_approved, decision_note, created_at
     FROM leave_requests
     WHERE employee_id=?
     ORDER BY created_at DESC, id DESC
     LIMIT 100'
);
$leaveStmt->execute([$employeeId]);
$leaveRequests = $leaveStmt->fetchAll();

$leaveCounts = [
    'Pending' => 0,
    'Approved' => 0,
    'Rejected' => 0,
    'Cancelled' => 0,
];
foreach ($leaveRequests as $request) {
    $status = (string) ($request['status'] ?? '');
    if (array_key_exists($status, $leaveCounts)) {
        $leaveCounts[$status]++;
    }
}

$statusBadge = static function (string $status): string {
    return match ($status) {
        'Approved' => 'badge-green',
        'Rejected' => 'badge-red',
        'Pending' => 'badge-amber',
        default => 'badge-gray',
    };
};
$inclusiveDays = static function (string $startDate, string $endDate): int {
    $start = strtotime($startDate);
    $end = strtotime($endDate);
    if ($start === false || $end === false || $end < $start) {
        return 0;
    }

    return (int) floor(($end - $start) / 86400) + 1;
};
?>
<section class="schedule-page employee-leave-page">
    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('leave', 'heading-icon') ?>Request Leave</h2>
                <p class="muted">Send a paid or unpaid leave request to HR/Admin for review.</p>
            </div>
            <span class="badge badge-blue"><?= e((string) $employee['employee_no']) ?></span>
        </div>

        <div class="metric-strip">
            <div><small>Pending</small><strong><?= $leaveCounts['Pending'] ?></strong></div>
            <div><small>Approved</small><strong class="text-green"><?= $leaveCounts['Approved'] ?></strong></div>
            <div><small>Rejected</small><strong class="text-red"><?= $leaveCounts['Rejected'] ?></strong></div>
            <div><small>Total requests</small><strong><?= count($leaveRequests) ?></strong></div>
        </div>

        <div class="note-box">Submitting a request does not automatically approve the leave. Your attendance is updated only after HR/Admin approves it.</div>

        <form method="post" action="employee-portal.php?page=leave" class="employee-form" data-submit-lock>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="submit_leave_request">
            <div class="form-grid">
                <label>Leave Type
                    <select name="leave_type" required>
                        <option value="Paid Leave">Paid Leave</option>
                        <option value="Unpaid Leave">Unpaid Leave</option>
                    </select>
                </label>
                <label>Start Date
                    <input type="date" name="start_date" min="<?= e(date('Y-m-d')) ?>" required>
                </label>
                <label>End Date
                    <input type="date" name="end_date" min="<?= e(date('Y-m-d')) ?>" required>
                </label>
                <label>Reason
                    <input type="text" name="reason" maxlength="1000" placeholder="Briefly explain your leave request" required>
                </label>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit" data-submit-label="Submitting…"><?= ui_icon('plus', 'button-icon') ?>Submit leave request</button>
            </div>
        </form>
    </article>

    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('audit', 'heading-icon') ?>My Leave History</h2>
                <p class="muted">Only requests linked to your employee account are shown.</p>
            </div>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Dates</th>
                        <th>Type</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Decision</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leaveRequests as $request): ?>
                        <?php
                        $requestStatus = (string) $request['status'];
                        $days = $inclusiveDays((string) $request['start_date'], (string) $request['end_date']);
                        ?>
                        <tr>
                            <td>
                                <strong><?= e(date('M j, Y', strtotime((string) $request['start_date']))) ?> – <?= e(date('M j, Y', strtotime((string) $request['end_date']))) ?></strong>
                                <small class="cell-subtitle"><?= $days ?> day<?= $days === 1 ? '' : 's' ?></small>
                            </td>
                            <td><?= e((string) $request['leave_type']) ?></td>
                            <td><?= e((string) $request['reason']) ?></td>
                            <td>
                                <span class="badge <?= e($statusBadge($requestStatus)) ?>"><?= e($requestStatus) ?></span>
                                <small class="cell-subtitle">Requested <?= e(date('M j, Y', strtotime((string) $request['created_at']))) ?></small>
                            </td>
                            <td>
                                <?= $request['decision_note'] ? e((string) $request['decision_note']) : '—' ?>
                                <?php if ($request['date_approved']): ?>
                                    <small class="cell-subtitle"><?= e(date('M j, Y, g:i A', strtotime((string) $request['date_approved']))) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($requestStatus === 'Pending'): ?>
                                    <form method="post" action="employee-portal.php?page=leave" onsubmit="return confirm('Cancel this pending leave request?')">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="cancel_leave_request">
                                        <input type="hidden" name="leave_request_id" value="<?= (int) $request['id'] ?>">
                                        <button class="btn btn-mini btn-outline text-red" type="submit"><?= ui_icon('x', 'button-icon') ?>Cancel</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">No action</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$leaveRequests): ?>
                        <tr>
                            <td colspan="6" class="empty-state">You have not submitted a leave request yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>