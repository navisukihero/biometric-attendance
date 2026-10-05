<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/fingerprint_commands.php';
require_once __DIR__ . '/attendance_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    device_json(['ok' => false, 'error' => 'POST is required.'], 405);
}

$body = device_request_body();
$deviceId = device_verify_request($pdo, 'POST', $body, '/api/device/command_result.php');
$payload = device_json_body($body);

$commandId = trim((string) ($payload['commandId'] ?? ''));
$successValue = $payload['success'] ?? null;
$message = trim((string) ($payload['message'] ?? ''));
if ($commandId === '' || !is_bool($successValue)) {
    device_json(['ok' => false, 'error' => 'A command id and boolean success value are required.'], 422);
}
$success = $successValue;
$message = substr($message !== '' ? $message : ($success ? 'Command completed.' : 'Command failed.'), 0, 255);
$headers = device_headers();
$capabilities = fingerprint_parse_capabilities((string) ($headers['x-device-capabilities'] ?? ''));

try {
    $stmt = $pdo->prepare('INSERT INTO device_status (device_id, last_seen) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)');
    $stmt->execute([$deviceId]);
} catch (Throwable) {
}

$pdo->beginTransaction();
try {
    biometric_attendance_reconcile_saved_commands($pdo);
    $stmt = $pdo->prepare('SELECT * FROM device_commands WHERE command_uuid = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$commandId]);
    $command = $stmt->fetch();
    if (!$command) {
        $pdo->rollBack();
        device_json(['ok' => false, 'error' => 'Command not found.'], 404);
    }

    if (!empty($command['device_id']) && !hash_equals((string) $command['device_id'], $deviceId)) {
        $pdo->rollBack();
        device_json(['ok' => false, 'error' => 'Command belongs to a different biometric terminal.'], 409);
    }

    // Device retries are expected on Wi-Fi. A completed command must never
    // repeat enrollment side effects or alter its original result.
    if (in_array((string) $command['status'], ['Done', 'Failed'], true)) {
        $status = (string) $command['status'];
        $pdo->commit();
        device_json([
            'ok' => true,
            'status' => $status,
            'alreadyCompleted' => true,
            'mappingUpdated' => $status === 'Done' && $command['command_type'] === 'ENROLL',
        ]);
    }

    if (
        (string) $command['status'] !== 'Running'
        || empty($command['device_id'])
        || !hash_equals((string) $command['device_id'], $deviceId)
    ) {
        $pdo->rollBack();
        device_json(['ok' => false, 'error' => 'The command was not claimed by this biometric terminal.'], 409);
    }

    if (
        $command['command_type'] === 'ENROLL'
        && $success
        && !fingerprint_supports_guided_enrollment($capabilities)
    ) {
        $pdo->rollBack();
        device_json([
            'ok' => false,
            'error' => 'Firmware upgrade required before five-position enrollment can complete.',
        ], 409);
    }

    $status = $success ? 'Done' : 'Failed';
    $mappingUpdated = false;

    if ($command['command_type'] === 'ENROLL') {
        $stmt = $pdo->prepare(
            'SELECT fr.employee_id, fr.fingerprint_slot, fr.mapping_status,
                    fr.enrollment_version, fr.enrollment_command_uuid
             FROM fingerprint_registrations fr
             WHERE fr.employee_id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([(int) $command['employee_id']]);
        $mapping = $stmt->fetch();

        if (
            !$mapping
            || (int) $mapping['fingerprint_slot'] !== (int) $command['fingerprint_slot']
            || (int) $mapping['enrollment_version'] !== (int) $command['enrollment_version']
            || !hash_equals((string) $mapping['enrollment_command_uuid'], (string) $command['command_uuid'])
        ) {
            $pdo->rollBack();
            device_json(['ok' => false, 'error' => 'Enrollment result is stale and cannot change the current employee mapping.'], 409);
        }

        if ($success) {
            if ((string) $mapping['mapping_status'] !== 'Pending') {
                $pdo->rollBack();
                device_json([
                    'ok' => false,
                    'error' => 'Press BTN1 and start the approved scan before completing enrollment.',
                ], 409);
            }
            $stmt = $pdo->prepare(
                'UPDATE fingerprint_registrations
                 SET mapping_status="Enrolled", device_id=?, enrolled_at=NOW()
                 WHERE employee_id=? AND fingerprint_slot=?
                   AND enrollment_version=? AND enrollment_command_uuid=?'
            );
            $stmt->execute([
                $deviceId,
                (int) $command['employee_id'],
                (int) $command['fingerprint_slot'],
                (int) $command['enrollment_version'],
                (string) $command['command_uuid'],
            ]);
            $stmt = $pdo->prepare(
                'UPDATE fingerprint_template_slots
                 SET mapping_status="Enrolled", enrollment_version=?,
                     device_id=?, enrolled_at=NOW()
                 WHERE employee_id=? AND mapping_status="Pending"
                   AND enrollment_version=? AND device_id=?'
            );
            $stmt->execute([
                (int) $command['enrollment_version'],
                $deviceId,
                (int) $command['employee_id'],
                (int) $command['enrollment_version'],
                $deviceId,
            ]);
            $templateCheck = $pdo->prepare(
                'SELECT COUNT(*) FROM fingerprint_template_slots
                 WHERE employee_id=? AND mapping_status="Enrolled"
                   AND enrollment_version=? AND device_id=?'
            );
            $templateCheck->execute([
                (int) $command['employee_id'],
                (int) $command['enrollment_version'],
                $deviceId,
            ]);
            if ((int) $templateCheck->fetchColumn() !== FINGERPRINT_ENROLLMENT_CAPTURE_COUNT) {
                throw new RuntimeException('The five physical fingerprint templates were not synchronized.');
            }
            $stmt = $pdo->prepare(
                'UPDATE employees
                 SET fingerprint_status="Enrolled", fingerprint_code=?
                 WHERE id=?'
            );
            $stmt->execute([(string) $command['fingerprint_slot'], (int) $command['employee_id']]);
            $mappingUpdated = true;
        } elseif ((string) $mapping['mapping_status'] === 'Pending') {
            // Once scanning starts, the old sensor template may have been
            // replaced. A failed scan must disable that mapping until retry.
            $stmt = $pdo->prepare(
                'UPDATE fingerprint_registrations
                 SET mapping_status="Failed", device_id=?
                 WHERE employee_id=? AND fingerprint_slot=?
                   AND enrollment_version=? AND enrollment_command_uuid=?'
            );
            $stmt->execute([
                $deviceId,
                (int) $command['employee_id'],
                (int) $command['fingerprint_slot'],
                (int) $command['enrollment_version'],
                (string) $command['command_uuid'],
            ]);
            $pdo->prepare(
                'UPDATE fingerprint_template_slots
                 SET mapping_status="Failed", device_id=?
                 WHERE employee_id=? AND mapping_status="Pending"
                   AND enrollment_version=?'
            )->execute([
                $deviceId,
                (int) $command['employee_id'],
                (int) $command['enrollment_version'],
            ]);
            $pdo->prepare('UPDATE employees SET fingerprint_status="Not enrolled" WHERE id=?')->execute([(int) $command['employee_id']]);
        } else {
            // BTN2 cancellation while waiting for approval is non-destructive:
            // preserve an existing Enrolled mapping, or a new employee's
            // Reserved mapping, because the sensor was never modified.
            $fingerprintStatus = (string) $mapping['mapping_status'] === 'Enrolled'
                ? 'Enrolled'
                : 'Not enrolled';
            $pdo->prepare('UPDATE employees SET fingerprint_status=? WHERE id=?')
                ->execute([$fingerprintStatus, (int) $command['employee_id']]);
        }
    }

    $stmt = $pdo->prepare('UPDATE device_commands SET status = ?, result_message = ?, completed_at = NOW() WHERE id = ? AND status="Running" AND device_id=?');
    $stmt->execute([$status, $message, (int) $command['id'], $deviceId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Command changed before its result was saved.');
    }

    $activity = match ((string) $command['command_type']) {
        'ENROLL' => ($success ? 'Completed' : 'Failed') . ' fingerprint enrollment for employee #' . (int) $command['employee_id'] . ', slot ' . (int) $command['fingerprint_slot'] . ', mapping v' . (int) $command['enrollment_version'],
        'VERIFY_ATTENDANCE' => ($success ? 'Completed' : 'Failed') . ' two-scan attendance verification for employee #' . (int) $command['employee_id'],
        default => ($success ? 'Completed' : 'Failed') . ' biometric device command',
    };
    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$command['requested_by'] ?: null, substr($activity . ': ' . $message, 0, 255)]);

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    device_json(['ok' => false, 'error' => 'Could not save command result. Apply the latest biometric database migration and retry.'], 500);
}

device_json(['ok' => true, 'status' => $status, 'mappingUpdated' => $mappingUpdated]);
