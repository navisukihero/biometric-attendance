<?php

declare(strict_types=1);

// One-time HR-requested correction of unpaid compensation history only.
// Run after a verified backup. Paid/Released payroll snapshots are immutable.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

try {
    $pdo->beginTransaction();
    $employee = $pdo->prepare('SELECT id, pay_type, basic_rate FROM employees WHERE employee_no=? FOR UPDATE');
    $employee->execute(['EMP-1012']);
    $current = $employee->fetch(PDO::FETCH_ASSOC);
    if (!$current || (int) $current['id'] !== 28 || $current['pay_type'] !== 'Hourly'
        || (float) $current['basic_rate'] !== 300.0) {
        throw new RuntimeException('EMP-1012 current Employee Records rate is not the expected ₱300/hour. No change was made.');
    }

    $paid = $pdo->prepare(
        'SELECT pr.id FROM payroll_items pi JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
         WHERE pi.employee_id=? AND pr.period_start<=? AND pr.period_end>=?
         LIMIT 1 FOR UPDATE'
    );
    $paid->execute([28, '2026-09-27', '2026-09-16']);
    if ($paid->fetch()) {
        throw new RuntimeException('A payroll run covers September 16–27. Compensation was not changed.');
    }

    $history = $pdo->prepare(
        'SELECT id, employment_type, pay_type, basic_rate, effective_from, effective_to
         FROM employee_compensation_history WHERE employee_id=?
         ORDER BY effective_from, id FOR UPDATE'
    );
    $history->execute([28]);
    $target = null;
    $following = null;
    foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['effective_from'] === '2026-09-16') {
            $target = $row;
        } elseif ($row['effective_from'] === '2026-09-28') {
            $following = $row;
        }
    }
    if (!$target || (int) $target['id'] !== 15 || $target['effective_to'] !== '2026-09-27'
        || $target['employment_type'] !== 'Part-Time' || $target['pay_type'] !== 'Hourly'
        || !$following || (float) $following['basic_rate'] !== 300.0) {
        throw new RuntimeException('Dated compensation no longer matches the inspected records. No change was made.');
    }
    if ((float) $target['basic_rate'] === 300.0) {
        $pdo->rollBack();
        echo "EMP-1012 already has ₱300/hour for September 16–27; no changes made.\n";
        exit(0);
    }
    if ((float) $target['basic_rate'] !== 250.0) {
        throw new RuntimeException('The old rate is not ₱250/hour. No change was made.');
    }

    $update = $pdo->prepare(
        'UPDATE employee_compensation_history
         SET basic_rate=300.00, change_reason=? WHERE id=? AND basic_rate=250.00'
    );
    $update->execute([
        'HR-confirmed ₱300/hour effective September 16 for unpaid dates; paid history is unchanged.',
        15,
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The compensation row changed concurrently. No change was made.');
    }

    $audit = $pdo->prepare(
        'INSERT INTO activity_logs
           (user_id, action, module, record_id, description, old_values, new_values, created_at)
         VALUES (NULL, ?, "Payroll", ?, ?, ?, ?, NOW())'
    );
    $audit->execute([
        'Corrected effective compensation',
        '28',
        'HR-confirmed EMP-1012 rate of ₱300/hour for unpaid September 16–27; Paid and Released payroll untouched.',
        json_encode(['history_id' => 15, 'rate' => 250.0, 'effective_from' => '2026-09-16', 'effective_to' => '2026-09-27'], JSON_THROW_ON_ERROR),
        json_encode(['history_id' => 15, 'rate' => 300.0, 'effective_from' => '2026-09-16', 'effective_to' => '2026-09-27'], JSON_THROW_ON_ERROR),
    ]);
    $pdo->commit();
    echo "EMP-1012 approved rate is ₱300/hour from September 16. Paid and Released payroll untouched.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
