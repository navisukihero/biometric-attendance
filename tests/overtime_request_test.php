<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/overtime_requests.php';

function overtime_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo->beginTransaction();
try {
    overtime_test_assert(ucchr_overtime_hours_to_minutes('1.50') === 90, '1.50 hours must become 90 minutes');
    overtime_test_assert(ucchr_overtime_minutes_label(90) === '1h 30m', '90 minutes must have a readable label');

    $employeeId = (int) $pdo->query('SELECT id FROM employees ORDER BY id LIMIT 1')->fetchColumn();
    overtime_test_assert($employeeId > 0, 'An employee is required for the overtime integration test');

    // Executes the production automatic-link query under a real transaction,
    // even when the fixture has no currently eligible overtime row.
    $candidate = ucchr_overtime_find_eligible_attendance($pdo, $employeeId, 1);
    overtime_test_assert(is_array($candidate), 'Eligible-attendance lookup must return an array');

    $testDate = new DateTimeImmutable('2099-12-31');
    $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM overtime_requests WHERE employee_id=? AND attendance_date=?');
    do {
        $date = $testDate->format('Y-m-d');
        $existsStmt->execute([$employeeId, $date]);
        $exists = (int) $existsStmt->fetchColumn() > 0;
        $testDate = $testDate->modify('-1 day');
    } while ($exists);

    $pdo->prepare(
        'INSERT INTO overtime_requests
            (employee_id, attendance_id, attendance_date, potential_minutes, requested_minutes,
             approved_minutes, status, request_source, reason, created_at, updated_at)
         VALUES (?, NULL, ?, 120, 90, 0, "Pending", "Employee", ?, NOW(), NOW())'
    )->execute([$employeeId, $date, 'Overtime document ownership regression test.']);
    $requestId = (int) $pdo->lastInsertId();

    $ownedDocument = ucchr_overtime_request_document($pdo, $requestId, $employeeId);
    overtime_test_assert((int) ($ownedDocument['id'] ?? 0) === $requestId, 'Employee must be able to read their own overtime form');
    overtime_test_assert((string) ($ownedDocument['employee_name'] ?? '') !== '', 'Official form must include the employee name');
    overtime_test_assert(
        ucchr_overtime_request_document($pdo, $requestId, -1) === [],
        'Employee-scoped form lookup must not expose a request to another identity'
    );
    overtime_test_assert(
        (int) (ucchr_overtime_request_document($pdo, $requestId)['id'] ?? 0) === $requestId,
        'Authenticated admin lookup must retrieve the same synchronized request'
    );

    echo "overtime_request_test: PASS\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
