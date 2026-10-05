<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/../includes/employee_dtr.php';

$range = employee_dtr_resolve_range(
    isset($_GET['start']) ? (string) $_GET['start'] : null,
    isset($_GET['end']) ? (string) $_GET['end'] : null
);
$rangeError = (string) $range['error'];
$dtr = $rangeError === ''
    ? employee_dtr_build($pdo, $employee, $range['start'], $range['end'])
    : null;
$startValue = $range['start']->format('Y-m-d');
$endValue = $range['end']->format('Y-m-d');
$printUrl = 'employee-dtr-print.php?start=' . rawurlencode($startValue)
    . '&end=' . rawurlencode($endValue);
?>
<section class="dtr-page">
    <aside class="panel dtr-controls-panel">
        <div class="dtr-controls-heading">
            <span class="panel-icon"><?= ui_icon('clock') ?></span>
            <div>
                <h2>Daily Time Record</h2>
                <p>Attendance &amp; Logs</p>
            </div>
        </div>

        <?php if ($rangeError !== ''): ?>
            <div class="login-error dtr-range-error" role="alert"><?= e($rangeError) ?></div>
        <?php endif; ?>

        <form class="dtr-range-form" method="get" action="employee-portal.php">
            <input type="hidden" name="page" value="dtr">
            <label>Range Start
                <input type="date" name="start" value="<?= e($startValue) ?>" required>
            </label>
            <label>Range End
                <input type="date" name="end" value="<?= e($endValue) ?>" required>
            </label>
            <button class="btn btn-primary btn-large" type="submit"><?= ui_icon('refresh', 'button-icon') ?>Generate DTR</button>
        </form>

        <div class="dtr-output-actions">
            <?php if ($dtr): ?>
                <a class="btn btn-outline" href="<?= e($printUrl) ?>" target="_blank" rel="noopener"><?= ui_icon('print', 'button-icon') ?>Print DTR</a>
            <?php else: ?>
                <span class="btn btn-outline is-disabled" aria-disabled="true"><?= ui_icon('print', 'button-icon') ?>Print DTR</span>
            <?php endif; ?>
        </div>

        <div class="dtr-system-note">
            <strong><span aria-hidden="true"></span>System status</strong>
            <p>Your DTR is calculated from official biometric attendance records. This page is view-only; contact HR/Admin if you find a discrepancy.</p>
        </div>
    </aside>

    <section class="panel dtr-preview-panel">
        <div class="dtr-preview-heading">
            <div><small>Selected period</small><strong><?= e($startValue) ?> – <?= e($endValue) ?></strong></div>
            <span class="badge badge-green"><?= ui_icon('shield') ?>System record</span>
        </div>
        <?php if ($dtr): ?>
            <div class="dtr-document-scroll">
                <?php require __DIR__ . '/../includes/employee_dtr_document.php'; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">Correct the date range, then select Generate DTR.</div>
        <?php endif; ?>
    </section>
</section>