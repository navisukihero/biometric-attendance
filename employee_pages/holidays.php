<?php

declare(strict_types=1);

if (!isset($pdo, $employee) || !is_array($employee)) {
    http_response_code(404);
    exit('Not found.');
}

$currentYear = (int) date('Y');
$selectedYear = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 2000, 'max_range' => 2100],
]);
if (!is_int($selectedYear)) {
    $selectedYear = $currentYear;
}

$holidayStmt = $pdo->prepare(
    'SELECT id, holiday_name, holiday_date, holiday_type, description
     FROM holidays
     WHERE status="Active" AND holiday_date BETWEEN ? AND ?
     ORDER BY holiday_date, holiday_name'
);
$holidayStmt->execute([
    sprintf('%04d-01-01', $selectedYear),
    sprintf('%04d-12-31', $selectedYear),
]);
$holidays = $holidayStmt->fetchAll();

$regularCount = 0;
$specialCount = 0;
$upcomingCount = 0;
$today = date('Y-m-d');
foreach ($holidays as $holiday) {
    if ((string) $holiday['holiday_type'] === 'Regular Holiday') {
        $regularCount++;
    } else {
        $specialCount++;
    }
    if ((string) $holiday['holiday_date'] >= $today) {
        $upcomingCount++;
    }
}
?>
<section class="schedule-page employee-holidays-page">
    <article class="panel standard-panel">
        <div class="panel-title-row attendance-heading">
            <div>
                <h2><?= ui_icon('holiday', 'heading-icon') ?>Holiday Calendar</h2>
                <p class="muted">Active holidays configured by HR/Admin for attendance and payroll processing.</p>
            </div>
            <form method="get" action="employee-portal.php" class="inline-form">
                <input type="hidden" name="page" value="holidays">
                <input type="number" name="year" min="2000" max="2100" value="<?= $selectedYear ?>" aria-label="Holiday year">
                <button class="btn btn-outline" type="submit"><?= ui_icon('calendar', 'button-icon') ?>View year</button>
            </form>
        </div>

        <div class="metric-strip">
            <div><small>Calendar year</small><strong><?= $selectedYear ?></strong></div>
            <div><small>Regular holidays</small><strong><?= $regularCount ?></strong></div>
            <div><small>Special days</small><strong><?= $specialCount ?></strong></div>
            <div><small>Upcoming</small><strong class="text-green"><?= $upcomingCount ?></strong></div>
        </div>

        <div class="note-box">This calendar is view-only. Holiday attendance and pay treatment are calculated by the main system using the active payroll rules.</div>

        <div class="button-row employee-year-navigation">
            <a class="btn btn-outline" href="employee-portal.php?page=holidays&amp;year=<?= $selectedYear - 1 ?>"><?= ui_icon('chevron-left', 'button-icon') ?><?= $selectedYear - 1 ?></a>
            <a class="btn btn-outline" href="employee-portal.php?page=holidays&amp;year=<?= $selectedYear + 1 ?>"><?= $selectedYear + 1 ?><?= ui_icon('chevron-right', 'button-icon') ?></a>
        </div>

        <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Holiday</th>
                        <th>Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holidays as $holiday): ?>
                        <?php
                        $holidayDate = (string) $holiday['holiday_date'];
                        $isToday = $holidayDate === $today;
                        $isPast = $holidayDate < $today;
                        $isRegular = (string) $holiday['holiday_type'] === 'Regular Holiday';
                        ?>
                        <tr>
                            <td>
                                <strong><?= e(date('M j, Y', strtotime($holidayDate))) ?></strong>
                                <small class="cell-subtitle"><?= e(date('l', strtotime($holidayDate))) ?><?= $isToday ? ' · Today' : ($isPast ? ' · Past' : '') ?></small>
                            </td>
                            <td><strong><?= e((string) $holiday['holiday_name']) ?></strong></td>
                            <td><span class="badge <?= $isRegular ? 'badge-green' : 'badge-blue' ?>"><?= e((string) $holiday['holiday_type']) ?></span></td>
                            <td><?= $holiday['description'] ? e((string) $holiday['description']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$holidays): ?>
                        <tr>
                            <td colspan="4" class="empty-state">No active holidays are configured for <?= $selectedYear ?>.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>