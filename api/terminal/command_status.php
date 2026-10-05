<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../device/attendance_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    terminal_json(['ok' => false, 'error' => 'GET is required.'], 405);
}

$commandId = trim((string) ($_GET['id'] ?? ''));
if (!terminal_command_is_authorized($commandId)) {
    terminal_json(['ok' => false, 'error' => 'Command link is invalid or expired.'], 404);
}

try {
    biometric_attendance_reconcile_saved_commands($pdo);
    $stmt = $pdo->prepare('UPDATE device_commands SET status = "Failed", result_message = "Command expired before completion.", completed_at = NOW() WHERE command_uuid = ? AND command_type = "VERIFY_ATTENDANCE" AND status IN ("Pending", "Running") AND requested_at < NOW() - INTERVAL 2 MINUTE');
    $stmt->execute([$commandId]);
    $stmt = $pdo->prepare('
        SELECT dc.*, e.employee_no, e.first_name, e.last_name
        FROM device_commands dc
        JOIN employees e ON e.id = dc.employee_id
        WHERE dc.command_uuid = ?
        LIMIT 1
    ');
    $stmt->execute([$commandId]);
    $command = $stmt->fetch();
} catch (Throwable) {
    terminal_json(['ok' => false, 'error' => 'Biometric terminal migration is missing. Run the latest database update SQL.'], 503);
}

if (!$command) {
    terminal_json(['ok' => false, 'error' => 'Command was not found.'], 404);
}

$status = (string) $command['status'];
$storedResult = biometric_attendance_unpack_command_result(
    (string) ($command['result_message'] ?? '')
);
$message = $storedResult['message'];
if ($message === '') {
    $message = match ($status) {
        'Pending' => 'Waiting for the ESP32 to collect this command.',
        'Running' => 'The scanner is active. Scan the selected employee’s same finger twice.',
        'Done' => 'Fingerprint verified and attendance saved.',
        'Failed' => 'Fingerprint verification failed.',
        default => 'Waiting for biometric result.',
    };
}

$attendance = null;
$punch = null;
if ($status === 'Done' && $command['command_type'] === 'VERIFY_ATTENDANCE') {
    try {
        $stmt = $pdo->prepare(
            'SELECT al.attendance_date, al.action, al.session_index,
                    al.punch_sequence, al.scanned_at, al.processed_attendance_id
             FROM attendance_logs al
             WHERE al.command_uuid=? AND al.employee_id=?
             ORDER BY al.id DESC LIMIT 1'
        );
        $stmt->execute([$commandId, (int) $command['employee_id']]);
        $punch = $stmt->fetch() ?: null;
        $stmt = $pdo->prepare(
            'SELECT scan_date, time_in, time_out, status, source, worked_minutes,
                    regular_minutes, late_minutes, undertime_minutes,
                    approved_overtime_minutes
             FROM attendance
             WHERE id=COALESCE(?, 0)
                OR (employee_id=? AND scan_date=COALESCE(?, DATE(?)))
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            $punch['processed_attendance_id'] ?? null,
            (int) $command['employee_id'],
            $punch['attendance_date'] ?? null,
            (string) $command['requested_at'],
        ]);
        $attendance = $stmt->fetch() ?: null;
    } catch (Throwable) {
        // Metric columns are optional until the latest migration is installed.
        $stmt = $pdo->prepare('SELECT scan_date, time_in, time_out, status, source FROM attendance WHERE employee_id = ? AND scan_date >= DATE_SUB(DATE(?), INTERVAL 1 DAY) ORDER BY scan_date DESC, id DESC LIMIT 1');
        $stmt->execute([(int) $command['employee_id'], (string) $command['requested_at']]);
        $attendance = $stmt->fetch() ?: null;
    }
}

terminal_json([
    'ok' => true,
    'status' => $status,
    'employeeNo' => (string) $command['employee_no'],
    'employeeName' => trim((string) $command['first_name'] . ' ' . (string) $command['last_name']),
    'eventType' => (string) ($command['event_type'] ?? ''),
    'resultAction' => $storedResult['action'],
    'message' => $message,
    'attendance' => $attendance,
    'punch' => $punch,
    'requestedAt' => (string) $command['requested_at'],
    'completedAt' => $command['completed_at'],
]);
