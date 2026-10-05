<?php

declare(strict_types=1);

// One-time, idempotent HR-confirmed correction. Run only after a database backup.
// Paid September 1–15 payroll remains on its frozen ₱150/hour snapshot.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

$employeeNo = 'EMP-1012';
$correctionStart = '2026-09-16';
$correctionEnd = '2026-09-27';
$priorEnd = '2026-09-15';
$newRate = 250.00;

try {
    $pdo->beginTransaction();
    $employeeStmt = $pdo->prepare('SELECT id, pay_type, basic_rate FROM employees WHERE employee_no=? FOR UPDATE');
    $employeeStmt->execute([$employeeNo]);
    $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee || (int) $employee['id'] !== 28 || $employee['pay_type'] !== 'Hourly') {
        throw new RuntimeException('EMP-1012 is missing or no longer has the expected Hourly setup. No correction was applied.');
    }
    $employeeId = (int) $employee['id'];

    $paidStmt = $pdo->prepare(
        'SELECT pr.id, pr.status FROM payroll_items pi
         JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
         WHERE pi.employee_id=? AND pr.period_start<=? AND pr.period_end>=?
         LIMIT 1 FOR UPDATE'
    );
    $paidStmt->execute([$employeeId, $correctionEnd, $correctionStart]);
    if ($paidStmt->fetch()) {
        throw new RuntimeException('A payroll run now covers the correction dates. No compensation history was changed.');
    }

    $historyStmt = $pdo->prepare(
        'SELECT id, employment_type, pay_type, basic_rate, effective_from, effective_to
         FROM employee_compensation_history
         WHERE employee_id=? ORDER BY effective_from, id FOR UPDATE'
    );
    $historyStmt->execute([$employeeId]);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    $prior = null;
    $corrected = null;
    $next = null;
    foreach ($history as $row) {
        if ($row['effective_from'] === '2026-09-12') {
            $prior = $row;
        } elseif ($row['effective_from'] === $correctionStart) {
            $corrected = $row;
        } elseif ($row['effective_from'] === '2026-09-28') {
            $next = $row;
        }
    }
    if (!$prior || (int) $prior['id'] !== 11 || $prior['employment_type'] !== 'Part-Time'
        || $prior['pay_type'] !== 'Hourly' || (float) $prior['basic_rate'] !== 150.0
        || !$next || $next['pay_type'] !== 'Hourly' || (float) $next['basic_rate'] !== 300.0) {
        throw new RuntimeException('Compensation history changed since it was inspected. No correction was applied.');
    }
    if ($corrected) {
        if ($prior['effective_to'] !== $priorEnd || $corrected['effective_to'] !== $correctionEnd
            || $corrected['pay_type'] !== 'Hourly' || (float) $corrected['basic_rate'] !== $newRate) {
            throw new RuntimeException('A different correction already exists for September 16. No correction was applied.');
        }
        $pdo->rollBack();
        echo "EMP-1012 compensation was already corrected; no changes made.\n";
        exit(0);
    }
    if ($prior['effective_to'] !== $correctionEnd) {
        throw new RuntimeException('The existing ₱150 rate no longer ends on September 27. No correction was applied.');
    }

    $update = $pdo->prepare('UPDATE employee_compensation_history SET effective_to=? WHERE id=? AND effective_to=?');
    $update->execute([$priorEnd, (int) $prior['id'], $correctionEnd]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The previous compensation record changed concurrently.');
    }
    $insert = $pdo->prepare(
        'INSERT INTO employee_compensation_history
            (employee_id, employment_type, pay_type, basic_rate, effective_from, effective_to,
             change_reason, changed_by, created_at)
         VALUES (?, "Part-Time", "Hourly", ?, ?, ?, ?, NULL, NOW())'
    );
    $insert->execute([
        $employeeId,
        $newRate,
        $correctionStart,
        $correctionEnd,
        'HR-confirmed correction of the unpaid September 16–27 hourly rate; earlier paid payroll remains frozen.',
    ]);
    $correctionId = (int) $pdo->lastInsertId();

    $audit = $pdo->prepare(
        'INSERT INTO activity_logs
            (user_id, action, module, record_id, description, old_values, new_values, created_at)
         VALUES (NULL, ?, "Payroll", ?, ?, ?, ?, NOW())'
    );
    $audit->execute([
        'Corrected effective compensation',
        (string) $employeeId,
        'EMP-1012 hourly rate corrected only for unpaid September 16–27, 2026; existing paid payroll and current employee rate unchanged.',
        json_encode(['history_id' => (int) $prior['id'], 'rate' => 150.0, 'effective_from' => $correctionStart, 'effective_to' => $correctionEnd], JSON_THROW_ON_ERROR),
        json_encode(['history_id' => $correctionId, 'rate' => $newRate, 'effective_from' => $correctionStart, 'effective_to' => $correctionEnd], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    echo "EMP-1012 hourly rate corrected to ₱250 for 2026-09-16 through 2026-09-27. Paid and current rates remain unchanged.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
