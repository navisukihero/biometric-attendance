<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/fingerprint_commands.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    device_json([
        'ok' => false,
        'belongsToEmployee' => false,
        'reclaimable' => false,
        'reason' => 'POST is required.',
    ], 405);
}

$body = device_request_body();
$deviceId = device_verify_request($pdo, 'POST', $body, '/api/device/enrollment_slot_check.php');
$payload = device_json_body($body);
$headers = device_headers();
$capabilities = fingerprint_parse_capabilities((string) ($headers['x-device-capabilities'] ?? ''));

$commandId = trim((string) ($payload['commandId'] ?? ''));
$intendedSlot = filter_var($payload['intendedSlot'] ?? null, FILTER_VALIDATE_INT);
$version = filter_var($payload['mappingVersion'] ?? null, FILTER_VALIDATE_INT);
$matchedSlot = filter_var($payload['matchedSlot'] ?? null, FILTER_VALIDATE_INT);

if ($commandId === '' || $intendedSlot === false || $version === false || $matchedSlot === false) {
    device_json([
        'ok' => false,
        'belongsToEmployee' => false,
        'reclaimable' => false,
        'reason' => 'Command id, intended slot, mapping version, and matched slot are required.',
    ], 422);
}
if (!fingerprint_supports_guided_enrollment($capabilities)) {
    device_json([
        'ok' => false,
        'belongsToEmployee' => false,
        'reclaimable' => false,
        'reason' => 'Firmware upgrade required for five-position fingerprint enrollment.',
    ], 409);
}

try {
    $result = fingerprint_enrollment_slot_reclaimability(
        $pdo,
        $deviceId,
        $commandId,
        (int) $intendedSlot,
        (int) $version,
        (int) $matchedSlot
    );
} catch (OutOfBoundsException $error) {
    device_json([
        'ok' => false,
        'belongsToEmployee' => false,
        'reclaimable' => false,
        'reason' => $error->getMessage(),
    ], 404);
} catch (InvalidArgumentException $error) {
    device_json([
        'ok' => false,
        'belongsToEmployee' => false,
        'reclaimable' => false,
        'reason' => $error->getMessage(),
    ], 422);
} catch (DomainException $error) {
    device_json([
        'ok' => false,
        'belongsToEmployee' => false,
        'reclaimable' => false,
        'reason' => $error->getMessage(),
    ], 409);
} catch (Throwable) {
    device_json([
        'ok' => false,
        'reclaimable' => false,
        'reason' => 'Could not verify ownership of the matched fingerprint slot.',
    ], 500);
}

device_json([
    'ok' => true,
    'belongsToEmployee' => (bool) ($result['belongsToEmployee'] ?? false),
    'reclaimable' => (bool) $result['reclaimable'],
    'reason' => (string) $result['reason'],
]);
