<?php

declare(strict_types=1);

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(404);
    exit('Not found.');
}

$allowedStatuses = ['', 'Pending', 'Approved', 'Rejected', 'Cancelled'];
$statusFilter = trim((string) ($_GET['status'] ?? ''));
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$leaveRequests = [];
$leaveSummary = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
$leaveSchemaReady = true;

try {
    $summaryStmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS request_count
         FROM leave_requests
         GROUP BY status'
    );
    $summaryStmt->execute();
    foreach ($summaryStmt->fetchAll() as $summaryRow) {
        $summaryStatus = (string) ($summaryRow['status'] ?? '');
        if (array_key_exists($summaryStatus, $leaveSummary)) {
            $leaveSummary[$summaryStatus] = (int) $summaryRow['request_count'];
        }
    }

    $requestStmt = $pdo->prepare(
        'SELECT lr.*, e.employee_no, e.first_name, e.last_name,
                d.name AS department, u.full_name AS approver_name
         FROM leave_requests lr
         JOIN employees e ON e.id=lr.employee_id
         LEFT JOIN departments d ON d.id=e.department_id
         LEFT JOIN users u ON u.id=lr.approved_by
         WHERE (? = "" OR lr.status = ?)
         ORDER BY CASE lr.status WHEN "Pending" THEN 0 ELSE 1 END,
                  lr.created_at DESC, lr.id DESC
         LIMIT 250'
    );
    $requestStmt->execute([$statusFilter, $statusFilter]);
    $leaveRequests = $requestStmt->fetchAll();
} catch (Throwable) {
    $leaveSchemaReady = false;
}

$leaveBadge = static fn(string $status): string => match ($status) {
    'Approved' => 'badge-green',
    'Rejected', 'Cancelled' => 'badge-red',
    'Pending' => 'badge-amber',
    default => 'badge-gray',
};
?>
<section class="schedule-page leave-management-page">
    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('leave', 'heading-icon') ?>Leave Management</h2>
                <p class="muted">Review paid and unpaid leave requests before daily attendance is finalized.</p>
            </div>
            <?php if ($leaveSchemaReady): ?>
                <form method="get" action="app.php" class="inline-form">
                    <input type="hidden" name="page" value="leave">
                    <label>Status
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All requests</option>
                            <?php foreach (array_slice($allowedStatuses, 1) as $status): ?>
                                <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$leaveSchemaReady): ?>
            <div class="note-box">Leave Management is not installed in this database yet. Import <code>database/integrated_attendance_payroll_update.sql</code>, then reload this page.</div>
        <?php else: ?>
            <div class="metric-strip">
                <div><small>Pending review</small><strong class="text-red"><?= $leaveSummary['Pending'] ?></strong></div>
                <div><small>Approved</small><strong class="text-green"><?= $leaveSummary['Approved'] ?></strong></div>
                <div><small>Rejected</small><strong><?= $leaveSummary['Rejected'] ?></strong></div>
                <div><small>Cancelled</small><strong><?= $leaveSummary['Cancelled'] ?></strong></div>
            </div>

            <div class="note-box">Only approved leave is considered by attendance processing. A decision records the administrator and decision time; rejected or cancelled leave must not suppress an absence.</div>

            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Leave</th>
                            <th>Dates</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaveRequests as $request): ?>
                            <?php
                            $requestStatus = (string) $request['status'];
                            $leaveDays = max(1, (int) ((new DateTimeImmutable((string) $request['start_date']))
                                ->diff(new DateTimeImmutable((string) $request['end_date']))->days ?? 0) + 1);
                            ?>
                            <tr>
                                <td><strong><?= e($request['first_name'] . ' ' . $request['last_name']) ?></strong><small class="cell-subtitle"><?= e($request['employee_no']) ?> · <?= e((string) ($request['department'] ?: 'Unassigned')) ?></small></td>
                                <td><span class="badge badge-blue"><?= e((string) $request['leave_type']) ?></span><small class="cell-subtitle"><?= $leaveDays ?> day<?= $leaveDays === 1 ? '' : 's' ?></small></td>
                                <td><strong><?= e(date('M j', strtotime((string) $request['start_date']))) ?>–<?= e(date('M j, Y', strtotime((string) $request['end_date']))) ?></strong><small class="cell-subtitle">Requested <?= e(date('M j, Y', strtotime((string) $request['created_at']))) ?></small></td>
                                <td><?= nl2br(e((string) $request['reason'])) ?></td>
                                <td><span class="badge <?= e($leaveBadge($requestStatus)) ?>"><?= e($requestStatus) ?></span><?php if (!empty($request['approver_name'])): ?><small class="cell-subtitle">By <?= e((string) $request['approver_name']) ?></small><?php endif; ?></td>
                                <td>
                                    <?php if ($requestStatus === 'Pending'): ?>
                                        <form method="post" class="stack-form" data-submit-lock>
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="save_leave_decision">
                                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                            <label>Decision
                                                <select name="decision" required>
                                                    <option value="Approved">Approve</option>
                                                    <option value="Rejected">Reject</option>
                                                </select>
                                            </label>
                                            <label>Decision note<input name="decision_note" maxlength="500" placeholder="Optional review note"></label>
                                            <button class="btn btn-mini btn-primary" type="submit"><?= ui_icon('check-circle', 'button-icon') ?>Save decision</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted"><?= e((string) ($request['decision_note'] ?: 'Decision complete')) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$leaveRequests): ?><tr>
                                <td colspan="6" class="empty-state">No leave requests match this view.</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>