<?php

declare(strict_types=1);

/**
 * Administrative write handlers for the integrated attendance/payroll modules.
 *
 * This file is loaded by actions.php after authentication, CSRF verification,
 * and the shared audit/frozen-payroll guards have been established.  Every
 * branch redirects, so legacy handlers below it remain available without being
 * duplicated or renamed.
 */

if (!isset($pdo, $action, $userId) || !$pdo instanceof PDO) {
    throw new LogicException('Integrated admin actions require an authenticated action context.');
}

if ($action === 'save_leave_decision') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $decision = trim((string) ($_POST['decision'] ?? ''));
    $decisionNote = trim((string) ($_POST['decision_note'] ?? ''));

    try {
        if ($requestId < 1 || !in_array($decision, ['Approved', 'Rejected'], true)) {
            throw new InvalidArgumentException('Choose a valid pending leave request and decision.');
        }
        if (mb_strlen($decisionNote) > 500) {
            throw new InvalidArgumentException('The decision note cannot exceed 500 characters.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT lr.*, e.employee_no
             FROM leave_requests lr
             JOIN employees e ON e.id=lr.employee_id
             WHERE lr.id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new InvalidArgumentException('The leave request was not found.');
        }
        if ((string) $request['status'] !== 'Pending') {
            throw new RuntimeException('Only a Pending leave request can be decided.');
        }

        ucchr_action_assert_not_finalized(
            $pdo,
            (string) $request['start_date'],
            (string) $request['end_date'],
            (int) $request['employee_id']
        );
        $pdo->prepare(
            'UPDATE leave_requests
             SET status=?, approved_by=?, date_approved=NOW(), decision_note=?
             WHERE id=? AND status="Pending"'
        )->execute([
            $decision,
            $userId,
            $decisionNote !== '' ? $decisionNote : null,
            $requestId,
        ]);
        ucchr_action_reprocess_employee_period(
            $pdo,
            (int) $request['employee_id'],
            (string) $request['start_date'],
            (string) $request['end_date']
        );
        ucchr_action_audit(
            $pdo,
            $userId,
            'Leave ' . strtolower($decision),
            'Leave',
            $requestId,
            $decision . ' leave request for ' . (string) $request['employee_no'] . '.',
            ['status' => 'Pending'],
            ['status' => $decision, 'decision_note' => $decisionNote !== '' ? $decisionNote : null]
        );
        $pdo->commit();
        set_flash('success', 'Leave request ' . strtolower($decision) . '; affected attendance was synchronized.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Leave decision was not saved: ' . $error->getMessage());
    }
    redirect('app.php?page=leave');
}

if ($action === 'save_overtime_decision') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $decision = trim((string) ($_POST['decision'] ?? ''));
    $approvedMinutesRaw = trim((string) ($_POST['approved_minutes'] ?? '0'));
    $decisionNote = trim((string) ($_POST['decision_note'] ?? ''));

    try {
        if ($requestId < 1 || !in_array($decision, ['Approved', 'Rejected'], true)) {
            throw new InvalidArgumentException('Choose a valid pending overtime request and decision.');
        }
        if (!preg_match('/^\d+$/', $approvedMinutesRaw) || mb_strlen($decisionNote) > 500) {
            throw new InvalidArgumentException('Approved minutes must be a whole non-negative number and the note must be at most 500 characters.');
        }
        $approvedMinutes = (int) $approvedMinutesRaw;

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT ot.*, e.employee_no
             FROM overtime_requests ot
             JOIN employees e ON e.id=ot.employee_id
             WHERE ot.id=? AND ot.request_source="Employee" LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new InvalidArgumentException('The overtime request was not found.');
        }
        if ((string) $request['status'] !== 'Pending') {
            throw new RuntimeException('Only a Pending overtime request can be decided.');
        }
        $potential = max(0, (int) $request['potential_minutes']);
        $requested = max(0, (int) $request['requested_minutes']);
        $limit = min($potential, $requested > 0 ? $requested : $potential);
        if ($decision === 'Approved' && ($approvedMinutes < 1 || $approvedMinutes > $limit)) {
            throw new InvalidArgumentException('Approved minutes must be between 1 and the eligible limit of ' . $limit . '.');
        }
        if ($decision === 'Rejected') {
            $approvedMinutes = 0;
        }

        ucchr_action_assert_not_finalized(
            $pdo,
            (string) $request['attendance_date'],
            (string) $request['attendance_date'],
            (int) $request['employee_id']
        );
        $pdo->prepare(
            'UPDATE overtime_requests
             SET status=?, approved_minutes=?, approved_by=?, approval_date=NOW(), decision_note=?
             WHERE id=? AND status="Pending"'
        )->execute([
            $decision,
            $approvedMinutes,
            $userId,
            $decisionNote !== '' ? $decisionNote : null,
            $requestId,
        ]);
        if (!empty($request['attendance_id'])) {
            $pdo->prepare(
                'UPDATE attendance SET approved_overtime_minutes=? WHERE id=? AND employee_id=?'
            )->execute([$approvedMinutes, (int) $request['attendance_id'], (int) $request['employee_id']]);
        }
        ucchr_action_reprocess_employee_period(
            $pdo,
            (int) $request['employee_id'],
            (string) $request['attendance_date'],
            (string) $request['attendance_date']
        );
        ucchr_action_audit(
            $pdo,
            $userId,
            'Overtime ' . strtolower($decision),
            'Overtime',
            $requestId,
            $decision . ' overtime for ' . (string) $request['employee_no'] . '.',
            ['status' => 'Pending', 'approved_minutes' => (int) $request['approved_minutes']],
            ['status' => $decision, 'approved_minutes' => $approvedMinutes, 'decision_note' => $decisionNote !== '' ? $decisionNote : null]
        );
        $pdo->commit();
        set_flash('success', 'Overtime request ' . strtolower($decision) . '; payroll eligibility was synchronized.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Overtime decision was not saved: ' . $error->getMessage());
    }
    redirect('app.php?page=overtime');
}

