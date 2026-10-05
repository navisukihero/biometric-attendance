<?php

declare(strict_types=1);

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(404);
    exit('Not found.');
}

$moduleFilter = trim((string) ($_GET['module'] ?? ''));
if ($moduleFilter !== '' && !preg_match('/^[A-Za-z0-9 _-]{1,80}$/', $moduleFilter)) {
    $moduleFilter = '';
}

$auditRows = [];
$auditModules = [];
$structuredAuditReady = true;

try {
    $moduleStmt = $pdo->prepare(
        'SELECT DISTINCT module
         FROM activity_logs
         WHERE module IS NOT NULL AND module<>""
         ORDER BY module'
    );
    $moduleStmt->execute();
    $auditModules = array_map('strval', $moduleStmt->fetchAll(PDO::FETCH_COLUMN));

    $auditStmt = $pdo->prepare(
        'SELECT al.id, al.action, al.module, al.record_id, al.description,
                al.old_values, al.new_values, al.ip_address, al.created_at,
                u.full_name, u.username
         FROM activity_logs al
         LEFT JOIN users u ON u.id=al.user_id
         WHERE (? = "" OR al.module = ?)
         ORDER BY al.created_at DESC, al.id DESC
         LIMIT 250'
    );
    $auditStmt->execute([$moduleFilter, $moduleFilter]);
    $auditRows = $auditStmt->fetchAll();
} catch (Throwable) {
    $structuredAuditReady = false;
    try {
        $legacyStmt = $pdo->prepare(
            'SELECT al.id, al.action, al.created_at, u.full_name, u.username
             FROM activity_logs al
             LEFT JOIN users u ON u.id=al.user_id
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT 250'
        );
        $legacyStmt->execute();
        $auditRows = $legacyStmt->fetchAll();
    } catch (Throwable) {
        $auditRows = [];
    }
}

$formatAuditValues = static function (mixed $raw): string {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($formatted) ? $formatted : $raw;
    }
    return $raw;
};
?>
<section class="schedule-page audit-log-page">
    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('audit', 'heading-icon') ?>Audit Logs</h2>
                <p class="muted">A review trail for sensitive employee, schedule, approval, payroll, and settings actions.</p>
            </div>
            <?php if ($structuredAuditReady): ?>
                <form method="get" action="app.php" class="inline-form">
                    <input type="hidden" name="page" value="audit_logs">
                    <label>Module
                        <select name="module" onchange="this.form.submit()">
                            <option value="">All modules</option>
                            <?php foreach ($auditModules as $module): ?>
                                <option value="<?= e($module) ?>" <?= $moduleFilter === $module ? 'selected' : '' ?>><?= e($module) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            <?php endif; ?>
        </div>

        <?php if (!$structuredAuditReady): ?>
            <div class="note-box">Showing the legacy activity history. Import <code>database/integrated_attendance_payroll_update.sql</code> to enable module, record, before/after, and IP details.</div>
        <?php else: ?>
        <?php endif; ?>

        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date &amp; time</th>
                        <th>Actor</th>
                        <th>Module / Record</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditRows as $audit): ?>
                        <?php
                        $oldValues = $formatAuditValues($audit['old_values'] ?? '');
                        $newValues = $formatAuditValues($audit['new_values'] ?? '');
                        ?>
                        <tr>
                            <td><strong><?= e(date('M j, Y', strtotime((string) $audit['created_at']))) ?></strong><small class="cell-subtitle"><?= e(date('g:i:s A', strtotime((string) $audit['created_at']))) ?></small></td>
                            <td><strong><?= e((string) ($audit['full_name'] ?: 'System')) ?></strong><small class="cell-subtitle"><?= e((string) ($audit['username'] ?? 'Automated process')) ?><?php if (!empty($audit['ip_address'])): ?> · <?= e((string) $audit['ip_address']) ?><?php endif; ?></small></td>
                            <td><span class="badge badge-blue"><?= e((string) ($audit['module'] ?? 'Legacy')) ?></span><?php if (!empty($audit['record_id'])): ?><small class="cell-subtitle">Record <?= e((string) $audit['record_id']) ?></small><?php endif; ?></td>
                            <td><strong><?= e((string) $audit['action']) ?></strong><small class="cell-subtitle"><?= e((string) ($audit['description'] ?? '')) ?></small></td>
                            <td>
                                <?php if ($oldValues !== '' || $newValues !== ''): ?>
                                    <details>
                                        <summary>View changes</summary>
                                        <?php if ($oldValues !== ''): ?><small>Before</small>
                                            <pre><?= e($oldValues) ?></pre><?php endif; ?>
                                        <?php if ($newValues !== ''): ?><small>After</small>
                                            <pre><?= e($newValues) ?></pre><?php endif; ?>
                                    </details>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$auditRows): ?><tr>
                            <td colspan="5" class="empty-state">No audit activity was found.</td>
                        </tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
