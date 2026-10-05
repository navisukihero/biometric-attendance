<?php

declare(strict_types=1);

require __DIR__ . '/../../config/database.php';

date_default_timezone_set(getenv('UCCHR_TIMEZONE') ?: 'Asia/Manila');

const DEVICE_API_MAX_BODY_BYTES = 16384;

function device_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function device_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }
    }

    $normalized = [];
    foreach ($headers as $key => $value) {
        $normalized[strtolower($key)] = (string) $value;
    }
    return $normalized;
}

function device_request_body(): string
{
    $contentLength = filter_var(
        $_SERVER['CONTENT_LENGTH'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if (
        $contentLength !== false && $contentLength !== null
        && $contentLength > DEVICE_API_MAX_BODY_BYTES
    ) {
        device_json(['ok' => false, 'error' => 'Device request body is too large.'], 413);
    }

    $body = (string) file_get_contents('php://input');
    if (strlen($body) > DEVICE_API_MAX_BODY_BYTES) {
        device_json(['ok' => false, 'error' => 'Device request body is too large.'], 413);
    }
    return $body;
}

function device_json_body(string $body): array
{
    if ($body === '') {
        return [];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        device_json(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
    }
    return $decoded;
}

function device_secret(PDO $pdo): string
{
    $env = getenv('UCCHR_DEVICE_SECRET');
    if (is_string($env) && $env !== '') {
        return $env;
    }

    try {
        $stmt = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = "device_shared_secret" LIMIT 1');
        $stmt->execute();
        $value = (string) ($stmt->fetchColumn() ?: '');
        if ($value !== '') {
            return $value;
        }
    } catch (Throwable) {
        // The settings table may not exist during first installation.
    }

    // Never make API authentication predictable merely because an
    // environment variable, migration, or settings row is missing.
    device_json([
        'ok' => false,
        'error' => 'Device authentication is not configured. Set UCCHR_DEVICE_SECRET or settings.device_shared_secret.',
    ], 503);
}

function device_verify_request(
    PDO $pdo,
    string $method,
    string $body,
    ?string $expectedSignedPath = null
): string {
    $headers = device_headers();
    $deviceId = $headers['x-device-id'] ?? '';
    $timestamp = $headers['x-timestamp'] ?? '';
    $nonce = $headers['x-nonce'] ?? '';
    $signature = $headers['x-signature'] ?? '';
    $signedPath = $headers['x-request-path'] ?? '';
    // These may be absent on a request, in which case the signed value is the
    // deterministic empty string. When present, sign the exact header bytes;
    // both fields influence enrollment authorization or operational identity.
    $deviceCapabilities = $headers['x-device-capabilities'] ?? '';
    $firmwareVersion = $headers['x-firmware-version'] ?? '';

    if ($deviceId === '' || $timestamp === '' || $nonce === '' || $signature === '' || $signedPath === '') {
        device_json(['ok' => false, 'error' => 'Missing device authentication headers.'], 401);
    }
    if (
        !preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $deviceId)
        || !preg_match('/^[A-Za-z0-9._:-]{16,80}$/', $nonce)
        || !preg_match('/^[0-9]{13}$/', $timestamp)
        || !preg_match('/^[a-fA-F0-9]{64}$/', $signature)
        || strlen($signedPath) > 160
        || !preg_match('#^/[A-Za-z0-9._/-]+$#', $signedPath)
        || strlen($deviceCapabilities) > 512
        || preg_match('/[^\x20-\x7E]/', $deviceCapabilities)
        || strlen($firmwareVersion) > 80
        || preg_match('/[^\x20-\x7E]/', $firmwareVersion)
    ) {
        device_json(['ok' => false, 'error' => 'Malformed device authentication headers.'], 401);
    }
    if ($expectedSignedPath !== null && !hash_equals($expectedSignedPath, $signedPath)) {
        device_json(['ok' => false, 'error' => 'Signed path is not valid for this endpoint.'], 401);
    }

    $method = strtoupper($method);
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== $method) {
        device_json(['ok' => false, 'error' => 'Signed method does not match request method.'], 401);
    }

    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($requestPath !== '' && !str_ends_with($requestPath, $signedPath)) {
        device_json(['ok' => false, 'error' => 'Signed path does not match request path.'], 401);
    }

    $timestampMs = (int) $timestamp;
    $nowMs = time() * 1000;
    if ($timestampMs <= 0 || abs($nowMs - $timestampMs) > 10 * 60 * 1000) {
        device_json(['ok' => false, 'error' => 'Device clock is not synchronized.'], 401);
    }

    // Device identity scopes nonce replay records, command claims, fingerprint
    // mappings, and terminal status. Capabilities decide whether destructive
    // enrollment work may be claimed/completed. Bind all device metadata so a
    // captured request cannot be relabeled in transit. The ESP32 signer uses
    // this exact order and the verifier intentionally has no legacy fallback.
    $canonical = $method . "\n" . $signedPath . "\n" . $deviceId . "\n"
        . $deviceCapabilities . "\n" . $firmwareVersion . "\n"
        . $timestamp . "\n" . $nonce . "\n" . $body;
    $expected = hash_hmac('sha256', $canonical, device_secret($pdo));
    if (!hash_equals($expected, strtolower($signature))) {
        device_json(['ok' => false, 'error' => 'Invalid device signature.'], 401);
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO device_nonces (device_id, nonce, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$deviceId, $nonce]);
        // A signed request is accepted for at most ten minutes. Retaining an
        // hour gives ample clock/race margin without growing this table by a
        // full day of two-second terminal polls.
        $pdo->exec('DELETE FROM device_nonces WHERE created_at < NOW() - INTERVAL 1 HOUR LIMIT 500');
    } catch (PDOException $error) {
        if ((string) $error->getCode() === '23000') {
            device_json(['ok' => false, 'error' => 'This signed request was already used.'], 409);
        }
        device_json(['ok' => false, 'error' => 'Run database/iot_schedule_update.sql before connecting the ESP32.'], 500);
    }

    // Every authenticated request is also a device heartbeat. The website can
    // now report whether the ESP32 is actually polling instead of assuming it
    // is online merely because the PHP application is running.
    try {
        $remoteAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $stmt = $pdo->prepare('INSERT INTO device_status (device_id, last_seen, ip_address) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE last_seen=NOW(), ip_address=VALUES(ip_address)');
        $stmt->execute([$deviceId, $remoteAddress !== '' ? $remoteAddress : null]);
    } catch (Throwable) {
        // Heartbeat reporting is optional during a rolling database update.
    }

    return $deviceId;
}