if ($action === 'save_holiday') {
    $holidayId = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['holiday_name'] ?? ''));
    $date = trim((string) ($_POST['holiday_date'] ?? ''));
    $type = trim((string) ($_POST['holiday_type'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? 'Active'));

    try {
        if (
            $name === '' || mb_strlen($name) > 160 || !ucchr_action_valid_date($date)
            || !in_array($type, ['Regular Holiday', 'Special Non-Working Day'], true)
            || !in_array($status, ['Active', 'Inactive'], true)
            || mb_strlen($description) > 500
        ) {
            throw new InvalidArgumentException('Enter a valid holiday name, date, type, status, and optional description.');
        }

        $pdo->beginTransaction();
        $old = null;
        if ($holidayId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM holidays WHERE id=? LIMIT 1 FOR UPDATE');
            $stmt->execute([$holidayId]);
            $old = $stmt->fetch();
            if (!$old) {
                throw new InvalidArgumentException('The holiday record was not found.');
            }
            ucchr_action_assert_not_finalized($pdo, (string) $old['holiday_date'], (string) $old['holiday_date']);
        }
        ucchr_action_assert_not_finalized($pdo, $date, $date);

        if ($holidayId > 0) {
            $pdo->prepare(
                'UPDATE holidays
                 SET holiday_name=?, holiday_date=?, holiday_type=?, description=?, status=?
                 WHERE id=?'
            )->execute([$name, $date, $type, $description !== '' ? $description : null, $status, $holidayId]);
        } else {
            $pdo->prepare(
                'INSERT INTO holidays
                    (holiday_name, holiday_date, holiday_type, description, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$name, $date, $type, $description !== '' ? $description : null, $status, $userId]);
            $holidayId = (int) $pdo->lastInsertId();
        }

        $affectedDates = [$date];
        if ($old && (string) $old['holiday_date'] !== $date) {
            $affectedDates[] = (string) $old['holiday_date'];
        }
        foreach (array_unique($affectedDates) as $affectedDate) {
            ucchr_action_reprocess_date($pdo, $affectedDate);
        }
        $new = [
            'holiday_name' => $name,
            'holiday_date' => $date,
            'holiday_type' => $type,
            'description' => $description !== '' ? $description : null,
            'status' => $status,
        ];
        ucchr_action_audit(
            $pdo,
            $userId,
            $old ? 'Holiday modified' : 'Holiday added',
            'Holidays',
            $holidayId,
            ($old ? 'Updated' : 'Added') . ' holiday ' . $name . '.',
            $old ?: null,
            $new
        );
        $pdo->commit();
        set_flash('success', 'Holiday saved and affected attendance was synchronized.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $error instanceof PDOException && (string) $error->getCode() === '23000'
            ? 'A holiday already exists on that date.'
            : $error->getMessage();
        set_flash('error', 'Holiday was not saved: ' . $message);
    }
    redirect('app.php?page=holidays' . ($holidayId > 0 ? '&edit=' . $holidayId : ''));
}

if ($action === 'delete_holiday') {
    $holidayId = (int) ($_POST['id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM holidays WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$holidayId]);
        $holiday = $stmt->fetch();
        if (!$holiday) {
            throw new InvalidArgumentException('The holiday record was not found.');
        }
        ucchr_action_assert_not_finalized(
            $pdo,
            (string) $holiday['holiday_date'],
            (string) $holiday['holiday_date']
        );
        $pdo->prepare('UPDATE holidays SET status="Inactive" WHERE id=?')->execute([$holidayId]);
        ucchr_action_reprocess_date($pdo, (string) $holiday['holiday_date']);
        ucchr_action_audit(
            $pdo,
            $userId,
            'Holiday deactivated',
            'Holidays',
            $holidayId,
            'Deactivated holiday ' . (string) $holiday['holiday_name'] . '.',
            ['status' => (string) $holiday['status']],
            ['status' => 'Inactive']
        );
        $pdo->commit();
        set_flash('success', 'Holiday deactivated; it was not deleted from history.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Holiday was not deactivated: ' . $error->getMessage());
    }
    redirect('app.php?page=holidays');
}

if ($action === 'process_attendance_date') {
    $date = trim((string) ($_POST['attendance_date'] ?? ''));
    try {
        if (!ucchr_action_valid_date($date) || $date > date('Y-m-d')) {
            throw new InvalidArgumentException('Choose today or a past attendance date.');
        }
        if (!function_exists('attendance_process_date')) {
            throw new RuntimeException('The integrated attendance processor is unavailable.');
        }
        $pdo->beginTransaction();
        ucchr_action_assert_not_finalized($pdo, $date, $date);
        $result = attendance_process_date(
            $pdo,
            $date,
            new DateTimeImmutable('now'),
            $date < date('Y-m-d')
        );
        ucchr_action_audit(
            $pdo,
            $userId,
            'Daily attendance processed',
            'Attendance',
            $date,
            'Processed attendance date ' . $date . '.',
            null,
            $result
        );
        $pdo->commit();
        set_flash('success', 'Attendance processed: ' . (int) $result['processed'] . ' row(s), ' . (int) $result['deferred'] . ' still open.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Attendance was not processed: ' . $error->getMessage());
    }
    redirect('app.php?page=attendance&date=' . rawurlencode($date));
}

if ($action === 'save_payroll_settings') {
    $booleanKeys = [
        'late_deduction_enabled',
        'undertime_deduction_enabled',
        'overtime_enabled',
        'employee_overtime_requests_enabled',
    ];
    $numericRanges = [
        'regular_hours_per_day' => [0.01, 24.0],
        'grace_minutes' => [0.0, 240.0],
        'break_duration' => [0.0, 480.0],
        'half_day_minimum_percent' => [1.0, 99.0],
        'full_day_minimum_percent' => [2.0, 100.0],
        'overtime_multiplier' => [0.0, 10.0],
        'regular_holiday_worked_multiplier' => [0.0, 10.0],
        'regular_holiday_overtime_multiplier' => [0.0, 10.0],
        'special_day_worked_multiplier' => [0.0, 10.0],
        'special_day_overtime_multiplier' => [0.0, 10.0],
        'rest_day_multiplier' => [0.0, 10.0],
        'regular_holiday_rest_day_multiplier' => [0.0, 10.0],
        'special_day_rest_day_multiplier' => [0.0, 10.0],
    ];

    try {
        $values = [];
        foreach ($booleanKeys as $key) {
            $value = trim((string) ($_POST[$key] ?? '0'));
            if (!in_array($value, ['0', '1'], true)) {
                throw new InvalidArgumentException("{$key} must be enabled or disabled.");
            }
            $values[$key] = $value;
        }
        foreach ($numericRanges as $key => [$minimum, $maximum]) {
            $raw = trim((string) ($_POST[$key] ?? ''));
            if ($raw === '' || !is_numeric($raw)) {
                throw new InvalidArgumentException("{$key} must be numeric.");
            }
            $number = (float) $raw;
            if (!is_finite($number) || $number < $minimum || $number > $maximum) {
                throw new InvalidArgumentException("{$key} is outside its permitted range.");
            }
            $values[$key] = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
            if ($values[$key] === '') {
                $values[$key] = '0';
            }
        }
        // Overtime approval is a fixed integrity rule, not an optional toggle.
        $values['overtime_requires_approval'] = '1';
        $frequency = trim((string) ($_POST['payroll_frequency'] ?? ''));
        $rounding = trim((string) ($_POST['rounding_rule'] ?? ''));
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? '')));
        if (
            !in_array($frequency, ['Weekly', 'Bi-weekly', 'Semi-monthly', 'Monthly'], true)
            || !in_array($rounding, ['nearest_cent', 'nearest_peso', 'truncate_cent'], true)
            || !preg_match('/^[A-Z]{3}$/', $currency)
        ) {
            throw new InvalidArgumentException('Choose a valid payroll frequency, rounding rule, and three-letter currency.');
        }
        $values['payroll_frequency'] = $frequency;
        $values['rounding_rule'] = $rounding;
        $values['currency'] = $currency;
        if ((float) $values['full_day_minimum_percent'] <= (float) $values['half_day_minimum_percent']) {
            throw new InvalidArgumentException('Full-day percentage must be greater than the half-day minimum.');
        }

        $pdo->beginTransaction();
        $keys = array_keys($values);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ({$placeholders}) FOR UPDATE");
        $stmt->execute($keys);
        $old = [];
        foreach ($stmt->fetchAll() as $row) {
            $old[(string) $row['key']] = (string) $row['value'];
        }
        $save = $pdo->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)'
        );
        foreach ($values as $key => $value) {
            $save->execute([$key, $value]);
        }
        ucchr_action_audit(
            $pdo,
            $userId,
            'Payroll settings changed',
            'Payroll Settings',
            null,
            'Updated centralized attendance and payroll policy values.',
            $old,
            $values
        );
        $pdo->commit();
        set_flash('success', 'Payroll policies saved. New Draft calculations use these values; finalized history remains unchanged.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Payroll settings were not saved: ' . $error->getMessage());
    }
    redirect('app.php?page=payroll_settings');
}

