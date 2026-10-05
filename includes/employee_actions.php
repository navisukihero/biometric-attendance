<?php

declare(strict_types=1);

require_once __DIR__ . '/overtime_requests.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}


if (!isset($employee) || !is_array($employee) || empty($employee['employee_id'])) {
    http_response_code(403);
    exit('Authenticated employee context is required.');
}
/** @var array<string, mixed> $employee */

verify_csrf();
$action = (string) ($_POST['action'] ?? '');

function employee_portal_audit(PDO $pdo, int $employeeId, string $action, string $module, int $recordId, string $description): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs
                (user_id, action, module, record_id, description, old_values, new_values, ip_address, created_at)
             VALUES (NULL, ?, ?, ?, ?, NULL, NULL, ?, NOW())'
        );
        $stmt->execute([
            $action,
            $module,
            (string) $recordId,
            $description . ' [Employee #' . $employeeId . ']',
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    } catch (PDOException) {

        $legacyAudit = $pdo->prepare(
            'INSERT INTO activity_logs (user_id, action, created_at) VALUES (NULL, ?, NOW())'
        );
        $legacyAudit->execute([
            $action . ' - ' . $description . ' [Employee #' . $employeeId . ']'
        ]);
    }
}

function employee_portal_assert_not_frozen(PDO $pdo, int $employeeId, string $date): void
{
    $stmt = $pdo->prepare(
        'SELECT pr.id
         FROM payroll_item_attendance pia
         JOIN payroll_items pi ON pi.id=pia.payroll_item_id
         JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
         JOIN attendance a ON a.id=pia.attendance_id
         WHERE a.employee_id=? AND a.scan_date=?
           AND pr.status IN ("For Review", "Approved", "Finalized", "Paid", "Released")
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$employeeId, $date]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('This attendance date is already included in payroll under review or frozen history. Return an editable run to Draft before changing its source records.');
    }
}

