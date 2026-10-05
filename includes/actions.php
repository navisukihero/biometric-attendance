<?php

declare(strict_types=1);

require_once __DIR__ . '/attendance_schedule.php';
require_once __DIR__ . '/fingerprint_commands.php';
require_once __DIR__ . '/employee_auth.php';
$attendanceProcessingFile = __DIR__ . '/attendance_processing.php';
if (is_file($attendanceProcessingFile)) {
    require_once $attendanceProcessingFile;
}
$payrollEngineFile = __DIR__ . '/payroll_engine.php';
if (is_file($payrollEngineFile)) {
    require_once $payrollEngineFile;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

verify_csrf();
$action = (string) ($_POST['action'] ?? '');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$actor = current_user($pdo);
if ($userId < 1 || (string) ($actor['role'] ?? '') !== 'Administrator') {
    http_response_code(403);
    exit('Administrator access is required.');
}

function ucchr_action_valid_date(string $value): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $value;
}

function ucchr_action_audit(
    PDO $pdo,
    int $userId,
    string $action,
    string $module,
    int|string|null $recordId,
    string $description,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO activity_logs
                (user_id, action, module, record_id, description, old_values, new_values, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $userId ?: null,
            $action,
            $module,
            $recordId === null ? null : (string) $recordId,
            $description,
            $oldValues === null ? null : json_encode($oldValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $newValues === null ? null : json_encode($newValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    } catch (PDOException $error) {
        // Compatibility for installations that have not yet added the
        // structured activity columns. Do not lose the basic audit event.
        log_activity($pdo, $userId, $action . ' - ' . $description);
    }
}

function ucchr_action_numeric_setting(PDO $pdo, string $key, float $fallback): float
{
    $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key`=? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false && is_numeric($value) ? (float) $value : $fallback;
}

/**
 * Keep the legacy employees.daily_rate compatibility column meaningful while
 * basic_rate remains stored in its selected Daily, Hourly, or Monthly unit.
 */
function ucchr_action_legacy_daily_rate(PDO $pdo, string $payType, float $basicRate): float
{
    $regularHours = max(0.01, ucchr_action_numeric_setting($pdo, 'regular_hours_per_day', 8.0));
    $legacyMonthlyDays = max(0.01, ucchr_action_numeric_setting($pdo, 'working_day_basis', 30.0));
    return round(match ($payType) {
        'Hourly' => $basicRate * $regularHours,
        // Compatibility only: payroll itself prorates Monthly compensation
        // and derives attendance deductions from the assigned Work Schedule.
        'Monthly' => $basicRate / $legacyMonthlyDays,
        default => $basicRate,
    }, 2);
}

function ucchr_action_assert_not_finalized(
    PDO $pdo,
    string $startDate,
    string $endDate,
    ?int $employeeId = null
): void {
    $sql = 'SELECT pr.id, pr.status, a.scan_date, e.employee_no
            FROM payroll_item_attendance pia
            JOIN payroll_items pi ON pi.id=pia.payroll_item_id
            JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
            JOIN attendance a ON a.id=pia.attendance_id
            JOIN employees e ON e.id=a.employee_id
            WHERE pr.status IN ("For Review", "Approved", "Finalized", "Paid", "Released")
              AND a.scan_date BETWEEN ? AND ?';
    $parameters = [$startDate, $endDate];
    if ($employeeId !== null) {
        $sql .= ' AND a.employee_id=?';
        $parameters[] = $employeeId;
    }
    $sql .= ' LIMIT 1 FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    $locked = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($locked) {
        $details = 'Attendance for ' . $locked['employee_no'] . ' on ' . $locked['scan_date']
            . ' is included in ' . $locked['status'] . ' payroll run #' . $locked['id'] . '. ';
        if (in_array((string) $locked['status'], ['For Review', 'Approved'], true)) {
            throw new RuntimeException($details . 'Return that run to Draft before changing its attendance.');
        }
        throw new RuntimeException($details . 'The paid or finalized payroll record is read-only; reprocessing it would change an official calculation.');
    }

    // Payroll generated before payroll_item_attendance existed has no daily
    // link rows to inspect. Protect those legacy Released items by employee
    // and overlapping payroll period instead of leaving old history mutable.
    $legacySql = 'SELECT pr.id
                  FROM payroll_runs pr
                  JOIN payroll_items pi ON pi.payroll_run_id=pr.id
                  WHERE pr.status="Released"
                    AND pr.period_start<=? AND pr.period_end>=?';
    $legacyParameters = [$endDate, $startDate];
    if ($employeeId !== null) {
        $legacySql .= ' AND pi.employee_id=?';
        $legacyParameters[] = $employeeId;
    }
    $legacySql .= ' LIMIT 1 FOR UPDATE';
    $legacyStmt = $pdo->prepare($legacySql);
    $legacyStmt->execute($legacyParameters);
    if ($legacyStmt->fetchColumn() !== false) {
        throw new RuntimeException('This change overlaps a legacy released payroll. Its historical attendance and calculation are immutable.');
    }
}

function ucchr_action_reprocess_employee_period(PDO $pdo, int $employeeId, string $startDate, string $endDate): void
{
    if (!function_exists('attendance_process_employee_date')) {
        throw new RuntimeException('Attendance processing is unavailable. Install includes/attendance_processing.php first.');
    }
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    if ($end < $start || $start->diff($end)->days > 366) {
        throw new InvalidArgumentException('The affected date range is invalid or too large.');
    }
    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        attendance_process_employee_date(
            $pdo,
            $employeeId,
            $date->format('Y-m-d'),
            new DateTimeImmutable('now'),
            false
        );
    }
}

function ucchr_action_reprocess_date(PDO $pdo, string $date): void
{
    if (!function_exists('attendance_process_date')) {
        throw new RuntimeException('Attendance processing is unavailable. Install includes/attendance_processing.php first.');
    }
    attendance_process_date($pdo, $date, new DateTimeImmutable('now'), false);
}

require_once __DIR__ . '/integrated_admin_actions.php';

if ($action === 'save_employee') {
    $id = (int) ($_POST['id'] ?? 0);
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $dateOfBirth = trim((string) ($_POST['date_of_birth'] ?? '')) ?: null;
    $gender = trim((string) ($_POST['gender'] ?? 'Not specified'));
    $civilStatus = trim((string) ($_POST['civil_status'] ?? 'Single'));
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $position = trim((string) ($_POST['position'] ?? ''));
    $employmentType = trim((string) ($_POST['employment_type'] ?? ''));
    $payType = trim((string) ($_POST['pay_type'] ?? ''));
    $basicRateRaw = trim((string) ($_POST['basic_rate'] ?? ''));
    $basicRate = is_numeric($basicRateRaw) ? round((float) $basicRateRaw, 2) : -1;
    $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $status = $id === 0 ? 'Active' : trim((string) ($_POST['status'] ?? 'Active'));
    $effectiveFrom = trim((string) ($_POST['compensation_effective_from'] ?? date('Y-m-d')));
    $changeReason = trim((string) ($_POST['compensation_change_reason'] ?? ''));

    if (
        $firstName === '' || $lastName === '' || $position === ''
        || !in_array($employmentType, ['Full-Time', 'Part-Time'], true)
        || !in_array($payType, ['Daily', 'Hourly', 'Monthly'], true)
        || $basicRate <= 0 || $basicRate > 999999999.99
        || !in_array($status, ['Active', 'Inactive', 'On leave'], true)
        || !ucchr_action_valid_date($effectiveFrom)
        || $effectiveFrom > date('Y-m-d')
        || mb_strlen($changeReason) > 500
        || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
    ) {
        set_flash('error', 'Enter valid employee details, choose Daily, Hourly, or Monthly pay, and enter a positive Approved Rate. The rate note is limited to 500 characters and its effective date cannot be in the future.');
        redirect($id ? "app.php?page=employee_edit&id={$id}" : 'app.php?page=employee_new');
    }

    $legacyDailyRate = ucchr_action_legacy_daily_rate($pdo, $payType, $basicRate);

    try {
        $pdo->beginTransaction();
        if ($id > 0) {
            $existingStmt = $pdo->prepare('SELECT * FROM employees WHERE id=? LIMIT 1 FOR UPDATE');
            $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('The employee record could not be found.');
            }

            $stmt = $pdo->prepare(
                'UPDATE employees
                 SET first_name=?, middle_name=?, last_name=?, date_of_birth=?, gender=?,
                     civil_status=?, department_id=?, position=?, employment_type=?, pay_type=?,
                     basic_rate=?, daily_rate=?, contact_number=?, email=?, status=?
                 WHERE id=?'
            );
            $stmt->execute([
                $firstName,
                $middleName,
                $lastName,
                $dateOfBirth,
                $gender,
                $civilStatus,
                $departmentId > 0 ? $departmentId : null,
                $position,
                $employmentType,
                $payType,
                $basicRate,
                $legacyDailyRate,
                $contactNumber,
                $email,
                $status,
                $id,
            ]);

            $compensationChanged = (string) ($existing['employment_type'] ?? '') !== $employmentType
                || (string) ($existing['pay_type'] ?? 'Daily') !== $payType
                || abs((float) ($existing['basic_rate'] ?? $existing['daily_rate']) - $basicRate) > 0.004;
            if ($compensationChanged) {
                if ($changeReason === '') {
                    throw new InvalidArgumentException('Enter a reason or approval reference for the compensation change.');
                }
                $latestEffectiveStmt = $pdo->prepare(
                    'SELECT effective_from FROM employee_compensation_history
                     WHERE employee_id=? ORDER BY effective_from DESC, id DESC LIMIT 1 FOR UPDATE'
                );
                $latestEffectiveStmt->execute([$id]);
                $latestEffective = $latestEffectiveStmt->fetchColumn();
                if ($latestEffective !== false && $latestEffective !== null && $effectiveFrom < (string) $latestEffective) {
                    throw new InvalidArgumentException(
                        'The new rate cannot start before the latest compensation record (' . (string) $latestEffective . ').'
                    );
                }
                $pdo->prepare(
                    'UPDATE employee_compensation_history
                     SET effective_to=DATE_SUB(?, INTERVAL 1 DAY)
                     WHERE employee_id=? AND effective_from<?
                       AND (effective_to IS NULL OR effective_to>=?)'
                )->execute([$effectiveFrom, $id, $effectiveFrom, $effectiveFrom]);
                $pdo->prepare(
                    'INSERT INTO employee_compensation_history
                        (employee_id, employment_type, pay_type, basic_rate, effective_from,
                         effective_to, change_reason, changed_by, created_at)
                     VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        employment_type=VALUES(employment_type), pay_type=VALUES(pay_type),
                        basic_rate=VALUES(basic_rate), change_reason=VALUES(change_reason),
                        changed_by=VALUES(changed_by)'
                )->execute([
                    $id,
                    $employmentType,
                    $payType,
                    $basicRate,
                    $effectiveFrom,
                    $changeReason !== '' ? $changeReason : 'Employee compensation updated by HR/Admin',
                    $userId,
                ]);
            }

            if ($status === 'Inactive') {
                $pdo->prepare('UPDATE employee_accounts SET account_status="Disabled" WHERE employee_id=?')->execute([$id]);
                $pdo->prepare(
                    'UPDATE employee_account_tokens t
                     JOIN employee_accounts ea ON ea.id=t.employee_account_id
                     SET t.used_at=NOW()
                     WHERE ea.employee_id=? AND t.used_at IS NULL'
                )->execute([$id]);
            }

            $newValues = [
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'department_id' => $departmentId > 0 ? $departmentId : null,
                'position' => $position,
                'employment_type' => $employmentType,
                'pay_type' => $payType,
                'basic_rate' => $basicRate,
                'status' => $status,
            ];
            ucchr_action_audit(
                $pdo,
                $userId,
                $compensationChanged ? 'Employee and compensation updated' : 'Employee updated',
                'Employees',
                $id,
                'Updated employee ' . (string) $existing['employee_no'],
                array_intersect_key($existing, $newValues),
                $newValues
            );
            $pdo->commit();
            set_flash('success', 'Employee record and approved compensation information updated.');
        } else {
            $next = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM employees')->fetchColumn();
            $employeeNo = 'EMP-' . str_pad((string) (1000 + $next), 4, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare(
                'INSERT INTO employees
                    (employee_no, first_name, middle_name, last_name, date_of_birth, gender,
                     civil_status, department_id, position, employment_type, pay_type,
                     basic_rate, daily_rate, contact_number, email, status,
                     fingerprint_status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Not enrolled", NOW())'
            );
            $stmt->execute([
                $employeeNo,
                $firstName,
                $middleName,
                $lastName,
                $dateOfBirth,
                $gender,
                $civilStatus,
                $departmentId > 0 ? $departmentId : null,
                $position,
                $employmentType,
                $payType,
                $basicRate,
                $legacyDailyRate,
                $contactNumber,
                $email,
                'Active',
            ]);
            $id = (int) $pdo->lastInsertId();

            // Prefer the employee ID as the AS608 slot, then fall back to the
            // first unused slot. Keeping slots within 1..127 supports common
            // AS608 modules and the unique database index prevents collisions.
            $usedSlots = array_flip(array_map('intval', $pdo->query('SELECT fingerprint_slot FROM fingerprint_registrations')->fetchAll(PDO::FETCH_COLUMN)));
            // Auxiliary LEFT/RIGHT/UPPER/LOWER pages are physical sensor slots
            // too. Excluding only canonical registrations could assign a new
            // employee's CENTER page on top of another employee's template.
            foreach ($pdo->query('SELECT sensor_slot FROM fingerprint_template_slots')->fetchAll(PDO::FETCH_COLUMN) as $templateSlot) {
                $usedSlots[(int) $templateSlot] = true;
            }
            foreach ($pdo->query('SELECT fingerprint_code FROM employees WHERE fingerprint_code REGEXP "^[0-9]+$"')->fetchAll(PDO::FETCH_COLUMN) as $legacySlot) {
                $usedSlots[(int) $legacySlot] = true;
            }
            $slot = $id <= 127 && !isset($usedSlots[$id]) ? $id : 0;
            if ($slot === 0) {
                for ($candidate = 1; $candidate <= 127; $candidate++) {
                    if (!isset($usedSlots[$candidate])) {
                        $slot = $candidate;
                        break;
                    }
                }
            }
            if ($slot === 0) {
                throw new RuntimeException('The fingerprint sensor has no free template slots.');
            }
            $pdo->prepare('UPDATE employees SET fingerprint_code=? WHERE id=?')->execute([(string) $slot, $id]);
            $pdo->prepare('INSERT INTO fingerprint_registrations (employee_id, fingerprint_slot, mapping_status) VALUES (?, ?, "Reserved")')->execute([$id, $slot]);
            $pdo->prepare(
                'INSERT INTO employee_accounts
                    (employee_id, username, password_hash, account_status, must_change_password, created_by, created_at, updated_at)
                 VALUES (?, ?, NULL, "Pending", 1, ?, NOW(), NOW())'
            )->execute([$id, $employeeNo, $userId ?: null]);
            $pdo->prepare(
                'INSERT INTO employee_compensation_history
                    (employee_id, employment_type, pay_type, basic_rate, effective_from,
                     change_reason, changed_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $id,
                $employmentType,
                $payType,
                $basicRate,
                $effectiveFrom,
                $changeReason !== '' ? $changeReason : 'Initial approved compensation',
                $userId,
            ]);
            ucchr_action_audit(
                $pdo,
                $userId,
                'Employee added',
                'Employees',
                $id,
                'Registered ' . $employeeNo . ' and created a pending employee portal account.',
                null,
                [
                    'employee_no' => $employeeNo,
                    'name' => trim($firstName . ' ' . $lastName),
                    'employment_type' => $employmentType,
                    'pay_type' => $payType,
                    'basic_rate' => $basicRate,
                    'status' => 'Active',
                ]
            );
            $pdo->commit();
            set_flash('success', "Employee {$employeeNo} saved as Active and is now available in Work Schedule. Review the record, then click Sync/Enroll Fingerprint to send the enrollment command to the ESP32.");
            redirect("app.php?page=employee_edit&id={$id}");
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Employee could not be saved: ' . $error->getMessage());
        redirect($id ? "app.php?page=employee_edit&id={$id}" : 'app.php?page=employee_new');
    }
    redirect('app.php?page=employees');
}

if ($action === 'delete_employee') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id < 1 || (string) ($_POST['confirm_delete'] ?? '') !== 'DELETE') {
        set_flash('error', 'Employee deletion was not confirmed.');
        redirect('app.php?page=employees');
    }

    try {
        $pdo->beginTransaction();

        $employeeStmt = $pdo->prepare('SELECT id FROM employees WHERE id=? FOR UPDATE');
        $employeeStmt->execute([$id]);
        if (!$employeeStmt->fetchColumn()) {
            throw new DomainException('Employee record was not found.');
        }

        $activeCommandStmt = $pdo->prepare(
            'SELECT id FROM device_commands WHERE employee_id=? AND status IN ("Pending", "Running") LIMIT 1 FOR UPDATE'
        );
        $activeCommandStmt->execute([$id]);
        if ($activeCommandStmt->fetchColumn()) {
            throw new DomainException('Finish or cancel the active biometric command before deleting this employee.');
        }

        // A payroll run may contain other employees. Remove only this employee's
        // item first; its attendance links cascade with the item.
        $runIdsStmt = $pdo->prepare('SELECT DISTINCT payroll_run_id FROM payroll_items WHERE employee_id=? FOR UPDATE');
        $runIdsStmt->execute([$id]);
        $affectedRunIds = array_map('intval', $runIdsStmt->fetchAll(PDO::FETCH_COLUMN));
        $scopedRunIdsStmt = $pdo->prepare('SELECT id FROM payroll_runs WHERE scope_employee_id=? FOR UPDATE');
        $scopedRunIdsStmt->execute([$id]);
        $affectedRunIds = array_unique(array_merge(
            $affectedRunIds,
            array_map('intval', $scopedRunIdsStmt->fetchAll(PDO::FETCH_COLUMN))
        ));
        sort($affectedRunIds, SORT_NUMERIC);
        $lockRunStmt = $pdo->prepare('SELECT id FROM payroll_runs WHERE id=? FOR UPDATE');
        foreach ($affectedRunIds as $runId) {
            $lockRunStmt->execute([$runId]);
        }

        foreach (['payroll_items', 'attendance_logs', 'employee_deduction_entries', 'employee_compensation_history'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE employee_id=?")->execute([$id]);
        }

        $remainingItemsStmt = $pdo->prepare('SELECT COUNT(*) FROM payroll_items WHERE payroll_run_id=?');
        $deleteRunStmt = $pdo->prepare('DELETE FROM payroll_runs WHERE id=?');
        $clearRunScopeStmt = $pdo->prepare('UPDATE payroll_runs SET scope_employee_id=NULL WHERE id=? AND scope_employee_id=?');
        foreach ($affectedRunIds as $runId) {
            $remainingItemsStmt->execute([$runId]);
            if ((int) $remainingItemsStmt->fetchColumn() === 0) {
                $deleteRunStmt->execute([$runId]);
            } else {
                $clearRunScopeStmt->execute([$runId, $id]);
            }
        }

        // Remaining employee-owned records (including portal access, schedules,
        // attendance, biometric mappings, leave, and OT) cascade via InnoDB FKs.
        $deleteEmployeeStmt = $pdo->prepare('DELETE FROM employees WHERE id=?');
        $deleteEmployeeStmt->execute([$id]);
        if ($deleteEmployeeStmt->rowCount() !== 1) {
            throw new RuntimeException('Employee deletion did not complete.');
        }

        ucchr_action_audit($pdo, $userId, 'DELETE', 'Employees', $id, 'Permanently deleted employee and linked records.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof DomainException) {
            set_flash('error', $error->getMessage());
        } else {
            error_log('Employee deletion failed for record #' . $id . ': ' . $error->getMessage());
            set_flash('error', 'Employee could not be deleted. No records were removed. Check the server log.');
        }
        redirect('app.php?page=employees');
    }
    set_flash('success', 'Employee and linked records deleted. Clear the physical AS608 fingerprint templates separately.');
    redirect('app.php?page=employees');
}

if ($action === 'issue_employee_portal_link') {
    $id = (int) ($_POST['id'] ?? 0);
    $requestedPurpose = (string) ($_POST['purpose'] ?? 'Activation');
    try {
        $employeeStmt = $pdo->prepare(
            'SELECT employee_no, first_name, last_name, status FROM employees WHERE id=? LIMIT 1'
        );
        $employeeStmt->execute([$id]);
        $accountEmployee = $employeeStmt->fetch();
        if (!$accountEmployee || (string) $accountEmployee['status'] === 'Inactive') {
            throw new RuntimeException('Only a current employee can receive portal access.');
        }
        $issued = employee_issue_account_token(
            $pdo,
            $id,
            in_array($requestedPurpose, ['Activation', 'Reset'], true) ? $requestedPurpose : 'Activation',
            $userId ?: null
        );
        $_SESSION['employee_portal_link'] = [
            'employee_id' => $id,
            'url' => employee_account_link((string) $issued['token']),
            'purpose' => (string) $issued['purpose'],
            'expires_at' => (string) $issued['expires_at'],
        ];
        log_activity(
            $pdo,
            $userId,
            'Issued employee portal ' . strtolower((string) $issued['purpose'])
                . ' link for ' . $accountEmployee['employee_no']
        );
        set_flash(
            'success',
            $issued['purpose'] . ' link created. Copy the private link below and give it only to '
                . $accountEmployee['first_name'] . ' ' . $accountEmployee['last_name'] . '.'
        );
    } catch (Throwable $error) {
        set_flash('error', 'Employee portal link could not be created: ' . $error->getMessage());
    }
    redirect('app.php?page=employee_edit&id=' . $id . '#employee-account');
}

if ($action === 'set_employee_portal_password') {
    $id = (int) ($_POST['id'] ?? 0);
    $temporaryPassword = (string) ($_POST['temporary_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_temporary_password'] ?? '');
    try {
        $employeeStmt = $pdo->prepare(
            'SELECT employee_no, status FROM employees WHERE id=? LIMIT 1'
        );
        $employeeStmt->execute([$id]);
        $accountEmployee = $employeeStmt->fetch();
        if (!$accountEmployee || (string) $accountEmployee['status'] === 'Inactive') {
            throw new RuntimeException('Only a current employee can receive portal access.');
        }
        if ($temporaryPassword !== $confirmPassword) {
            throw new InvalidArgumentException('The temporary password and confirmation do not match.');
        }
        $account = employee_set_temporary_password($pdo, $id, $temporaryPassword, $userId ?: null);
        log_activity($pdo, $userId, 'Set temporary employee portal password for ' . $accountEmployee['employee_no']);
        set_flash(
            'success',
            'Employee portal activated. Username: ' . $account['username']
                . '. Give the temporary password privately; the employee must replace it after login.'
        );
    } catch (Throwable $error) {
        set_flash('error', 'Temporary password could not be saved: ' . $error->getMessage());
    }
    redirect('app.php?page=employee_edit&id=' . $id . '#employee-account');
}

if ($action === 'set_employee_portal_status') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['account_status'] ?? 'Disabled');
    try {
        employee_set_account_status($pdo, $id, $status);
        log_activity($pdo, $userId, ($status === 'Active' ? 'Enabled' : 'Disabled') . ' employee portal account #' . $id);
        set_flash('success', $status === 'Active'
            ? 'Employee portal access enabled.'
            : 'Employee portal access disabled. Existing employee sessions can no longer open the portal.');
    } catch (Throwable $error) {
        set_flash('error', 'Employee portal status could not be changed: ' . $error->getMessage());
    }
    redirect('app.php?page=employee_edit&id=' . $id . '#employee-account');
}

if ($action === 'enroll_fingerprint') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $queued = fingerprint_queue_enrollment($pdo, $id, $userId ?: null);
        if ($queued['alreadyQueued']) {
            set_flash(
                'success',
                'Fingerprint enrollment is already queued for AS608 slot '
                    . $queued['slot'] . '. At the terminal, press BTN1, then follow Center, Left, Right, Upper, and Lower thumb prompts.'
            );
        } else {
            log_activity(
                $pdo,
                $userId,
                'Queued fingerprint enrollment v' . $queued['version']
                    . ' for employee #' . $id . ' in slot ' . $queued['slot']
            );
            set_flash(
                'success',
                'Fingerprint enrollment queued for AS608 slot ' . $queued['slot']
                    . '. Press BTN1 on the biometric terminal, then capture the thumb at Center, Left, Right, Upper, and Lower positions.'
            );
        }
    } catch (Throwable $error) {
        set_flash('error', 'Enrollment could not be queued: ' . $error->getMessage());
    }
    redirect("app.php?page=employee_edit&id={$id}");
}

if ($action === 'save_profile') {
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $user = current_user($pdo);

    if ($name === '' || !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', 'Enter a valid name, email, and username. Usernames may contain letters, numbers, dots, underscores, and hyphens.');
        redirect('app.php?page=profile');
    }
    if (!ucchr_password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
        set_flash('error', 'The current password is required to change account information.');
        redirect('app.php?page=profile');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE (username=? OR email=?) AND id<>? LIMIT 1');
    $stmt->execute([$username, $email, $userId]);
    if ($stmt->fetch()) {
        set_flash('error', 'That username or email address is already used by another account.');
        redirect('app.php?page=profile');
    }

    $stmt = $pdo->prepare('UPDATE users SET full_name=?, username=?, email=? WHERE id=?');
    $stmt->execute([$name, $username, $email, $userId]);
    log_activity($pdo, $userId, 'Updated account name, username, or email');
    set_flash('success', 'Account information saved to the database. Use the new username on your next login.');
    redirect('app.php?page=profile');
}

if ($action === 'update_password') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $user = current_user($pdo);
    if (
        !ucchr_password_verify($current, (string) ($user['password_hash'] ?? ''))
        || strlen($new) < 8
        || strlen($new) > 72
        || $new !== $confirm
    ) {
        set_flash('error', 'Check the current password; the new password must match and contain 8 to 72 characters.');
    } elseif (ucchr_password_verify($new, (string) ($user['password_hash'] ?? ''))) {
        set_flash('error', 'Choose a new password that is different from the current password.');
    } else {
        $newHash = ucchr_password_hash($new);
        $stmt = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
        $stmt->execute([$newHash, $userId]);
        $_SESSION['auth_password_fingerprint'] = hash('sha256', $newHash);
        log_activity($pdo, $userId, 'Changed account password');
        set_flash('success', 'Password updated.');
    }
    redirect('app.php?page=profile');
}

if ($action === 'save_weekly_schedule') {
    $employeeId = (int) ($_POST['employee_id'] ?? 0);
    $employmentType = trim((string) ($_POST['employment_type'] ?? ''));
    $payType = trim((string) ($_POST['pay_type'] ?? ''));
    $basicRateRaw = trim((string) ($_POST['basic_rate'] ?? ''));
    $basicRate = is_numeric($basicRateRaw) ? round((float) $basicRateRaw, 2) : -1;
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $submittedWorkDays = is_array($_POST['work_days'] ?? null) ? $_POST['work_days'] : [];
    $workDays = array_values(array_unique(array_filter(
        array_map(static fn(mixed $day): string => trim((string) $day), $submittedWorkDays),
        static fn(string $day): bool => in_array($day, $days, true)
    )));
    $submittedStarts = is_array($_POST['shift_start'] ?? null) ? $_POST['shift_start'] : [];
    $submittedEnds = is_array($_POST['shift_end'] ?? null) ? $_POST['shift_end'] : [];
    $submittedBreaks = is_array($_POST['break_minutes'] ?? null) ? $_POST['break_minutes'] : [];
    $submittedPeriodStarts = is_array($_POST['period_start'] ?? null) ? $_POST['period_start'] : [];
    $submittedPeriodEnds = is_array($_POST['period_end'] ?? null) ? $_POST['period_end'] : [];

    $scheduleByDay = [];
    foreach ($days as $day) {
        $isWork = in_array($day, $workDays, true);
        $periods = [];
        if ($isWork && $employmentType === 'Part-Time') {
            $periodStarts = is_array($submittedPeriodStarts[$day] ?? null)
                ? array_values($submittedPeriodStarts[$day])
                : [];
            $periodEnds = is_array($submittedPeriodEnds[$day] ?? null)
                ? array_values($submittedPeriodEnds[$day])
                : [];
            $periodCount = max(count($periodStarts), count($periodEnds));
            for ($index = 0; $index < $periodCount; $index++) {
                $periodStart = trim((string) ($periodStarts[$index] ?? ''));
                $periodEnd = trim((string) ($periodEnds[$index] ?? ''));
                if ($periodStart === '' && $periodEnd === '') {
                    continue;
                }
                $periods[] = ['period_start' => $periodStart, 'period_end' => $periodEnd];
            }
        }
        $scheduleByDay[$day] = [
            'schedule_type' => $isWork ? 'Work' : 'Off',
            'shift_start' => $isWork && $employmentType !== 'Part-Time'
                ? trim((string) ($submittedStarts[$day] ?? ''))
                : null,
            'shift_end' => $isWork && $employmentType !== 'Part-Time'
                ? trim((string) ($submittedEnds[$day] ?? ''))
                : null,
            'break_minutes' => $isWork && $employmentType !== 'Part-Time'
                ? trim((string) ($submittedBreaks[$day] ?? ''))
                : 0,
            'periods' => $periods,
        ];
    }

    try {
        if (
            !in_array($employmentType, ['Full-Time', 'Part-Time'], true)
            || !in_array($payType, ['Daily', 'Hourly', 'Monthly'], true)
            || !$workDays
            || $basicRate <= 0 || $basicRate > 999999999.99
        ) {
            throw new InvalidArgumentException(
                'Choose Full-Time or Part-Time, Daily, Hourly, or Monthly pay, at least one workday, a positive Approved Rate, and valid daily time periods.'
            );
        }
        $pdo->beginTransaction();
        foreach ($scheduleByDay as $scheduleDay => $scheduleRow) {
            if ($scheduleRow['schedule_type'] !== 'Work') {
                continue;
            }
            if ($employmentType === 'Part-Time') {
                $periods = attendance_validate_schedule_periods(
                    (array) $scheduleRow['periods'],
                    $scheduleDay
                );
                $summary = attendance_schedule_period_summary($periods);
                $scheduleByDay[$scheduleDay]['periods'] = $periods;
                $scheduleByDay[$scheduleDay]['shift_start'] = $summary['shift_start'];
                $scheduleByDay[$scheduleDay]['shift_end'] = $summary['shift_end'];
                $scheduleByDay[$scheduleDay]['break_minutes'] = $summary['gap_minutes'];
                continue;
            }
            $breakRaw = (string) $scheduleRow['break_minutes'];
            if (!preg_match('/^\d+$/', $breakRaw) || (int) $breakRaw > 480) {
                throw new InvalidArgumentException($scheduleDay . ' requires break minutes between 0 and 480.');
            }
            $startParts = array_map('intval', explode(':', (string) $scheduleRow['shift_start']));
            $endParts = array_map('intval', explode(':', (string) $scheduleRow['shift_end']));
            if (count($startParts) < 2 || count($endParts) < 2) {
                throw new InvalidArgumentException($scheduleDay . ' requires valid Expected In and Expected Out times.');
            }
            $startMinute = ($startParts[0] * 60) + $startParts[1];
            $endMinute = ($endParts[0] * 60) + $endParts[1];
            if ($endMinute <= $startMinute) {
                $endMinute += 1440;
            }
            if ((int) $breakRaw >= ($endMinute - $startMinute)) {
                throw new InvalidArgumentException($scheduleDay . ' break must be shorter than the shift.');
            }
            $scheduleByDay[$scheduleDay]['break_minutes'] = (int) $breakRaw;
        }
        $employeeStmt = $pdo->prepare(
            'SELECT employee_no, first_name, last_name, employment_type,
                    pay_type, basic_rate, daily_rate
             FROM employees
             WHERE id=? AND status="Active"
             LIMIT 1 FOR UPDATE'
        );
        $employeeStmt->execute([$employeeId]);
        $employee = $employeeStmt->fetch();
        if (!$employee) {
            throw new InvalidArgumentException('The selected active employee could not be found.');
        }

        $compensationChanged = (string) ($employee['employment_type'] ?? '') !== $employmentType
            || (string) ($employee['pay_type'] ?? 'Daily') !== $payType
            || abs((float) ($employee['basic_rate'] ?? $employee['daily_rate']) - $basicRate) > 0.004;
        if ($compensationChanged) {
            $effectiveFrom = date('Y-m-d');
            $legacyDailyRate = ucchr_action_legacy_daily_rate($pdo, $payType, $basicRate);
            $pdo->prepare(
                'UPDATE employees
                 SET employment_type=?, pay_type=?, basic_rate=?, daily_rate=?
                 WHERE id=?'
            )->execute([$employmentType, $payType, $basicRate, $legacyDailyRate, $employeeId]);
            $pdo->prepare(
                'UPDATE employee_compensation_history
                 SET effective_to=DATE_SUB(?, INTERVAL 1 DAY)
                 WHERE employee_id=? AND effective_from<?
                   AND (effective_to IS NULL OR effective_to>=?)'
            )->execute([$effectiveFrom, $employeeId, $effectiveFrom, $effectiveFrom]);
            $pdo->prepare(
                'INSERT INTO employee_compensation_history
                    (employee_id, employment_type, pay_type, basic_rate, effective_from,
                     effective_to, change_reason, changed_by, created_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    employment_type=VALUES(employment_type), pay_type=VALUES(pay_type),
                    basic_rate=VALUES(basic_rate), change_reason=VALUES(change_reason),
                    changed_by=VALUES(changed_by)'
            )->execute([
                $employeeId,
                $employmentType,
                $payType,
                $basicRate,
                $effectiveFrom,
                'Employment and pay setup confirmed with weekly schedule assignment',
                $userId,
            ]);
        }

        $syncedRecords = attendance_save_weekly_schedule($pdo, $employeeId, $scheduleByDay);
        ucchr_action_audit(
            $pdo,
            $userId,
            'Employee schedule assigned',
            'Work Schedule',
            $employeeId,
            'Assigned a complete flexible weekly schedule and confirmed employment/pay setup for ' . $employee['employee_no'] . '.',
            [
                'employment_type' => $employee['employment_type'],
                'pay_type' => $employee['pay_type'],
                'basic_rate' => $employee['basic_rate'],
            ],
            [
                'employment_type' => $employmentType,
                'pay_type' => $payType,
                'basic_rate' => $basicRate,
                'work_days' => $workDays,
            ]
        );
        $pdo->commit();

        $message = 'Weekly schedule saved for ' . $employee['first_name'] . ' ' . $employee['last_name']
            . '. Employment type, Pay Type, Approved Rate, flexible daily hours, Rest Days, and Expected In/Out are now linked to attendance and payroll.';
        if ($syncedRecords > 0) {
            $message .= ' ' . $syncedRecords . ' current attendance record'
                . ($syncedRecords === 1 ? ' was' : 's were') . ' recalculated automatically.';
        }
        set_flash('success', $message);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash(
            'error',
            $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : 'The weekly schedule could not be saved. Please try again.'
        );
    }
    redirect('app.php?page=schedule&employee_id=' . $employeeId . '#schedule-assignment');
}

if ($action === 'save_settings') {
    $settings = ['institution_name', 'payroll_day', 'grace_minutes', 'timezone'];
    $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    foreach ($settings as $key) {
        $stmt->execute([$key, trim((string) ($_POST[$key] ?? ''))]);
    }
    log_activity($pdo, $userId, 'Updated system settings');
    set_flash('success', 'System settings saved.');
    redirect('app.php?page=settings');
}