if ($action === 'run_payroll') {
    $periodStart = trim((string) ($_POST['period_start'] ?? ''));
    $periodEnd = trim((string) ($_POST['period_end'] ?? ''));
    $employeeId = (int) ($_POST['employee_id'] ?? 0);
    $batchScope = (string) ($_POST['scope'] ?? '') === 'all';
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'Cash'));
    $paymentStatus = trim((string) ($_POST['payment_status'] ?? 'Pending'));
    $cashAdvanceRaw = trim((string) ($_POST['cash_advance_amount'] ?? '0'));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $runId = 0;
    try {
        if (!function_exists('payroll_generate_run')) {
            throw new RuntimeException('The integrated payroll engine is unavailable.');
        }
        if (mb_strlen($notes) > 1000) {
            throw new InvalidArgumentException('Payroll notes cannot exceed 1,000 characters.');
        }
        if ($employeeId < 1 && !$batchScope) {
            throw new InvalidArgumentException('Select the employee whose payroll will be generated.');
        }
        if ($batchScope && $employeeId !== 0) {
            throw new InvalidArgumentException('Mixed payroll must use the All active employees scope.');
        }
        if ($cashAdvanceRaw === '' || !is_numeric($cashAdvanceRaw)) {
            throw new InvalidArgumentException('Cash Advance must be a valid amount, or 0 when none applies.');
        }
        $cashAdvanceValue = (float) $cashAdvanceRaw;
        if (!is_finite($cashAdvanceValue) || $cashAdvanceValue < 0 || $cashAdvanceValue > 999999999.99) {
            throw new InvalidArgumentException('Cash Advance must be between 0.00 and 999,999,999.99.');
        }
        $cashAdvanceAmount = round($cashAdvanceValue, 2, PHP_ROUND_HALF_UP);
        if ($batchScope && $cashAdvanceAmount > 0) {
            throw new InvalidArgumentException('Additional Cash Advance is per employee. Set it to 0.00 for mixed payroll, or run a single employee.');
        }
        if (!in_array($paymentMethod, ['Cash', 'Bank Transfer'], true)) {
            throw new InvalidArgumentException('Select Cash or Bank Transfer as the payment method.');
        }
        if (!in_array($paymentStatus, ['Pending', 'Paid'], true)) {
            throw new InvalidArgumentException('Select Pending or Paid as the payment status.');
        }
        $runId = payroll_generate_run(
            $pdo,
            $periodStart,
            $periodEnd,
            $userId,
            $notes !== '' ? $notes : null,
            $batchScope ? null : $employeeId,
            $paymentMethod,
            $cashAdvanceAmount,
            $paymentStatus
        );
        set_flash('success', $paymentStatus === 'Paid'
            ? 'Payroll #' . $runId . ' was generated and recorded as Paid. The payment date and HR/Admin confirmation were saved.'
            : 'Payroll Draft #' . $runId . ' generated with Pending payment status. Review and approve it before releasing payment.');
    } catch (Throwable $error) {
        set_flash(
            'error',
            $runId > 0
                ? 'Payroll #' . $runId . ' was generated, but its payment workflow stopped: ' . $error->getMessage() . ' Open the payroll review to continue safely.'
                : 'Payroll was not generated: ' . $error->getMessage()
        );
    }
    redirect($runId > 0
        ? 'app.php?page=payroll&run_id=' . $runId . '#payroll-review'
        : 'app.php?page=payroll&view=generate');
}

