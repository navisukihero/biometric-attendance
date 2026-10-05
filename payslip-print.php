<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow', true);

require_login($pdo);

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$runId = filter_input(INPUT_GET, 'run_id', FILTER_VALIDATE_INT) ?: 0;
$requestedIds = $_GET['ids'] ?? [];
$itemIds = [];
if (is_array($requestedIds)) {
    foreach ($requestedIds as $requestedId) {
        $validatedId = filter_var($requestedId, FILTER_VALIDATE_INT);
        if ($validatedId !== false && $validatedId > 0) {
            $itemIds[$validatedId] = $validatedId;
        }
    }
}
$itemIds = array_slice(array_values($itemIds), 0, 200);
$selectionModeCount = (int) ($itemId > 0) + (int) ($runId > 0) + (int) ($itemIds !== []);
if ($selectionModeCount !== 1) {
    http_response_code(422);
    exit('Choose one individual payslip, selected employee payslips, or one payroll run.');
}

$parameters = [];
if ($itemId > 0) {
    $where = 'pi.id=?';
    $parameters[] = $itemId;
} elseif ($runId > 0) {
    $where = 'pi.payroll_run_id=?';
    $parameters[] = $runId;
} else {
    $where = 'pi.id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
    $parameters = $itemIds;
}
$stmt = $pdo->prepare(
    'SELECT pi.*, pr.period_start, pr.period_end, pr.status AS payroll_status,
            pr.payment_method, pr.payment_status, pr.payment_updated_at,
            e.employee_no, e.first_name, e.middle_name, e.last_name
     FROM payroll_items pi
     JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
     JOIN employees e ON e.id=pi.employee_id
     WHERE ' . $where . '
       AND pr.status IN ("Approved","Finalized","Paid","Released")
     ORDER BY e.last_name, e.first_name, e.employee_no'
);
$stmt->execute($parameters);
$rows = $stmt->fetchAll();
if (!$rows) {
    http_response_code(404);
    exit('No approved payslip is available to print.');
}

$payslips = array_map(static function (array $row): array {
    $snapshot = json_decode((string) ($row['calculation_snapshot'] ?? ''), true);
    $employeeSnapshot = is_array($snapshot) && is_array($snapshot['employee'] ?? null)
        ? $snapshot['employee']
        : [];
    $employeeName = trim((string) ($employeeSnapshot['name'] ?? ''));
    if ($employeeName === '') {
        $employeeName = trim(implode(' ', array_filter([
            (string) ($row['first_name'] ?? ''),
            (string) ($row['middle_name'] ?? ''),
            (string) ($row['last_name'] ?? ''),
        ], static fn(string $part): bool => trim($part) !== '')));
    }

    return [
        'employee' => $employeeName,
        'employee_no' => (string) ($employeeSnapshot['employee_no'] ?? $row['employee_no']),
        'period' => date('M j', strtotime((string) $row['period_start']))
            . '–' . date('j, Y', strtotime((string) $row['period_end'])),
        // These are frozen payroll_items values, not live employee rates.
        'basic_pay' => (float) ($row['regular_pay'] ?? 0),
        'overtime_pay' => (float) ($row['overtime_pay'] ?? 0),
        'other_earnings' => (float) ($row['holiday_pay'] ?? 0)
            + (float) ($row['rest_day_pay'] ?? 0)
            + (float) ($row['other_earnings'] ?? 0),
        'gross_pay' => (float) ($row['gross_pay'] ?? 0),
        'late_deduction' => (float) ($row['late_deduction'] ?? 0),
        'undertime_deduction' => (float) ($row['undertime_deduction'] ?? 0),
        'half_day_deduction' => (float) ($row['half_day_deduction'] ?? 0),
        'absence_deduction' => (float) ($row['absence_deduction'] ?? 0),
        'cash_advance_deduction' => (float) ($row['cash_advance_deduction'] ?? 0),
        'other_deductions' => (float) ($row['other_deductions'] ?? 0),
        'total_deductions' => (float) ($row['total_deductions'] ?? 0),
        'net_pay' => (float) ($row['net_pay'] ?? 0),
        'payment_method' => trim((string) ($row['payment_method'] ?? '')) ?: '—',
        'payment_status' => trim((string) ($row['payment_status'] ?? '')) ?: '—',
        'payroll_status' => (string) ($row['payroll_status'] ?? ''),
    ];
}, $rows);

