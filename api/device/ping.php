<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    device_json(['ok' => false, 'error' => 'GET is required.'], 405);
}

$deviceId = device_verify_request($pdo, 'GET', '', '/api/device/ping.php');

device_json([
    'ok' => true,
    'deviceId' => $deviceId,
    'serverTime' => date(DATE_ATOM),
    'message' => 'ESP32 API connection is authenticated.',
]);