if ($action === 'update_payroll_payment_method') {
    $runId = (int) ($_POST['run_id'] ?? 0);
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
    try {
        if (!function_exists('payroll_update_payment_method')) {
            throw new RuntimeException('The integrated payroll payment service is unavailable.');
        }
        payroll_update_payment_method($pdo, $runId, $paymentMethod, $userId);
        set_flash('success', 'Payroll #' . $runId . ' payment method updated to ' . $paymentMethod . '.');
    } catch (Throwable $error) {
        set_flash('error', 'Payment method was not changed: ' . $error->getMessage());
    }
    redirect('app.php?page=payroll&run_id=' . $runId . '#payroll-review');
}

if ($action === 'recalculate_payroll') {
    $runId = (int) ($_POST['run_id'] ?? 0);
    try {
        if (!function_exists('payroll_recalculate_draft')) {
            throw new RuntimeException('The integrated payroll engine is unavailable.');
        }
        payroll_recalculate_draft($pdo, $runId, $userId);
        set_flash('success', 'Payroll Draft #' . $runId . ' recalculated from the latest non-frozen attendance and policies.');
    } catch (Throwable $error) {
        set_flash('error', 'Payroll Draft was not recalculated: ' . $error->getMessage());
    }
    redirect('app.php?page=payroll&run_id=' . $runId . '#payroll-review');
}

