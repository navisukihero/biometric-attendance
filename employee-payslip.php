<?php

declare(strict_types=1);

define('UCCHR_SESSION_NAME', 'UCCHR_EMPLOYEE_SESSION');
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/employee_auth.php';

$employee = employee_require_login($pdo);
$payslipId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT pi.*, pr.period_start, pr.period_end, pr.processed_at, pr.status AS payroll_status,
            pr.payment_method, pr.payment_status, pr.payment_updated_at,
            e.employee_no, e.first_name, e.middle_name, e.last_name,
            e.position, d.name AS department
     FROM payroll_items pi
     JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
     JOIN employees e ON e.id=pi.employee_id
     LEFT JOIN departments d ON d.id=e.department_id
     WHERE pi.id=? AND pi.employee_id=? AND pr.status IN ("Approved", "Finalized", "Paid", "Released")
     LIMIT 1'
);
$stmt->execute([$payslipId, (int) $employee['employee_id']]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit('Pay slip not found.');
}
$snapshot = json_decode((string) ($row['calculation_snapshot'] ?? ''), true);
$snapshotEmployee = is_array($snapshot) && is_array($snapshot['employee'] ?? null) ? $snapshot['employee'] : [];
$fullName = (string) ($snapshotEmployee['name'] ?? trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']));
$department = (string) ($snapshotEmployee['department'] ?? ($row['department'] ?: '—'));
$position = (string) ($snapshotEmployee['position'] ?? $row['position']);
$payType = in_array((string) ($row['pay_type'] ?? ''), ['Daily', 'Hourly', 'Monthly'], true)
    ? (string) $row['pay_type']
    : 'Daily';
$rateUnit = $payType === 'Hourly' ? 'hour' : ($payType === 'Monthly' ? 'month' : 'day');
$salaryFormula = ucchr_payroll_basic_salary_formula($row);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pay Slip <?= e($row['employee_no']) ?> | UCCHR</title>
    <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
    <style>
        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: #eee9e9;
            color: #201719;
            font: 15px Arial, sans-serif
        }

        .sheet {
            width: min(780px, calc(100% - 28px));
            margin: 28px auto;
            background: #fff;
            padding: 38px;
            box-shadow: 0 12px 45px #40101a26
        }

        .head {
            display: flex;
            align-items: center;
            gap: 18px;
            border-bottom: 4px solid #a8172d;
            padding-bottom: 18px
        }

        .head img {
            width: 72px;
            height: 72px;
            object-fit: contain
        }

        .head h1,
        .head p {
            margin: 0
        }

        .head h1 {
            color: #a8172d
        }

        .meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 28px;
            margin: 24px 0
        }

        .meta div,
        .line {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 1px solid #ddd;
            padding: 10px 0
        }

        .pay-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px
        }

        .pay-grid h2 {
            font-size: 16px;
            color: #a8172d
        }

        .net {
            margin-top: 24px;
            padding: 18px;
            background: #f4e9eb;
            display: flex;
            justify-content: space-between;
            font-size: 20px
        }

        .net-calculation {
            margin: 10px 0 0;
            padding: 12px 14px;
            border: 1px solid #dfc7cd;
            border-radius: 8px;
            background: #fffafa;
            color: #514247;
            font-size: 12px;
            line-height: 1.55
        }

        .actions {
            display: flex;
            gap: 10px;
            margin: 24px auto;
            width: min(780px, calc(100% - 28px))
        }

        .btn {
            border: 0;
            border-radius: 7px;
            padding: 12px 18px;
            background: #a8172d;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none
        }

        .btn.secondary {
            background: #fff;
            color: #a8172d;
            border: 1px solid #a8172d
        }

        @media screen and (max-width:600px) {
            .sheet {
                width: calc(100% - 16px);
                margin: 14px auto;
                padding: 22px 16px
            }

            .meta,
            .pay-grid {
                grid-template-columns: 1fr
            }

            .actions {
                width: calc(100% - 16px);
                align-items: stretch;
                flex-direction: column
            }

            .actions .btn {
                min-height: 44px;
                text-align: center
            }

            .head {
                align-items: flex-start;
                gap: 12px
            }

            .head img {
                width: 56px;
                height: 56px
            }

            .head h1 {
                font-size: 23px;
                line-height: 1.15
            }

            .meta div,
            .line,
            .net {
                align-items: flex-start;
                flex-wrap: wrap
            }

            .meta strong,
            .line strong,
            .net strong {
                margin-left: auto;
                overflow-wrap: anywhere;
                text-align: right
            }
        }

        @media print {
            body {
                background: #fff
            }

            .sheet {
                width: 100%;
                margin: 0;
                box-shadow: none
            }

            .actions {
                display: none
            }
        }
    </style>
