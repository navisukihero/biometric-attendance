<?php

declare(strict_types=1);

/**
 * Convert the employee-facing decimal-hours input to whole minutes.
 * The database and payroll engine intentionally keep minutes as their source
 * of truth to avoid floating-point payroll calculations.
 */
function ucchr_overtime_hours_to_minutes(string $rawHours): int
{
    $rawHours = trim($rawHours);
    if (!preg_match('/^(?:\d{1,2})(?:\.\d{1,2})?$/', $rawHours)) {
        throw new InvalidArgumentException('Enter requested overtime as hours, for example 1 or 1.5.');
    }

    $hours = (float) $rawHours;
    $minutes = (int) round($hours * 60);
    if ($hours <= 0 || $hours > 24 || $minutes < 1 || $minutes > 1440) {
        throw new InvalidArgumentException('Requested overtime must be greater than 0 and no more than 24 hours.');
    }

    return $minutes;
}

function ucchr_overtime_minutes_label(int $minutes): string
{
    $minutes = max(0, $minutes);
    $hours = intdiv($minutes, 60);
    $remainder = $minutes % 60;
    if ($hours === 0) {
        return $remainder . ' minute' . ($remainder === 1 ? '' : 's');
    }
    if ($remainder === 0) {
        return $hours . ' hour' . ($hours === 1 ? '' : 's');
    }

    return $hours . 'h ' . str_pad((string) $remainder, 2, '0', STR_PAD_LEFT) . 'm';
}

/**
 * Find the newest attendance record that can safely receive this request.
 * This keeps the employee form limited to hours and reason while preserving
 * the date-specific attendance/payroll relationship used by the payroll engine.
 * Call inside a transaction because the selected attendance row is locked.
 *
 * @return array<string, mixed>
 */
function ucchr_overtime_find_eligible_attendance(PDO $pdo, int $employeeId, int $requestedMinutes): array
{
    $stmt = $pdo->prepare(
        'SELECT a.id, a.scan_date, a.potential_overtime_minutes
         FROM attendance a
         LEFT JOIN overtime_requests ot
           ON ot.employee_id=a.employee_id
          AND ot.attendance_date=a.scan_date
         WHERE a.employee_id=?
           AND a.time_out IS NOT NULL
           AND a.potential_overtime_minutes>=?
           AND (
                ot.id IS NULL
                OR ot.status="Cancelled"
           )
           AND NOT EXISTS (
                SELECT 1
                FROM payroll_item_attendance pia
                JOIN payroll_items pi ON pi.id=pia.payroll_item_id
                JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
                WHERE pia.attendance_id=a.id
                  AND pr.status IN ("For Review", "Approved", "Finalized", "Paid", "Released")
           )
         ORDER BY a.scan_date DESC, a.id DESC
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$employeeId, $requestedMinutes]);

    return $stmt->fetch() ?: [];
}

/**
 * Retrieve one official overtime request document. Supplying employeeId makes
 * the query ownership-safe for the employee portal; administrators omit it.
 *
 * @return array<string, mixed>
 */
function ucchr_overtime_request_document(PDO $pdo, int $requestId, ?int $employeeId = null): array
{
    $sql =
        'SELECT ot.*, e.employee_no, e.first_name, e.middle_name, e.last_name,
                e.position, d.name AS department, u.full_name AS approver_name
         FROM overtime_requests ot
         JOIN employees e ON e.id=ot.employee_id
         LEFT JOIN departments d ON d.id=e.department_id
         LEFT JOIN users u ON u.id=ot.approved_by
         WHERE ot.id=?';
    $params = [$requestId];
    if ($employeeId !== null) {
        $sql .= ' AND ot.employee_id=?';
        $params[] = $employeeId;
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $request = $stmt->fetch();
    if (!$request) {
        return [];
    }

    $middleName = trim((string) ($request['middle_name'] ?? ''));
    $request['employee_name'] = trim(
        (string) $request['first_name'] . ' '
            . ($middleName !== '' ? $middleName . ' ' : '')
            . (string) $request['last_name']
    );

    return $request;
}
