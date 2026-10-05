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

$overtimeRequests = [];
$overtimeSummary = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Cancelled' => 0];
$overtimeSchemaReady = true;

try {
    $summaryStmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS request_count
         FROM overtime_requests
         WHERE request_source="Employee"
         GROUP BY status'
    );
    $summaryStmt->execute();
    foreach ($summaryStmt->fetchAll() as $summaryRow) {
        $summaryStatus = (string) ($summaryRow['status'] ?? '');
        if (array_key_exists($summaryStatus, $overtimeSummary)) {
            $overtimeSummary[$summaryStatus] = (int) $summaryRow['request_count'];
        }
    }

    $requestStmt = $pdo->prepare(
        'SELECT ot.*, e.employee_no, e.first_name, e.last_name,
                d.name AS department, u.full_name AS approver_name
         FROM overtime_requests ot
         JOIN employees e ON e.id=ot.employee_id
         LEFT JOIN departments d ON d.id=e.department_id
         LEFT JOIN users u ON u.id=ot.approved_by
         WHERE ot.request_source="Employee"
           AND (? = "" OR ot.status = ?)
         ORDER BY CASE ot.status WHEN "Pending" THEN 0 ELSE 1 END,
                  ot.attendance_date DESC, ot.id DESC
         LIMIT 250'
    );
    $requestStmt->execute([$statusFilter, $statusFilter]);
    $overtimeRequests = $requestStmt->fetchAll();
} catch (Throwable) {
    $overtimeSchemaReady = false;
}

$overtimeBadge = static fn(string $status): string => match ($status) {
    'Approved' => 'badge-green',
    'Rejected', 'Cancelled' => 'badge-red',
    'Pending' => 'badge-amber',
    default => 'badge-gray',
};
$minutesLabel = static function (int $minutes): string {
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;
    return $hours > 0
        ? $hours . 'h' . ($remainder > 0 ? ' ' . $remainder . 'm' : '')
        : $remainder . 'm';
};
?>
<section class="schedule-page overtime-approval-page">
    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('overtime', 'heading-icon') ?>Overtime Approvals</h2>
                <p class="muted">Review employee requests, open the official form, then approve or reject the attendance-linked overtime.</p>
            </div>
            <?php if ($overtimeSchemaReady): ?>
                <form method="get" action="app.php" class="inline-form">
                    <input type="hidden" name="page" value="overtime">
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

        <?php if (!$overtimeSchemaReady): ?>
            <div class="note-box">Overtime approvals are not installed in this database yet. Import <code>database/integrated_attendance_payroll_update.sql</code>, then reload this page.</div>
        <?php else: ?>
            <div class="metric-strip">
                <div><small>Pending review</small><strong class="text-red"><?= $overtimeSummary['Pending'] ?></strong></div>
                <div><small>Approved</small><strong class="text-green"><?= $overtimeSummary['Approved'] ?></strong></div>
                <div><small>Rejected</small><strong><?= $overtimeSummary['Rejected'] ?></strong></div>
                <div><small>Cancelled</small><strong><?= $overtimeSummary['Cancelled'] ?></strong></div>
            </div>

            <div class="note-box"><strong>Approval control:</strong> approved minutes cannot exceed the potential/requested minutes. Payroll must use Approved OT only, never the potential amount.</div>

            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Work Date</th>
                            <th>Potential</th>
                            <th>Employee Request</th>
                            <th>Approved</th>
                            <th>Status &amp; Decision Time</th>
                            <th>Decision</th>
                            <th>Official Form</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($overtimeRequests as $request): ?>
                            <?php
                            $requestStatus = (string) $request['status'];
                            $potentialMinutes = max(0, (int) $request['potential_minutes']);
                            $requestedMinutes = max(0, (int) $request['requested_minutes']);
                            $approvalLimit = max(0, min($potentialMinutes, $requestedMinutes > 0 ? $requestedMinutes : $potentialMinutes));
                            ?>
                            <tr>
                                <td><strong><?= e($request['first_name'] . ' ' . $request['last_name']) ?></strong><small class="cell-subtitle"><?= e($request['employee_no']) ?> · <?= e((string) ($request['department'] ?: 'Unassigned')) ?></small></td>
                                <td><strong><?= e(date('M j, Y', strtotime((string) $request['attendance_date']))) ?></strong><small class="cell-subtitle"><?= e((string) $request['request_source']) ?> request</small></td>
                                <td><?= e($minutesLabel($potentialMinutes)) ?></td>
                                <td>
                                    <strong><?= e($minutesLabel($requestedMinutes)) ?></strong>
                                    <?php if (!empty($request['reason'])): ?><small class="cell-subtitle"><?= e((string) $request['reason']) ?></small><?php endif; ?>
                                    <small class="cell-subtitle">Submitted <?= e(date('M j, Y, g:i:s A', strtotime((string) $request['created_at']))) ?></small>
                                </td>
                                <td class="text-green"><?= e($minutesLabel((int) $request['approved_minutes'])) ?></td>
                                <td>
                                    <span class="badge <?= e($overtimeBadge($requestStatus)) ?>"><?= e($requestStatus) ?></span>
                                    <?php if (!empty($request['approval_date'])): ?><small class="cell-subtitle"><?= e(date('M j, Y, g:i:s A', strtotime((string) $request['approval_date']))) ?></small><?php else: ?><small class="cell-subtitle">Awaiting review</small><?php endif; ?>
                                    <?php if (!empty($request['approver_name'])): ?><small class="cell-subtitle">By <?= e((string) $request['approver_name']) ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($requestStatus === 'Pending'): ?>
                                        <form method="post" class="stack-form" data-submit-lock>
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="save_overtime_decision">
                                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                            <label>Decision
                                                <select name="decision" required>
                                                    <option value="Approved">Approve</option>
                                                    <option value="Rejected">Reject</option>
                                                </select>
                                            </label>
                                            <label>Approved minutes<input type="number" name="approved_minutes" min="0" max="<?= $approvalLimit ?>" value="<?= $approvalLimit ?>" required></label>
                                            <label>Decision note<input name="decision_note" maxlength="500" placeholder="Optional review note"></label>
                                            <button class="btn btn-mini btn-primary" type="submit"><?= ui_icon('check-circle', 'button-icon') ?>Save decision</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted"><?= e((string) ($request['decision_note'] ?: 'Decision complete')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><a class="btn btn-mini btn-outline" href="overtime-request-print.php?id=<?= (int) $request['id'] ?>" target="_blank" rel="noopener"><?= ui_icon('print', 'button-icon') ?>View / Print</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$overtimeRequests): ?><tr>
                                <td colspan="8" class="empty-state">No overtime requests match this view.</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>