</head>

<body>
    <div class="actions"><button class="btn" type="button" onclick="window.print()">Print / Save as PDF</button><a class="btn secondary" href="employee-portal.php?page=payslips">Back to My Payslips</a></div>
    <main class="sheet">
        <header class="head"><img src="assets/images/ucclogo.jpg" alt="UCC Logo">
            <div>
                <h1>Employee Pay Slip</h1>
                <p>Ubay Community College · UCCHR</p>
            </div>
        </header>
        <section class="meta">
            <div><span>Employee</span><strong><?= e($fullName) ?></strong></div>
            <div><span>Employee ID</span><strong><?= e($row['employee_no']) ?></strong></div>
            <div><span>Department</span><strong><?= e($department) ?></strong></div>
            <div><span>Position</span><strong><?= e($position) ?></strong></div>
            <div><span>Employment Type</span><strong><?= e((string) ($row['employment_type'] ?: '—')) ?></strong></div>
            <div><span>Pay Type</span><strong><?= e($payType) ?></strong></div>
            <div><span>Salary Basis</span><strong><?= e($salaryFormula) ?></strong></div>
            <div><span>Payroll period</span><strong><?= date('M j', strtotime($row['period_start'])) ?>–<?= date('j, Y', strtotime($row['period_end'])) ?></strong></div>
            <div><span>Payroll Status</span><strong><?= e((string) $row['payroll_status']) ?></strong></div>
            <div><span>Payment Method</span><strong><?= e((string) $row['payment_method']) ?></strong></div>
            <div><span>Payment Status</span><strong><?= e((string) $row['payment_status']) ?></strong></div>
        </section>
        <section class="pay-grid">
            <div>
                <h2>Earnings</h2>
                <div class="line"><span>Approved Rate</span><strong><?= money($row['basic_rate']) ?> / <?= e($rateUnit) ?></strong></div>
                <div class="line"><span>Regular hours</span><strong><?= number_format((float) $row['regular_hours'], 2) ?>h</strong></div>
                <div class="line"><span>Calculated Basic Salary</span><strong><?= money($row['monthly_basic_salary']) ?></strong></div>
                <div class="line"><span>Approved OT (<?= number_format((float) $row['overtime_hours'], 2) ?>h)</span><strong><?= money($row['overtime_pay']) ?></strong></div>
                <div class="line"><span>Holiday pay</span><strong><?= money($row['holiday_pay']) ?></strong></div>
                <div class="line"><span>Rest-day pay</span><strong><?= money($row['rest_day_pay']) ?></strong></div>
                <div class="line"><span>Gross pay</span><strong><?= money($row['gross_pay']) ?></strong></div>
            </div>
            <div>
                <h2>Deductions</h2>
                <div class="line"><span>Late (<?= e(ucchr_minutes_label((int) $row['late_minutes'])) ?>)</span><strong><?= money($row['late_deduction']) ?></strong></div>
                <div class="line"><span>Undertime (<?= e(ucchr_minutes_label((int) $row['undertime_minutes'])) ?>)</span><strong><?= money($row['undertime_deduction']) ?></strong></div>
                <div class="line"><span>Absence / Unpaid Time (<?= e(number_format((float) $row['absence_days'], 2)) ?> absent, <?= e(number_format((float) $row['unpaid_leave_days'], 2)) ?> unpaid leave; half-days included)</span><strong><?= money((float) $row['half_day_deduction'] + (float) $row['absence_deduction']) ?></strong></div>
                <div class="line"><span>Cash Advance</span><strong><?= money($row['cash_advance_deduction']) ?></strong></div>
                <?php if ((float) $row['other_deductions'] > 0): ?><div class="line"><span>Legacy Other</span><strong><?= money($row['other_deductions']) ?></strong></div><?php endif; ?>
                <div class="line"><span>Total Deductions</span><strong><?= money($row['total_deductions']) ?></strong></div>
                <p><small>All values are frozen from the approved payroll calculation.</small></p>
            </div>
        </section>
        <div class="net"><span>Net Pay</span><strong><?= money($row['net_pay']) ?></strong></div>
        <p class="net-calculation"><strong><?= e(ucchr_payroll_net_formula()) ?></strong><br><?= e(ucchr_payroll_net_calculation($row)) ?></p>
    </main>
</body>

</html>
