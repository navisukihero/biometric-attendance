<?php
$search = trim((string) ($_GET['q'] ?? ''));
$pageNo = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 8;
$where = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE CONCAT(e.first_name," ",e.middle_name," ",e.last_name," ",e.employee_no," ",e.position) LIKE ?';
    $params[] = '%' . $search . '%';
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees e {$where}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$pageNo = min($pageNo, $pages);
$offset = ($pageNo - 1) * $perPage;
$stmt = $pdo->prepare("SELECT e.*, d.name department,
    fr.fingerprint_slot AS mapped_fingerprint_slot,
    fr.mapping_status AS fingerprint_mapping_status,
    ea.account_status AS portal_account_status,
    ea.password_hash AS portal_password_hash,
    ea.reset_requested_at AS portal_reset_requested_at,
    (SELECT COUNT(*) FROM fingerprint_template_slots fts WHERE fts.employee_id=e.id AND fts.mapping_status='Enrolled') AS enrolled_template_count,
    (SELECT dc.status FROM device_commands dc WHERE dc.employee_id=e.id AND dc.command_type='ENROLL' ORDER BY dc.id DESC LIMIT 1) enrollment_command_status
    FROM employees e
    LEFT JOIN departments d ON d.id=e.department_id
    LEFT JOIN fingerprint_registrations fr ON fr.employee_id=e.id
    LEFT JOIN employee_accounts ea ON ea.employee_id=e.id
    {$where} ORDER BY e.id LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$employees = $stmt->fetchAll();
?>
<article class="panel employees-panel">
    <div class="records-toolbar">
        <h2><span class="panel-icon"><?= ui_icon('employees') ?></span> Employee<br> records</h2>
        <form class="search-form" method="get"><input type="hidden" name="page" value="employees"><span class="search-form-icon" aria-hidden="true"><?= ui_icon('search') ?></span><input name="q" value="<?= e($search) ?>" placeholder="Search employee..." aria-label="Search employees"></form>
        <a class="btn btn-primary btn-large" href="app.php?page=employee_new"><?= ui_icon('employee-add', 'button-icon') ?> Add<br>employee</a>
    </div>
    <div class="table-scroll">
        <table class="data-table employee-table">
            <colgroup>
                <col class="employee-col-name">
                <col class="employee-col-id">
                <col class="employee-col-department">
                <col class="employee-col-position">
                <col class="employee-col-employment">
                <col class="employee-col-pay-type">
                <col class="employee-col-rate">
                <col class="employee-col-monthly-salary">
                <col class="employee-col-fingerprint">
                <col class="employee-col-portal">
                <col class="employee-col-status">
                <col class="employee-col-actions">
            </colgroup>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>ID</th>
                    <th>Department</th>
                    <th>Position</th>
                    <th>Employment Type</th>
                    <th>Pay Type</th>
                    <th>Approved Rate</th>
                    <th>Expected Monthly Salary</th>
                    <th>Fingerprint</th>
                    <th>Portal</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $index => $employee): ?>
                    <tr>
                        <td>
                            <div class="employee-name-cell"><span class="avatar avatar-<?= ($employee['id'] % 4) + 1 ?>"><?= e(initials($employee['first_name'], $employee['last_name'])) ?></span><strong><?= e(substr($employee['first_name'], 0, 1) . '. ' . $employee['last_name']) ?></strong></div>
                        </td>
                        <td class="muted"><?= e($employee['employee_no']) ?></td>
                        <td><?= e($employee['department']) ?></td>
                        <td><?= e($employee['position']) ?></td>
                        <td><?= e((string) ($employee['employment_type'] ?? 'Not set')) ?></td>
                        <?php
                        $employeePayType = in_array((string) ($employee['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true)
                            ? (string) $employee['pay_type']
                            : 'Daily';
                        $approvedRate = (float) (($employee['basic_rate'] ?? 0) > 0 ? $employee['basic_rate'] : $employee['daily_rate']);
                        $scheduledMinutes = 0;
                        $scheduledDays = 0;
                        if ($approvedRate <= 0) {
                            $expectedMonthly = 0.0;
                        } else {
                            try {
                                $salaryEstimate = payroll_expected_monthly_estimate(
                                    $pdo,
                                    (int) $employee['id'],
                                    $employeePayType,
                                    $approvedRate,
                                    new DateTimeImmutable('first day of this month')
                                );
                                $expectedMonthly = (float) $salaryEstimate['amount'];
                                $scheduledMinutes = (int) $salaryEstimate['scheduled_minutes'];
                                $scheduledDays = (int) $salaryEstimate['scheduled_days'];
                            } catch (Throwable) {
                                $expectedMonthly = 0.0;
                            }
                        }
                        $rateUnit = match ($employeePayType) {
                            'Hourly' => 'per hour',
                            'Monthly' => 'per month',
                            default => 'per day',
                        };
                        $monthlyFormula = match ($employeePayType) {
                            'Hourly' => $scheduledMinutes > 0
                                ? number_format($scheduledMinutes / 60, 2) . ' scheduled hours this month'
                                : 'Assign a work schedule',
                            'Monthly' => 'Configured monthly rate; schedule controls adjustments',
                            default => $scheduledDays > 0
                                ? $scheduledDays . ' scheduled workday' . ($scheduledDays === 1 ? '' : 's') . ' this month'
                                : 'Assign a work schedule',
                        };
                        ?>
                        <td><span class="badge badge-blue"><?= e($employeePayType) ?></span></td>
                        <td><strong class="employee-approved-rate"><?= money($approvedRate) ?></strong><small class="cell-subtitle"><?= e($rateUnit) ?></small></td>
                        <td><strong class="employee-approved-rate text-green"><?= money($expectedMonthly) ?></strong><small class="cell-subtitle"><?= e($monthlyFormula) ?></small></td>
                        <?php
                        $fingerprintPending = in_array($employee['enrollment_command_status'] ?? '', ['Pending', 'Running'], true);
                        $fingerprintTemplateCount = max(0, (int) ($employee['enrolled_template_count'] ?? 0));
                        $fingerprintCanonicalEnrolled = ($employee['fingerprint_status'] ?? '') === 'Enrolled'
                            && ($employee['fingerprint_mapping_status'] ?? '') === 'Enrolled';
                        $fingerprintActive = !$fingerprintPending
                            && $fingerprintCanonicalEnrolled
                            && $fingerprintTemplateCount === 5;
                        if ($fingerprintPending) {
                            $fingerprintLabel = 'Sync ' . strtolower((string) $employee['enrollment_command_status']);
                        } elseif ($fingerprintActive) {
                            $fingerprintLabel = 'Enrolled · 5/5 · slot ' . (int) $employee['mapped_fingerprint_slot'];
                        } elseif ($fingerprintCanonicalEnrolled && $fingerprintTemplateCount < 5) {
                            $fingerprintLabel = 'Profile recovery · ' . $fingerprintTemplateCount . '/5';
                        } elseif ($fingerprintTemplateCount > 5) {
                            $fingerprintLabel = 'Profile review · ' . $fingerprintTemplateCount . ' mappings';
                        } elseif ($fingerprintTemplateCount > 0) {
                            $fingerprintLabel = 'Mapping incomplete · ' . $fingerprintTemplateCount . '/5';
                        } else {
                            $fingerprintLabel = 'Not enrolled';
                        }
                        ?>
                        <td><span class="badge <?= $fingerprintActive ? 'badge-green' : 'badge-amber' ?>"><?= e($fingerprintLabel) ?></span></td>
                        <?php
                        $portalReady = ($employee['portal_account_status'] ?? '') === 'Active'
                            && ucchr_password_hash_is_valid((string) ($employee['portal_password_hash'] ?? ''));
                        $portalLabel = !empty($employee['portal_reset_requested_at'])
                            ? 'Reset requested'
                            : ($portalReady
                                ? 'Active'
                                : (($employee['portal_account_status'] ?? '') === 'Disabled' ? 'Disabled' : 'Setup needed'));
                        ?>
                        <td><span class="badge <?= $portalReady && empty($employee['portal_reset_requested_at']) ? 'badge-green' : (($employee['portal_account_status'] ?? '') === 'Disabled' ? 'badge-gray' : 'badge-amber') ?>"><?= e($portalLabel) ?></span></td>
                        <td><span class="badge <?= $employee['status'] === 'Active' ? 'badge-green' : 'badge-gray' ?>"><?= e($employee['status']) ?></span></td>
                        <td class="actions"><a title="Edit employee" aria-label="Edit <?= e($employee['first_name'] . ' ' . $employee['last_name']) ?>" href="app.php?page=employee_edit&id=<?= (int) $employee['id'] ?>"><?= ui_icon('edit', 'action-icon') ?></a>
                            <form method="post" onsubmit="return confirm('Permanently delete this employee and their attendance, payroll, portal, schedule, and fingerprint mapping records? This cannot be undone. Fingerprints stored inside the AS608 sensor must be cleared separately.')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_employee"><input type="hidden" name="confirm_delete" value="DELETE"><input type="hidden" name="id" value="<?= (int) $employee['id'] ?>"><button title="Delete employee" aria-label="Delete <?= e($employee['first_name'] . ' ' . $employee['last_name']) ?>" type="submit"><?= ui_icon('trash', 'action-icon') ?></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$employees): ?><tr>
                        <td colspan="12" class="empty-state">No employees matched your search.</td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <footer class="pagination-row"><span>Showing <?= $total ? $offset + 1 : 0 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?> employees</span>
        <div><a class="page-arrow <?= $pageNo <= 1 ? 'disabled' : '' ?>" aria-label="Previous employee page" href="app.php?page=employees&q=<?= urlencode($search) ?>&p=<?= max(1, $pageNo - 1) ?>"><?= ui_icon('chevron-left') ?></a><strong>Page <?= $pageNo ?> of <?= $pages ?></strong><a class="page-arrow <?= $pageNo >= $pages ? 'disabled' : '' ?>" aria-label="Next employee page" href="app.php?page=employees&q=<?= urlencode($search) ?>&p=<?= min($pages, $pageNo + 1) ?>"><?= ui_icon('chevron-right') ?></a></div>
    </footer>
</article>
