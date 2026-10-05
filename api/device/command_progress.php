<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/fingerprint_commands.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    device_json(['ok' => false, 'error' => 'POST is required.'], 405);
}

$body = device_request_body();
$deviceId = device_verify_request($pdo, 'POST', $body, '/api/device/command_progress.php');
$payload = device_json_body($body);

$commandId = trim((string) ($payload['commandId'] ?? ''));
$state = strtoupper(trim((string) ($payload['state'] ?? '')));
$slot = filter_var($payload['fingerprintSlot'] ?? null, FILTER_VALIDATE_INT);
$version = filter_var($payload['mappingVersion'] ?? null, FILTER_VALIDATE_INT);
$progressMessage = isset($payload['message']) && is_scalar($payload['message'])
    ? (string) $payload['message']
    : null;
$headers = device_headers();
$capabilities = fingerprint_parse_capabilities((string) ($headers['x-device-capabilities'] ?? ''));

if ($commandId === '' || $slot === false || $version === false) {
    device_json([
        'ok' => false,
        'error' => 'Command id, fingerprint slot, and mapping version are required.',
    ], 422);
}
if (
    $state === FINGERPRINT_PROGRESS_SCANNING
    && !fingerprint_supports_guided_enrollment($capabilities)
) {
    device_json([
        'ok' => false,
        'error' => 'Firmware upgrade required for five-position fingerprint enrollment.',
    ], 409);
}

try {
    $result = fingerprint_set_enrollment_progress(
        $pdo,
        $deviceId,
        $commandId,
        (int) $slot,
        (int) $version,
        $state,
        $progressMessage
    );
} catch (OutOfBoundsException $error) {
    device_json(['ok' => false, 'error' => $error->getMessage()], 404);
} catch (InvalidArgumentException $error) {
    device_json(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (DomainException $error) {
    device_json(['ok' => false, 'error' => $error->getMessage()], 409);
} catch (Throwable) {
    device_json(['ok' => false, 'error' => 'Could not update enrollment progress.'], 500);
}

device_json([
    'ok' => true,
    'status' => $result['status'],
    'state' => $result['state'],
    'message' => $result['message'],
]);
