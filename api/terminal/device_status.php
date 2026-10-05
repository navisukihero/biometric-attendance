<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    terminal_json(['ok' => false, 'error' => 'GET is required.'], 405);
}

try {
    $stmt = $pdo->query('SELECT device_id, last_seen, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_ago FROM device_status ORDER BY last_seen DESC LIMIT 1');
    $device = $stmt->fetch();
} catch (Throwable) {
    terminal_json([
        'ok' => true,
        'configured' => false,
        'online' => false,
        'message' => 'Install the biometric terminal database update.',
        'lastSeen' => null,
    ]);
}

if (!$device) {
    terminal_json([
        'ok' => true,
        'configured' => true,
        'online' => false,
        'message' => 'No ESP32 heartbeat received yet.',
        'lastSeen' => null,
    ]);
}

$secondsAgo = max(0, (int) $device['seconds_ago']);
$online = $secondsAgo <= 20;
$payload = [
    'ok' => true,
    'configured' => true,
    'online' => $online,
    'message' => $online ? 'ESP32 online' : 'ESP32 last seen ' . $secondsAgo . ' seconds ago.',
];
// Exact device identity/timestamps are operational details for administrators;
// the public login-page terminal link needs only a simple online/offline state.
if (admin_session_is_valid($pdo)) {
    $payload['deviceId'] = (string) $device['device_id'];
    $payload['lastSeen'] = (string) $device['last_seen'];
    $payload['secondsAgo'] = $secondsAgo;
}
terminal_json($payload);