if ($action === 'submit_leave_request') {
    $employeeId = (int) $employee['employee_id'];
    $leaveType = trim((string) ($_POST['leave_type'] ?? ''));
    $startDate = trim((string) ($_POST['start_date'] ?? ''));
    $endDate = trim((string) ($_POST['end_date'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if (
            !in_array($leaveType, ['Paid Leave', 'Unpaid Leave'], true)
            || !$start || $start->format('Y-m-d') !== $startDate
            || !$end || $end->format('Y-m-d') !== $endDate
            || $end < $start || $start->diff($end)->days > 366
            || $startDate < date('Y-m-d')
            || $reason === '' || mb_strlen($reason) > 1000
        ) {
            throw new InvalidArgumentException('Enter a valid future/current leave period of at most 367 days and a reason.');
        }

        $pdo->beginTransaction();
        $overlap = $pdo->prepare(
            'SELECT id FROM leave_requests
             WHERE employee_id=? AND status IN ("Pending", "Approved")
               AND start_date<=? AND end_date>=?
             LIMIT 1 FOR UPDATE'
        );
        $overlap->execute([$employeeId, $endDate, $startDate]);
        if ($overlap->fetchColumn() !== false) {
            throw new RuntimeException('A pending or approved leave request already overlaps those dates.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason, status)
             VALUES (?, ?, ?, ?, ?, "Pending")'
        );
        $stmt->execute([$employeeId, $leaveType, $startDate, $endDate, $reason]);
        $requestId = (int) $pdo->lastInsertId();
        employee_portal_audit(
            $pdo,
            $employeeId,
            'Employee submitted leave',
            'Leave',
            $requestId,
            'Submitted ' . $leaveType . ' for ' . $startDate . ' through ' . $endDate . '.'
        );
        $pdo->commit();
        set_flash('success', 'Leave request submitted for HR/Admin review.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Leave request was not submitted: ' . $error->getMessage());
    }
    redirect('employee-portal.php?page=leave');
}

if ($action === 'cancel_leave_request') {
    $employeeId = (int) $employee['employee_id'];
    $requestId = (int) ($_POST['leave_request_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT id, status FROM leave_requests
             WHERE id=? AND employee_id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$requestId, $employeeId]);
        $request = $stmt->fetch();
        if (!$request) {
            throw new InvalidArgumentException('The leave request was not found.');
        }
        if ((string) $request['status'] !== 'Pending') {
            throw new RuntimeException('Only your own Pending leave request can be cancelled.');
        }
        $pdo->prepare(
            'UPDATE leave_requests SET status="Cancelled" WHERE id=? AND employee_id=? AND status="Pending"'
        )->execute([$requestId, $employeeId]);
        employee_portal_audit($pdo, $employeeId, 'Employee cancelled leave', 'Leave', $requestId, 'Cancelled a pending leave request.');
        $pdo->commit();
        set_flash('success', 'Pending leave request cancelled.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Leave request was not cancelled: ' . $error->getMessage());
    }
    redirect('employee-portal.php?page=leave');
}

if ($action === 'submit_overtime_request') {
    $employeeId = (int) $employee['employee_id'];
    $requestedHoursRaw = trim((string) ($_POST['requested_hours'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        $requestedMinutes = ucchr_overtime_hours_to_minutes($requestedHoursRaw);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Enter a reason containing no more than 1,000 characters.');
        }

        $pdo->beginTransaction();
        $setting = $pdo->prepare('SELECT `value` FROM settings WHERE `key`="employee_overtime_requests_enabled" LIMIT 1');
        $setting->execute();
        if ((string) $setting->fetchColumn() !== '1') {
            throw new RuntimeException('Employee overtime requests are currently disabled by HR/Admin.');
        }
        $attendance = ucchr_overtime_find_eligible_attendance($pdo, $employeeId, $requestedMinutes);
        if (!$attendance) {
            throw new RuntimeException(
                'No eligible attendance record has enough recorded potential overtime for '
                    . ucchr_overtime_minutes_label($requestedMinutes) . '.'
            );
        }
        $attendanceDate = (string) $attendance['scan_date'];
        $potential = max(0, (int) ($attendance['potential_overtime_minutes'] ?? 0));
        employee_portal_assert_not_frozen($pdo, $employeeId, $attendanceDate);

        $existingStmt = $pdo->prepare(
            'SELECT * FROM overtime_requests
             WHERE employee_id=? AND attendance_date=? LIMIT 1 FOR UPDATE'
        );
        $existingStmt->execute([$employeeId, $attendanceDate]);
        $existing = $existingStmt->fetch();
        if ($existing && (string) $existing['status'] !== 'Cancelled') {
            throw new RuntimeException('That attendance date already has an employee-reviewed or completed overtime request.');
        }
        if ($existing) {
            $pdo->prepare(
                'UPDATE overtime_requests
                 SET attendance_id=?, potential_minutes=?, requested_minutes=?, approved_minutes=0,
                     status="Pending", request_source="Employee", reason=?, approved_by=NULL,
                     approval_date=NULL, decision_note=NULL, created_at=NOW(), updated_at=NOW()
                 WHERE id=?'
            )->execute([(int) $attendance['id'], $potential, $requestedMinutes, $reason, (int) $existing['id']]);
            $requestId = (int) $existing['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO overtime_requests
                    (employee_id, attendance_id, attendance_date, potential_minutes,
                     requested_minutes, approved_minutes, status, request_source, reason)
                 VALUES (?, ?, ?, ?, ?, 0, "Pending", "Employee", ?)'
            )->execute([$employeeId, (int) $attendance['id'], $attendanceDate, $potential, $requestedMinutes, $reason]);
            $requestId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE attendance SET approved_overtime_minutes=0 WHERE id=?')->execute([(int) $attendance['id']]);
        employee_portal_audit(
            $pdo,
            $employeeId,
            'Employee submitted overtime',
            'Overtime',
            $requestId,
            'Requested ' . ucchr_overtime_minutes_label($requestedMinutes) . ' of '
                . ucchr_overtime_minutes_label($potential) . ' potential overtime for ' . $attendanceDate . '.'
        );
        $pdo->commit();
        set_flash(
            'success',
            'Overtime request submitted and linked to ' . date('M j, Y', strtotime($attendanceDate))
                . '. You can now view or print the official request form.'
        );
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Overtime request was not submitted: ' . $error->getMessage());
    }
    redirect('employee-portal.php?page=overtime');
}

if ($action !== 'change_employee_password') {
    http_response_code(400);
    exit('Unsupported employee portal action.');
}

$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');
$passwordHash = (string) ($employee['password_hash'] ?? '');

if (!ucchr_password_verify($currentPassword, $passwordHash)) {
    set_flash('error', 'The current password is incorrect.');
} elseif ($newPassword !== $confirmPassword) {
    set_flash('error', 'The new password and confirmation do not match.');
} elseif (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
    set_flash('error', 'Use a new password containing 8 to 72 characters.');
} elseif (ucchr_password_verify($newPassword, $passwordHash)) {
    set_flash('error', 'Choose a password different from your current password.');
} else {
    $newHash = ucchr_password_hash($newPassword);
    $pdo->prepare(
        'UPDATE employee_accounts
         SET password_hash=?, must_change_password=0, failed_attempts=0,
             locked_until=NULL, password_changed_at=NOW(), updated_at=NOW()
         WHERE id=?'
    )->execute([$newHash, (int) $employee['account_id']]);
    $_SESSION['employee_auth_password_fingerprint'] = hash('sha256', $newHash);
    $_SESSION['employee_last_activity'] = time();
    set_flash('success', 'Your employee portal password was changed successfully.');
}
redirect('employee-portal.php?page=security');
