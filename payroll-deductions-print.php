<?php

declare(strict_types=1);
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require_login($pdo);
$runId = max(0, (int)($_GET['run_id'] ?? 0));
$stmt = $pdo->prepare('SELECT * FROM payroll_runs WHERE id=? LIMIT 1');
$stmt->execute([$runId]);
$run = $stmt->fetch();
if (!$run) {
    http_response_code(404);
    exit('Payroll run not found.');
}
$stmt = $pdo->prepare('SELECT pi.*,e.employee_no,e.first_name,e.last_name FROM payroll_items pi JOIN employees e ON e.id=pi.employee_id WHERE pi.payroll_run_id=? ORDER BY e.last_name,e.first_name');
$stmt->execute([$runId]);
$rows = $stmt->fetchAll();
$keys = ['late_deduction', 'undertime_deduction', 'half_day_deduction', 'absence_deduction', 'cash_advance_deduction', 'other_deductions', 'total_deductions'];
$totals = array_fill_keys($keys, 0.0);
foreach ($rows as $row) {
    foreach ($keys as $key) {
        $totals[$key] += (float)$row[$key];
    }
}
$hasLegacyOther = $totals['other_deductions'] > 0;
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Deduction Summary #<?= $runId ?></title>
    <style>
        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: #eee;
            color: #191919;
            font: 12px Arial, sans-serif
        }

        .actions,
        .sheet {
            width: min(980px, calc(100% - 24px));
            margin: 18px auto
        }

        .actions {
            display: flex;
            gap: 8px
        }

        .actions button,
        .actions a {
            padding: 10px 15px;
            border-radius: 6px;
            border: 0;
            background: #8d1530;
            color: #fff;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer
        }

        .actions a {
            background: #fff;
            color: #8d1530;
            border: 1px solid #8d1530
        }

        .sheet {
            min-height: 10.4in;
            background: #fff;
            padding: .55in;
            box-shadow: 0 12px 36px #0002
        }

        .head {
            display: flex;
            align-items: center;
            gap: 16px;
            border-bottom: 3px solid #8d1530;
            padding-bottom: 13px
        }

        .head img {
            width: 60px;
            height: 60px;
            object-fit: contain
        }

        .head h1,
        .head p {
            margin: 0
        }

        .head h1 {
            color: #8d1530
        }

        .meta {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin: 18px 0
        }

        .meta div {
            border-bottom: 1px solid #bbb;
            padding: 6px
        }

        .meta span {
            display: block;
            font-size: 9px;
            color: #666;
            text-transform: uppercase
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th,
        td {
            border: 1px solid #888;
            padding: 7px
        }

        th {
            background: #8d1530;
            color: #fff;
            font-size: 10px;
            text-transform: uppercase
        }

        .money {
            text-align: right;
            white-space: nowrap
        }

        .total td {
            background: #f2e7e9;
            font-weight: 700
        }

        .note {
            margin-top: 16px;
            padding: 12px;
            background: #f7f2f3
        }

        .sign {
            width: 280px;
            margin: 65px 0 0 auto;
            border-top: 1px solid;
            text-align: center;
            padding-top: 6px
        }

        .footer {
            margin-top: 25px;
            color: #666;
            font-size: 10px;
            display: flex;
            justify-content: space-between
        }

        .document-table-scroll {
            max-width: 100%;
            overflow-x: auto;
            overscroll-behavior-x: contain;
            -webkit-overflow-scrolling: touch
        }

        @media screen and (max-width: 700px) {

            .actions,
            .sheet {
                width: calc(100% - 16px)
            }

            .actions {
                flex-wrap: wrap
            }

            .actions button,
            .actions a {
                flex: 1 1 135px;
                min-height: 44px;
                text-align: center
            }

            .sheet {
                min-height: 0;
                padding: 20px 16px
            }

            .head {
                align-items: flex-start
            }

            .head img {
                width: 48px;
                height: 48px
            }

            .head h1 {
                font-size: 20px;
                line-height: 1.15
            }

            .meta {
                grid-template-columns: 1fr;
                gap: 8px
            }

            .document-table-scroll table {
                min-width: 760px
            }

            .sign {
                width: 100%
            }

            .footer {
                align-items: flex-start;
                flex-direction: column;
                gap: 6px
            }
        }

        @page {
            size: Letter portrait;
            margin: .35in
        }

        @media print {
            body {
                background: #fff
            }

            .actions {
                display: none
            }

            .sheet {
                width: 100%;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none
            }

            thead {
                display: table-header-group
            }

            tr {
                break-inside: avoid
            }
        }
    </style>
</head>

<body>
    <div class="actions"><button onclick="window.print()">Print / Save PDF</button><a href="app.php?page=payroll&amp;run_id=<?= $runId ?>#payroll-review">Back to Payroll Review</a></div>
    <main class="sheet">
        <header class="head"><img src="assets/images/ucclogo.jpg" alt="UCC logo">
            <div>
                <h1>Payroll Deduction Summary</h1>
                <p>Ubay Community College · Official Payroll Report</p>
            </div>
        </header>
        <section class="meta">
            <div><span>Payroll Run</span><strong>#<?= $runId ?></strong></div>
            <div><span>Payroll Period</span><strong><?= e(date('M j', strtotime($run['period_start'])) . '–' . date('j, Y', strtotime($run['period_end']))) ?></strong></div>
            <div><span>Status</span><strong><?= e($run['status']) ?></strong></div>
        </section>
        <div class="document-table-scroll" tabindex="0" aria-label="Scrollable payroll deduction table">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Late</th>
                        <th>Undertime</th>
                        <th>Absence / Unpaid Time</th>
                        <th>Cash Advance</th>
                        <?php if ($hasLegacyOther): ?><th>Legacy Other</th><?php endif; ?>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody><?php foreach ($rows as $row): ?><tr>
                            <td><strong><?= e($row['first_name'] . ' ' . $row['last_name']) ?></strong><br><small><?= e($row['employee_no']) ?></small></td>
                            <td class="money"><?= e(money($row['late_deduction'])) ?></td>
                            <td class="money"><?= e(money($row['undertime_deduction'])) ?></td>
                            <td class="money"><?= e(money((float) $row['half_day_deduction'] + (float) $row['absence_deduction'])) ?></td>
                            <td class="money"><?= e(money($row['cash_advance_deduction'])) ?></td>
                            <?php if ($hasLegacyOther): ?><td class="money"><?= e(money($row['other_deductions'])) ?></td><?php endif; ?>
                            <td class="money"><strong><?= e(money($row['total_deductions'])) ?></strong></td>
                        </tr><?php endforeach; ?><tr class="total">
                        <td>REPORT TOTALS</td>
                        <td class="money"><?= e(money($totals['late_deduction'])) ?></td>
                        <td class="money"><?= e(money($totals['undertime_deduction'])) ?></td>
                        <td class="money"><?= e(money($totals['half_day_deduction'] + $totals['absence_deduction'])) ?></td>
                        <td class="money"><?= e(money($totals['cash_advance_deduction'])) ?></td>
                        <?php if ($hasLegacyOther): ?><td class="money"><?= e(money($totals['other_deductions'])) ?></td><?php endif; ?>
                        <td class="money"><?= e(money($totals['total_deductions'])) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="note"><strong>Net Pay formula:</strong> <?= e(ucchr_payroll_net_formula()) ?>.<br><strong>Calculation source:</strong> Late, undertime, and unpaid attendance (including half-days) come from frozen schedules and processed biometric attendance. Cash Advance is a dated source entry. Historical Other deductions, if any, remain visible only on older records.</div>
        <div class="sign">Reviewed / Approved by</div>
        <footer class="footer"><span>Payroll Run #<?= $runId ?></span><span>Printed <?= e(date('M j, Y g:i A')) ?></span></footer>
    </main>
</body>

</html>
