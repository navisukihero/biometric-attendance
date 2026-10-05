<?php

declare(strict_types=1);

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(404);
    exit('Not found.');
}

$holidays = [];
$editingHoliday = null;
$holidaySchemaReady = true;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;

try {
    if ($editId > 0) {
        $editStmt = $pdo->prepare('SELECT * FROM holidays WHERE id=? LIMIT 1');
        $editStmt->execute([$editId]);
        $editingHoliday = $editStmt->fetch() ?: null;
    }

    $holidayStmt = $pdo->prepare(
        'SELECT h.*, u.full_name AS creator_name
         FROM holidays h
         LEFT JOIN users u ON u.id=h.created_by
         ORDER BY CASE WHEN h.holiday_date >= CURDATE() THEN 0 ELSE 1 END,
                  CASE WHEN h.holiday_date >= CURDATE() THEN h.holiday_date END ASC,
                  h.holiday_date DESC
         LIMIT 250'
    );
    $holidayStmt->execute();
    $holidays = $holidayStmt->fetchAll();
} catch (Throwable) {
    $holidaySchemaReady = false;
}

$activeHolidayCount = count(array_filter(
    $holidays,
    static fn(array $holiday): bool => (string) $holiday['status'] === 'Active'
));
$upcomingHolidayCount = count(array_filter(
    $holidays,
    static fn(array $holiday): bool => (string) $holiday['status'] === 'Active'
        && (string) $holiday['holiday_date'] >= date('Y-m-d')
));
?>
<section class="two-column holiday-management-page">
    <article class="panel standard-panel">
        <div class="panel-title-row">
            <div>
                <h2><?= ui_icon('holiday', 'heading-icon') ?><?= $editingHoliday ? 'Edit Holiday' : 'Add Holiday' ?></h2>
                <p class="muted">Maintain dates used by attendance classification and payroll policy.</p>
            </div>
            <?php if ($editingHoliday): ?><a class="btn btn-outline" href="app.php?page=holidays"><?= ui_icon('x', 'button-icon') ?>Cancel edit</a><?php endif; ?>
        </div>

        <?php if (!$holidaySchemaReady): ?>
            <div class="note-box">Holiday Management is not installed in this database yet. Import <code>database/integrated_attendance_payroll_update.sql</code>, then reload this page.</div>
        <?php else: ?>
            <form method="post" class="stack-form" data-submit-lock>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_holiday">
                <input type="hidden" name="id" value="<?= (int) ($editingHoliday['id'] ?? 0) ?>">
                <label>Holiday name<input name="holiday_name" maxlength="160" value="<?= e((string) ($editingHoliday['holiday_name'] ?? '')) ?>" required></label>
                <label>Holiday date<input type="date" name="holiday_date" value="<?= e((string) ($editingHoliday['holiday_date'] ?? '')) ?>" required></label>
                <label>Holiday type
                    <select name="holiday_type" required>
                        <?php foreach (['Regular Holiday', 'Special Non-Working Day'] as $holidayType): ?>
                            <option value="<?= e($holidayType) ?>" <?= (string) ($editingHoliday['holiday_type'] ?? '') === $holidayType ? 'selected' : '' ?>><?= e($holidayType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Description<textarea name="description" maxlength="500" rows="4" placeholder="Optional policy or observance note"><?= e((string) ($editingHoliday['description'] ?? '')) ?></textarea></label>
                <label>Status
                    <select name="status" required>
                        <?php foreach (['Active', 'Inactive'] as $holidayStatus): ?>
                            <option value="<?= e($holidayStatus) ?>" <?= (string) ($editingHoliday['status'] ?? 'Active') === $holidayStatus ? 'selected' : '' ?>><?= e($holidayStatus) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-primary btn-large" type="submit"><?= ui_icon($editingHoliday ? 'save' : 'plus', 'button-icon') ?><?= $editingHoliday ? 'Save holiday changes' : 'Add holiday' ?></button>
            </form>
            <div class="note-box">Only Active holidays affect attendance. Multipliers are configured separately in Payroll Settings.</div>
        <?php endif; ?>
    </article>

    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('holiday', 'heading-icon') ?>Holiday Calendar</h2>
                <p class="muted">Upcoming dates appear first.</p>
            </div>
            <?php if ($holidaySchemaReady): ?><span class="badge badge-blue"><?= $upcomingHolidayCount ?> upcoming</span><?php endif; ?>
        </div>

        <?php if ($holidaySchemaReady): ?>
            <div class="metric-strip">
                <div><small>Calendar entries</small><strong><?= count($holidays) ?></strong></div>
                <div><small>Active rules</small><strong class="text-green"><?= $activeHolidayCount ?></strong></div>
                <div><small>Upcoming</small><strong><?= $upcomingHolidayCount ?></strong></div>
                <div><small>Today</small><strong><?= count(array_filter($holidays, static fn(array $holiday): bool => (string) $holiday['holiday_date'] === date('Y-m-d') && (string) $holiday['status'] === 'Active')) ?></strong></div>
            </div>

            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Holiday</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holidays as $holiday): ?>
                            <tr>
                                <td><strong><?= e(date('M j, Y', strtotime((string) $holiday['holiday_date']))) ?></strong><small class="cell-subtitle"><?= e(date('l', strtotime((string) $holiday['holiday_date']))) ?></small></td>
                                <td><strong><?= e((string) $holiday['holiday_name']) ?></strong><?php if (!empty($holiday['description'])): ?><small class="cell-subtitle"><?= e((string) $holiday['description']) ?></small><?php endif; ?></td>
                                <td><span class="badge badge-blue"><?= e((string) $holiday['holiday_type']) ?></span></td>
                                <td><span class="badge <?= (string) $holiday['status'] === 'Active' ? 'badge-green' : 'badge-gray' ?>"><?= e((string) $holiday['status']) ?></span></td>
                                <td class="actions holiday-actions">
                                    <a class="btn btn-mini btn-outline holiday-action-btn" href="app.php?page=holidays&amp;edit=<?= (int) $holiday['id'] ?>"><?= ui_icon('edit', 'button-icon') ?>Edit</a>
                                    <form method="post" data-submit-lock onsubmit="return confirm('Delete this holiday? Existing finalized records will remain protected.')">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_holiday">
                                        <input type="hidden" name="id" value="<?= (int) $holiday['id'] ?>">
                                        <button class="btn btn-mini btn-outline holiday-action-btn" type="submit"><?= ui_icon('trash', 'button-icon') ?>Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$holidays): ?><tr>
                                <td colspan="5" class="empty-state">No holidays have been configured.</td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>