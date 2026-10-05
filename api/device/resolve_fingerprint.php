<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/fingerprint_commands.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    device_json(['ok' => false, 'error' => 'POST is required.'], 405);
}

$body = device_request_body();
$deviceId = device_verify_request($pdo, 'POST', $body, '/api/device/resolve_fingerprint.php');
$payload = device_json_body($body);
$sensorSlot = filter_var($payload['sensorSlot'] ?? null, FILTER_VALIDATE_INT);
if ($sensorSlot === false || (int) $sensorSlot < 1 || (int) $sensorSlot > 127) {
    device_json(['ok' => false, 'error' => 'A valid AS608 sensor slot is required.'], 422);
}

try {
    $mapping = fingerprint_resolve_sensor_slot($pdo, (int) $sensorSlot);
} catch (Throwable) {
    device_json([
        'ok' => false,
        'error' => 'Apply database/fingerprint_five_template_update.sql before scanning attendance.',
    ], 503);
}

if (!$mapping) {
    device_json(['ok' => false, 'error' => 'Fingerprint is not assigned to an active employee.'], 404);
}
if ($mapping['deviceId'] !== '' && !hash_equals($mapping['deviceId'], $deviceId)) {
    device_json([
        'ok' => false,
        'error' => 'Fingerprint belongs to a different biometric terminal. Re-enroll it on this terminal.',
    ], 409);
}

device_json([
    'ok' => true,
    'sensorSlot' => $mapping['sensorSlot'],
    'position' => $mapping['position'],
    'fingerprintSlot' => $mapping['fingerprintSlot'],
    'employeeId' => $mapping['employeeId'],
    'employeeNo' => $mapping['employeeNo'],
    'employeeName' => $mapping['employeeName'],
]);
