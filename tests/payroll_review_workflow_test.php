<?php

declare(strict_types=1);

/**
 * Non-mutating source checks for the payroll review, expected salary, and
 * approved-payslip release workflow. This test does not require MariaDB.
 */

function workflow_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function workflow_read(string $path): string
{
    $source = file_get_contents($path);
    workflow_assert(is_string($source), 'Could not read ' . $path);
    return $source;
}

$root = realpath(__DIR__ . '/..');
workflow_assert(is_string($root), 'Project root must resolve');

try {
    $migration = workflow_read($root . '/database/payroll_review_automation_update.sql');
    $dashboardMigration = workflow_read($root . '/database/payroll_dashboard_update.sql');
    $dailyRateMigration = workflow_read($root . '/database/daily_rate_payroll_update.sql');
    $flexiblePayTypeMigration = workflow_read($root . '/database/flexible_pay_type_salary_update.sql');
    $compensationIntegrityMigration = workflow_read($root . '/database/compensation_history_integrity_update.sql');
    $monthlyPayMigration = workflow_read($root . '/database/monthly_pay_type_payroll_update.sql');
    $attendanceStatusMigration = workflow_read($root . '/database/attendance_status_payroll_update.sql');
    $app = workflow_read($root . '/app.php');
    $layout = workflow_read($root . '/includes/layout.php');
    $functions = workflow_read($root . '/includes/functions.php');
    $payroll = workflow_read($root . '/pages/payroll.php');
    $attendancePage = workflow_read($root . '/pages/attendance.php');
    $employeeEdit = workflow_read($root . '/pages/employee_edit.php');
    $employeeNew = workflow_read($root . '/pages/employee_new.php');
    $schedule = workflow_read($root . '/pages/schedule.php');
    $actions = workflow_read($root . '/includes/integrated_admin_actions.php');
    $employeeActions = workflow_read($root . '/includes/actions.php');
    $attendanceSchedule = workflow_read($root . '/includes/attendance_schedule.php');
    $payrollEngine = workflow_read($root . '/includes/payroll_engine.php');
    $adminPayslips = workflow_read($root . '/pages/payslips.php');
    $employeePayslips = workflow_read($root . '/employee_pages/payslips.php');
    $employeeDashboard = workflow_read($root . '/employee_pages/dashboard.php');
    $employeePrint = workflow_read($root . '/employee-payslip.php');
    $deductionPrint = workflow_read($root . '/payroll-deductions-print.php');

    workflow_assert(
        str_contains($payroll, 'Use unpaid dates:')
            && str_contains($payrollEngine, 'Dates in that run cannot be paid twice')
            && str_contains($attendancePage, 'Payroll-locked day')
            && str_contains($attendancePage, 'pr.status IN ("For Review","Approved","Finalized","Paid","Released")')
            && str_contains($employeeActions, 'The paid or finalized payroll record is read-only'),
        'Overlapping payroll and frozen attendance must give actionable guidance without changing paid history'
    );
    workflow_assert(
        str_contains($payroll, 'Scheduled pay estimate')
            && str_contains($payroll, 'Current Employee Records Rate')
            && str_contains($payroll, 'payroll-formula-output')
            && str_contains($functions, "' scheduled hours × ' . money((float) \$rate) . '/hour'")
            && !str_contains($payroll, 'No verified worked hours:')
            && !str_contains($payroll, 'The latest saved employee rate; earlier periods may use a different approved rate.'),
        'Hourly payroll preview must reconcile scheduled Basic Pay with the dated rate and separate unpaid time'
    );

    foreach (
        [
            'employee_deduction_entries',
            'undertime_deduction',
            'cash_advance_deduction',
            'other_deductions',
            'HALF_DAY',
            '2026-09-09-payroll-review-automation-v1'
        ] as $required
    ) {
        workflow_assert(str_contains($migration, $required), 'Migration must define ' . $required);
    }

    foreach (
        [
            'scope_employee_id',
            'payment_method',
            'payment_status',
            'uq_payroll_scope_period',
            '2026-09-10-payroll-dashboard-v1'
        ] as $required
    ) {
        workflow_assert(
            str_contains($dashboardMigration, $required),
            'Payroll Dashboard migration must define ' . $required
        );
    }

    foreach (['monthly_basic_salary', '@ucchr_flexible_pay_types_installed', '2026-09-10-fixed-daily-rate-payroll-v1'] as $required) {
        workflow_assert(
            str_contains($dailyRateMigration, $required),
            'Daily-rate payroll migration must define ' . $required
        );
    }
    foreach (['monthly_basic_salary', '@ucchr_compensation_integrity_installed', '2026-09-11-flexible-pay-types-v1'] as $required) {
        workflow_assert(
            str_contains($flexiblePayTypeMigration, $required),
            'Flexible pay-type migration must define ' . $required
        );
    }
    foreach (['@ucchr_compensation_rows_to_repair', "ENUM('Daily','Hourly','Monthly')", '2026-09-19-compensation-history-integrity-v1'] as $required) {
        workflow_assert(
            str_contains($compensationIntegrityMigration, $required),
            'Compensation-history integrity migration must define ' . $required
        );
    }
    workflow_assert(
        str_contains($monthlyPayMigration, "ENUM('Daily','Hourly','Monthly')")
            && str_contains($monthlyPayMigration, 'employee_compensation_history'),
        'The final payroll migration must enable all three pay types without rewriting historical items'
    );
    foreach (['half_day_deduction', 'absence_deduction', 'half_day_minimum_percent', '2026-09-11-attendance-status-payroll-v1'] as $required) {
        workflow_assert(
            str_contains($attendanceStatusMigration, $required),
            'Attendance-status payroll migration must define ' . $required
        );
    }

    workflow_assert(
        str_contains($payroll, "'Draft' => 'For Review'")
            && str_contains($payroll, "'For Review' => 'Approved'")
            && str_contains($payroll, "'Approved' => 'Finalized'")
            && str_contains($payroll, "'Finalized' => 'Paid'")
            && !str_contains($payroll, 'payroll-review-print.php')
            && str_contains($payroll, 'payment_method')
            && str_contains($payroll, 'Generate Employee Payroll'),
        'Payroll Dashboard must preserve the controlled lifecycle and payment details without Print Review'
    );
    workflow_assert(
        str_contains($payroll, 'Run Payroll')
            && str_contains($payroll, 'Total net payroll')
            && str_contains($payroll, 'Generate Employee Payroll')
            && str_contains($payroll, 'Approved Rate')
            && str_contains($payroll, 'Validate Payroll')
            && str_contains($payroll, 'Calculated Basic Salary')
            && str_contains($payroll, 'Approved overtime')
            && str_contains($payroll, 'Bank Transfer')
            && str_contains($payroll, 'Generate Payroll')
            && str_contains($payroll, 'Generate Mixed Payroll'),
        'Payroll must provide the dashboard, automatic employee data, and payment controls'
    );
    workflow_assert(
        str_contains($actions, "\$action === 'run_payroll'")
            && str_contains($actions, "\$_POST['employee_id']")
            && str_contains($actions, "\$_POST['payment_method']")
            && str_contains($actions, "\$_POST['payment_status']")
            && str_contains($actions, "\$_POST['cash_advance_amount']")
            && str_contains($payroll, 'name="cash_advance_amount"'),
        'Payroll generation must validate selected employee, payment details, and Cash Advance server-side'
    );
    workflow_assert(
        substr_count($payroll, 'name="payment_status" data-payment-status') === 2
            && substr_count($payroll, '<option value="Pending">Pending') === 2
            && substr_count($payroll, '<option value="Paid">Paid') === 2
            && str_contains($actions, "['Pending', 'Paid']")
            && str_contains($payrollEngine, "'payment' => ['method' => \$paymentMethod, 'status' => \$paymentStatus]"),
        'Individual and mixed payroll generation must offer validated Pending and Paid states'
    );
    workflow_assert(
        str_contains($actions, "\$action === 'delete_payroll_draft'")
            && str_contains($payrollEngine, 'function payroll_delete_draft(')
            && str_contains($payrollEngine, "'Deleted payroll draft'")
            && str_contains($payroll, 'Delete Draft &amp; Re-run')
            && str_contains($payroll, 'name="reason"'),
        'Only an auditable unreviewed Draft may be deleted to rerun payroll'
    );

    foreach (
        [
            'late_deduction',
            'undertime_deduction',
            'half_day_deduction',
            'absence_deduction',
            'cash_advance_deduction',
            'other_deductions',
            'total_deductions',
            'gross_pay',
            'net_pay'
        ] as $field
    ) {
        workflow_assert(str_contains($payroll, $field), 'Payroll review must display ' . $field);
    }

    workflow_assert(
        str_contains($employeeEdit, 'Expected Monthly Salary')
            && str_contains($employeeEdit, 'data-scheduled-days')
            && str_contains($payrollEngine, 'function payroll_expected_monthly_amount')
            && str_contains($payrollEngine, 'function payroll_expected_period_estimate')
            && str_contains($payrollEngine, "'scheduled_workdays_x_effective_daily_rate_minus_unpaid_time'")
            && !str_contains($payrollEngine, 'PAYROLL_MONTHLY_DAY_BASIS = 30')
            && str_contains($payrollEngine, "'monthly_basic_salary' => \$monthlyBasicSalary")
            && !str_contains($employeeEdit, 'Expected Schedule by Month')
            && !str_contains($employeeEdit, 'save_monthly_schedule')
            && !str_contains($actions, "\$action === 'save_monthly_schedule'")
            && !str_contains($attendanceSchedule, 'FROM employee_monthly_schedules')
            && !str_contains($payrollEngine, "'monthly_schedules' =>"),
        'Employee Records and payroll must use centralized monthly salary formulas without the obsolete monthly-schedule editor'
    );

    workflow_assert(
        str_contains($employeeNew, '<option value="Daily">')
            && str_contains($employeeNew, '<option value="Hourly">')
            && str_contains($employeeNew, '<option value="Monthly">')
            && str_contains($employeeEdit, 'name="pay_type"')
            && str_contains($employeeEdit, '<option value="Monthly"')
            && str_contains($schedule, 'name="pay_type"')
            && str_contains($schedule, '<option value="Monthly"')
            && str_contains($employeeActions, "['Daily', 'Hourly', 'Monthly']"),
        'Employee and Work Schedule forms must expose and validate Daily, Hourly, and Monthly pay types'
    );

    workflow_assert(
        !is_file($root . '/pages/deductions.php')
            && !str_contains($app, "    'deductions',")
            && str_contains($app, "\$page === 'deductions'")
            && str_contains($app, 'page=payroll&view=generate')
            && !str_contains($layout, "nav_link('deductions'")
            && !str_contains($actions, "\$action === 'save_deduction_entry'")
            && !str_contains($actions, "\$action === 'cancel_deduction_entry'")
            && str_contains($payrollEngine, 'float $cashAdvanceAmount = 0.0')
            && str_contains($payrollEngine, 'Entered during payroll generation'),
        'The redundant Deductions module must be retired while Cash Advance remains atomic in Generate Payroll'
    );

    foreach ([$adminPayslips, $employeePayslips] as $source) {
        workflow_assert(
            str_contains($source, '$canPrint')
                && str_contains($source, "['Approved', 'Finalized', 'Paid', 'Released']"),
            'Payslip lists must show processing status but protect printing until approval'
        );
        workflow_assert(
            str_contains($source, 'payment_method')
                && str_contains($source, 'payment_status'),
            'Payslip lists must show synchronized payment details'
        );
    }
    workflow_assert(
        str_contains($employeeDashboard, 'payment_method')
            && str_contains($employeeDashboard, 'payment_status')
            && str_contains($employeeDashboard, 'Latest Payroll'),
        'Employee Portal dashboard must surface the latest generated payroll and payment state'
    );
    workflow_assert(
        str_contains($employeePrint, 'pr.status IN ("Approved", "Finalized", "Paid", "Released")'),
        'Employee printable payslips must remain restricted to approved official payroll'
    );

    workflow_assert(
        str_contains($deductionPrint, 'require_login($pdo);')
            && str_contains($deductionPrint, 'window.print()'),
        'Remaining printable payroll documents must be authenticated and browser-printable'
    );

    workflow_assert(
        str_contains($functions, 'Gross Pay − Late − Undertime − Absence / Unpaid Time − Cash Advance = Net Pay')
            && str_contains($payrollEngine, "'net_pay_calculation' => [")
            && str_contains($payrollEngine, 'exceed Gross Pay')
            && str_contains($payroll, 'ucchr_payroll_net_formula()')
            && str_contains($employeePayslips, 'ucchr_payroll_net_formula()')
            && str_contains($employeePrint, 'ucchr_payroll_net_calculation($row)')
            && str_contains($adminPayslips, "\$item['other_deductions'] ?? 0")
            && str_contains($employeePayslips, "\$payslip['other_deductions']"),
        'Payroll review and payslips must reconcile Gross Pay through the approved deductions to Net Pay'
    );

    foreach (['sss', 'philhealth', 'pagibig'] as $retired) {
        workflow_assert(
            !str_contains(strtolower($payroll), $retired),
            'Active payroll pages must not restore retired ' . $retired . ' fields'
        );
    }

    echo "payroll_review_workflow_test: PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, "payroll_review_workflow_test: FAIL - {$error->getMessage()}\n");
    exit(1);
}
