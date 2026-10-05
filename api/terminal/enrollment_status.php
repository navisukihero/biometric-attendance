<?php

declare(strict_types=1);

require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fingerprint_commands.php';

header('Cache-Control: no-store, no-cache, must-revalidate');

function enrollment_status_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    enrollment_status_json(['ok' => false, 'error' => 'GET is required.'], 405);
}

if (!admin_session_is_valid($pdo)) {
    enrollment_status_json(['ok' => false, 'error' => 'Your session expired. Log in again.'], 401);
}

$employeeId = filter_input(INPUT_GET, 'employee_id', FILTER_VALIDATE_INT);
if ($employeeId === false || $employeeId === null || $employeeId < 1) {
    enrollment_status_json(['ok' => false, 'error' => 'A valid employee is required.'], 422);
}

try {
    $status = fingerprint_enrollment_status($pdo, (int) $employeeId);
} catch (Throwable) {
    enrollment_status_json([
        'ok' => false,
        'error' => 'Fingerprint status is unavailable. Apply the latest biometric database update.',
    ], 503);
}

if ($status === null) {
    enrollment_status_json(['ok' => false, 'error' => 'Employee not found.'], 404);
}

enrollment_status_json(['ok' => true, 'enrollment' => $status]);