$singleSlip = count($payslips) === 1;
$pages = $singleSlip ? [$payslips] : array_chunk($payslips, 8);
$autoPrint = (string) ($_GET['autoprint'] ?? '') === '1';
$documentTitle = $singleSlip ? 'Individual Payslip' : 'Payroll Payslips';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($documentTitle) ?> | UCCHR</title>
    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
        }

        body {
            background: #e7e7e7;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
        }

        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 2;
            width: min(210mm, calc(100% - 24px));
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin: 12px auto;
            padding: 10px 12px;
            border: 1px solid #bbb;
            border-radius: 9px;
            background: #fff;
            box-shadow: 0 5px 18px #0002;
        }

        .print-toolbar p {
            margin: 0;
            font-size: 13px;
        }

        .print-toolbar div {
            display: flex;
            gap: 8px;
        }

        .print-toolbar a,
        .print-toolbar button {
            min-height: 40px;
            padding: 8px 14px;
            border: 1px solid #222;
            border-radius: 7px;
            background: #fff;
            color: #111;
            font: 700 13px Arial, sans-serif;
            text-decoration: none;
            cursor: pointer;
        }

        .print-toolbar button {
            background: #111;
            color: #fff;
        }

        .sheet-stage {
            max-width: 100%;
            overflow-x: auto;
            padding: 0 12px 24px;
        }

        .a4-page {
            width: 210mm;
            height: 297mm;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            grid-template-rows: repeat(4, minmax(0, 1fr));
            gap: 2mm;
            margin: 0 auto 14px;
            padding: 8mm;
            background: #fff;
            box-shadow: 0 8px 30px #0003;
            page-break-after: always;
            break-after: page;
        }

        .a4-page:last-child {
            page-break-after: auto;
            break-after: auto;
        }

        .single-slip .print-toolbar {
            width: min(4.25in, calc(100% - 24px));
        }

        .single-slip .a4-page {
            width: 4.25in;
            height: 2.75in;
            display: block;
            padding: 0;
        }

        .single-slip .compact-payslip {
            width: 100%;
            height: 100%;
            padding: 2.1mm 2.6mm;
        }

        .compact-payslip {
            min-width: 0;
            min-height: 0;
            height: 68.75mm;
            display: flex;
            flex-direction: column;
            padding: 2.1mm 2.6mm;
            border: .28mm solid #111;
            background: #fff;
            color: #111;
            font-size: 6.7pt;
            line-height: 1.08;
            font-variant-numeric: tabular-nums;
            overflow: hidden;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .slip-heading {
            padding-bottom: 1.2mm;
            border-bottom: .28mm solid #111;
            text-align: center;
        }

        .slip-heading strong,
        .slip-heading span {
            display: block;
        }

        .slip-heading strong {
            font-size: 8.6pt;
            letter-spacing: .025em;
        }

        .slip-heading span {
            margin-top: .45mm;
            font-size: 6.6pt;
            font-weight: 700;
            letter-spacing: .11em;
        }

        .slip-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .5mm 2mm;
            padding: 1.2mm 0 .8mm;
            border-bottom: .18mm solid #777;
        }

        .slip-meta div:first-child {
            grid-column: 1 / -1;
        }

        .slip-meta span {
            color: #333;
            font-size: 5.8pt;
            text-transform: uppercase;
        }

        .slip-meta strong {
            display: block;
            margin-top: .2mm;
            overflow-wrap: anywhere;
            font-size: 6.8pt;
        }

        .slip-section-title {
            margin: .7mm 0 .25mm;
            font-size: 5.7pt;
            font-weight: 800;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .money-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 2mm;
            min-height: 2.7mm;
            padding: .18mm 0;
        }

        .money-row span:first-child {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .money-row strong {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .money-row.total {
            margin-top: .35mm;
            padding-top: .55mm;
            border-top: .18mm solid #555;
            font-weight: 800;
        }

        .net-pay {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 2mm;
            margin-top: .75mm;
            padding: .9mm 1mm;
            border: .35mm double #111;
            font-size: 8.2pt;
            font-weight: 900;
        }

        .slip-footer {
            display: flex;
            justify-content: space-between;
            gap: 2mm;
            margin-top: auto;
            padding-top: .75mm;
            border-top: .18mm solid #777;
            font-size: 5.8pt;
        }

        .slip-footer strong {
            white-space: nowrap;
        }

        @page {
            size: <?= $singleSlip ? '4.25in 2.75in' : 'A4 portrait' ?>;
            margin: 0;

            /* Keep browser-generated date/title/URL/page counters off the slip. */
            @top-left {
                content: "";
            }

            @top-center {
                content: "";
            }

            @top-right {
                content: "";
            }

            @bottom-left {
                content: "";
            }

            @bottom-center {
                content: "";
            }

            @bottom-right {
                content: "";
            }
        }

        @media print {

            html,
            body {
                width: <?= $singleSlip ? '4.25in' : '210mm' ?>;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible;
                background: #fff !important;
                color: #000;
            }

            .print-toolbar {
                display: none;
            }

            .sheet-stage {
                overflow: visible;
                padding: 0;
            }

            .a4-page {
                margin: 0;
                box-shadow: none;
            }

            .single-slip .sheet-stage,
            .single-slip .a4-page {
                width: 4.25in;
                height: 2.75in;
            }

            .single-slip .a4-page {
                page-break-after: auto;
                break-after: auto;
            }
        }

        @media screen and (max-width: 700px) {
            .print-toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .print-toolbar div {
                display: grid;
                grid-template-columns: 1fr 1fr;
            }

            .print-toolbar a,
            .print-toolbar button {
                text-align: center;
            }
        }
    </style>
</head>

<body class="<?= $singleSlip ? 'single-slip' : 'bulk-slips' ?>">
    <nav class="print-toolbar" aria-label="Payslip printing controls">
        <p><strong><?= count($payslips) ?></strong> compact payslip<?= count($payslips) === 1 ? '' : 's' ?> · <?= $singleSlip ? '1/8 Letter paper (4.25 × 2.75 in)' : 'A4 paper, up to 8 per page' ?></p>
        <div><a href="app.php?page=payslips">Back</a><button type="button" onclick="printCleanPayslips()">Print</button></div>
    </nav>
    <main class="sheet-stage">
        <?php foreach ($pages as $page): ?>
            <section class="a4-page" aria-label="<?= $singleSlip ? 'One-eighth Letter individual payslip' : 'A4 payslip sheet' ?>">
                <?php foreach ($page as $slip): ?>
                    <article class="compact-payslip">
                        <header class="slip-heading"><strong>UBAY COMMUNITY COLLEGE</strong><span>EMPLOYEE PAYSLIP</span></header>
                        <section class="slip-meta">
                            <div><span>Employee</span><strong><?= e($slip['employee']) ?></strong></div>
                            <div><span>Employee ID</span><strong><?= e($slip['employee_no']) ?></strong></div>
                            <div><span>Period</span><strong><?= e($slip['period']) ?></strong></div>
                        </section>

                        <div class="slip-section-title">Earnings</div>
                        <div class="money-row"><span>Basic Pay</span><strong><?= e(money($slip['basic_pay'])) ?></strong></div>
                        <div class="money-row"><span>Approved OT</span><strong><?= e(money($slip['overtime_pay'])) ?></strong></div>
                        <?php if ($slip['other_earnings'] > 0): ?><div class="money-row"><span>Other Earnings</span><strong><?= e(money($slip['other_earnings'])) ?></strong></div><?php endif; ?>
                        <div class="money-row total"><span>Gross Pay</span><strong><?= e(money($slip['gross_pay'])) ?></strong></div>

                        <div class="slip-section-title">Deductions</div>
                        <div class="money-row"><span>Late</span><strong><?= e(money($slip['late_deduction'])) ?></strong></div>
                        <div class="money-row"><span>Undertime</span><strong><?= e(money($slip['undertime_deduction'])) ?></strong></div>
                        <?php if ($slip['half_day_deduction'] + $slip['absence_deduction'] > 0): ?><div class="money-row"><span>Absence / Unpaid Time</span><strong><?= e(money($slip['half_day_deduction'] + $slip['absence_deduction'])) ?></strong></div><?php endif; ?>
                        <div class="money-row"><span>Cash Advance</span><strong><?= e(money($slip['cash_advance_deduction'])) ?></strong></div>
                        <?php if ($slip['other_deductions'] > 0): ?><div class="money-row"><span>Legacy Other</span><strong><?= e(money($slip['other_deductions'])) ?></strong></div><?php endif; ?>
                        <div class="money-row total"><span>Total Deductions</span><strong><?= e(money($slip['total_deductions'])) ?></strong></div>

                        <div class="net-pay"><span>NET PAY</span><strong><?= e(money($slip['net_pay'])) ?></strong></div>
                        <footer class="slip-footer"><span>Payment: <strong><?= e($slip['payment_method']) ?></strong></span><span>Status: <strong><?= e($slip['payment_status']) ?></strong></span></footer>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </main>
    <script>
        function printCleanPayslips() {
            const originalTitle = document.title;
            document.title = '\u200B';
            window.addEventListener('afterprint', () => {
                document.title = originalTitle;
            }, {
                once: true
            });
            window.print();
        }
        <?php if ($autoPrint): ?>window.addEventListener('load', printCleanPayslips, {
            once: true
        });
        <?php endif; ?>
    </script>
</body>

</html>
