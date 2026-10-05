<?php
declare(strict_types=1);

// Local visual-test fixture. No database or real accounts. Not served by the
// application router. Run only on loopback: php -S 127.0.0.1:8082 tests/mobile_preview_router.php
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($path) && preg_match('~^/assets/(css|js|images)/[a-zA-Z0-9_.-]+\.(css|js|jpg|png)$~D', $path)) {
    $asset = $root . $path;
    if (is_file($asset)) {
        $mime = ['css' => 'text/css', 'js' => 'application/javascript', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
        header('Content-Type: ' . $mime[pathinfo($asset, PATHINFO_EXTENSION)]);
        readfile($asset);
        exit;
    }
}
if ($path !== '/') {
    http_response_code(404);
    exit;
}
require $root . '/includes/functions.php';
require $root . '/includes/employee_layout.php';
render_employee_header(['first_name' => 'Preview', 'last_name' => 'Employee', 'employee_no' => 'UI-TEST'], 'attendance');
?>
<section class="employee-dashboard" aria-label="Employee dashboard card preview">
    <div class="stats-grid employee-summary-cards">
        <article class="stat-card"><small>Attendance status</small><strong>Present</strong><span>Completed schedule</span></article>
        <article class="stat-card"><small>Late this month</small><strong>45 min</strong><span>From processed logs</span></article>
        <article class="stat-card"><small>Approved overtime</small><strong>2h 30m</strong><span>Approved requests only</span></article>
        <article class="stat-card"><small>Latest net pay</small><strong>₱19,500.00</strong><span>Released payslip</span></article>
    </div>
</section>
<section class="panel standard-panel">
    <div class="panel-title-row"><h2>Mobile layout test</h2><button class="btn btn-primary" type="button">Preview action</button></div>
    <p>Fictional presentation-only data. No database is connected.</p>
    <div class="form-grid">
        <label>Range start<input type="date" value="2026-09-01"></label>
        <label>Range end<input type="date" value="2026-09-15"></label>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th>Date / Day</th><th>Expected In</th><th>Expected Out</th><th>Actual In</th><th>Actual Out</th><th>Late</th><th>Status</th></tr></thead>
            <tbody><tr><td>September 1, 2026<small>Tuesday</small></td><td>8:00 AM</td><td>5:00 PM</td><td>7:55 AM</td><td>5:01 PM</td><td>0 minutes</td><td><span class="badge badge-green">Present</span></td></tr></tbody>
        </table>
    </div>
    <div class="table-scroll">
        <table class="data-table employees-table">
            <thead><tr><th>Employee</th><th>Department</th><th>Approved Rate</th><th>Pay Type</th><th>Actions</th></tr></thead>
            <tbody><tr><td>Preview Employee<small>UI-TEST</small></td><td>Preview department</td><td>₱650.00</td><td>Daily</td><td><div class="button-row"><button class="btn btn-outline" type="button">View</button><button class="btn btn-primary" type="button">Edit</button></div></td></tr></tbody>
        </table>
    </div>
</section>
<section class="panel standard-panel is-part-time-schedule">
    <h2>Work schedule preview</h2>
    <div class="table-scroll">
        <table class="data-table weekly-schedule-table">
            <thead><tr><th>Day</th><th>Work / Rest</th><th>Schedule configuration</th><th>Required Hours</th></tr></thead>
            <tbody><tr>
                <td><strong>Monday</strong></td>
                <td><label class="workday-switch"><input type="checkbox" checked><span>Work Day</span></label></td>
                <td class="schedule-config-cell"><div class="part-time-period-config"><div class="part-time-period-row">
                    <span>Period 1</span>
                    <label>From<input type="time" value="09:00"></label>
                    <label>To<input type="time" value="13:00"></label>
                    <button class="btn btn-mini btn-outline" type="button" disabled>Remove</button>
                </div></div></td>
                <td><strong>4h</strong></td>
            </tr></tbody>
            <tfoot><tr><td colspan="3"><strong>Total weekly required hours</strong></td><td><strong>4h</strong></td></tr></tfoot>
        </table>
    </div>
</section>
<section class="panel standard-panel" aria-label="Schedule filter preview">
    <h2>Schedule filters preview</h2>
    <div class="schedule-filters">
        <label>Employee<select><option>Preview Employee</option></select></label>
        <label>Employment Type<select><option>Part-Time</option></select></label>
        <label>Pay Type<select><option>Hourly</option></select></label>
        <label>Status<select><option>Assigned</option></select></label>
        <button class="btn btn-outline" type="button">Filter</button>
    </div>
</section>
<section class="payroll-hub" aria-label="Payroll layout preview">
    <article class="panel payroll-hero">
        <div class="payroll-hero-copy"><span class="payroll-eyebrow">Payroll workspace</span><h2>Run Payroll</h2></div>
        <div class="payroll-hero-actions"><button class="btn btn-primary" type="button">Generate Payroll</button><button class="btn btn-outline" type="button">Payslips</button></div>
    </article>
    <div class="payroll-kpi-grid">
        <article class="payroll-kpi payroll-kpi-primary"><small>Total net payroll</small><strong>₱123,456.00</strong><p>Fictional amount</p></article>
        <article class="payroll-kpi payroll-kpi-paid"><small>Paid payroll</small><strong>₱98,765.00</strong><p>Fictional amount</p></article>
        <article class="payroll-kpi payroll-kpi-pending"><small>Pending payroll</small><strong>₱24,691.00</strong><p>Fictional amount</p></article>
        <article class="payroll-kpi payroll-kpi-runs"><small>Payroll runs</small><strong>4</strong><p>Fictional count</p></article>
    </div>
    <div class="payroll-generator-grid">
        <article class="panel standard-panel payroll-generator-panel">
            <div class="panel-title-row"><h2>Generate Employee Payroll</h2><span class="badge badge-blue">Step 1 · Employee &amp; period</span></div>
            <div class="payroll-preview-form"><label>Employee<select><option>Preview Employee</option></select></label><label>Period Start<input type="date" value="2026-09-01"></label><label>Period End<input type="date" value="2026-09-15"></label><button class="btn btn-primary" type="button">Validate Payroll</button></div>
            <div class="payroll-auto-grid"><label>Approved Rate<input value="₱300/hour" readonly></label><label>Eligible Hours<input value="34 hours" readonly></label><label>Basic Pay<input value="₱10,200" readonly></label></div>
        </article>
        <aside class="panel payroll-process-panel"><h2>Payroll process</h2><p>Fictional preview of the responsive side panel.</p></aside>
    </div>
</section>
<?php render_employee_footer(); ?>