if ($action === 'delete_payroll_draft') {
    $runId = (int) ($_POST['run_id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        $deleted = payroll_delete_draft($pdo, $runId, $userId, $reason);
        set_flash(
            'success',
            'Payroll Draft #' . $runId . ' was deleted. Its attendance remains available; validate the period again before creating a replacement Draft.'
        );
        redirect('app.php?' . http_build_query([
            'page' => 'payroll',
            'view' => 'generate',
            'employee_id' => $deleted['scope_employee_id'] ?? 0,
            'period_start' => $deleted['period_start'],
            'period_end' => $deleted['period_end'],
            'validate' => 1,
        ]));
    } catch (Throwable $error) {
        set_flash('error', 'Payroll Draft was not deleted: ' . $error->getMessage());
        redirect('app.php?page=payroll&run_id=' . $runId . '#payroll-review');
    }
}

if ($action === 'return_payroll_to_draft') {
    $runId = (int) ($_POST['run_id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if (!function_exists('payroll_return_to_draft')) {
            throw new RuntimeException('The integrated payroll engine is unavailable.');
        }
        if ($note === '' || mb_strlen($note) > 1000) {
            throw new InvalidArgumentException('Enter a correction reason of at most 1,000 characters.');
        }
        payroll_return_to_draft($pdo, $runId, $userId, $note);
        set_flash('success', 'Payroll #' . $runId . ' returned to Draft. Correct its source records, then click Recalculate.');
    } catch (Throwable $error) {
        set_flash('error', 'Payroll was not returned to Draft: ' . $error->getMessage());
    }
    redirect('app.php?page=payroll&run_id=' . $runId . '#payroll-review');
}

if ($action === 'transition_payroll') {
    $runId = (int) ($_POST['run_id'] ?? 0);
    $targetStatus = trim((string) ($_POST['target_status'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if (!function_exists('payroll_transition_run')) {
            throw new RuntimeException('The integrated payroll engine is unavailable.');
        }
        if (mb_strlen($note) > 1000) {
            throw new InvalidArgumentException('Transition notes cannot exceed 1,000 characters.');
        }
        payroll_transition_run(
            $pdo,
            $runId,
            $targetStatus,
            $userId,
            $note !== '' ? $note : null
        );
        set_flash('success', 'Payroll #' . $runId . ' advanced to ' . $targetStatus . '.');
    } catch (Throwable $error) {
        set_flash('error', 'Payroll status was not changed: ' . $error->getMessage());
    }
    redirect('app.php?page=payroll&run_id=' . $runId . '#payroll-review');
}
