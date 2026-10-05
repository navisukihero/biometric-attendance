<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/attendance_service.php';
require_once __DIR__ . '/../../includes/fingerprint_commands.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    device_json(['ok' => false, 'error' => 'POST is required.'], 405);
}

$body = device_request_body();
$deviceId = device_verify_request($pdo, 'POST', $body, '/api/device/attendance.php');
$payload = device_json_body($body);

$sensorSlot = (int) ($payload['fingerprintSlot'] ?? 0);
$eventType = strtoupper(trim((string) ($payload['eventType'] ?? 'AUTO')));
$commandId = trim((string) ($payload['commandId'] ?? ''));
$punchRequestId = trim((string) ($payload['punchRequestId'] ?? ''));
$requestedEventType = strtoupper(trim((string) ($payload['requestedEventType'] ?? $eventType)));
$capturedAt = $payload['capturedAt'] ?? null;
if ($sensorSlot <= 0 || !in_array($eventType, ['AUTO', 'IN', 'OUT'], true)) {
    device_json(['ok' => false, 'error' => 'Invalid fingerprint slot or event type.'], 422);
}
if (
    $punchRequestId !== ''
    && (strlen($punchRequestId) > 96 || !preg_match('/^[A-Za-z0-9._:-]+$/', $punchRequestId))
) {
    device_json(['ok' => false, 'error' => 'Invalid attendance punch request id.'], 422);
}
if ($commandId !== '' && $punchRequestId !== '' && !hash_equals($commandId, $punchRequestId)) {
    device_json(['ok' => false, 'error' => 'A website attendance command must use its command id as the punch request id.'], 422);
}
if ($commandId !== '' && !in_array($requestedEventType, ['IN', 'OUT'], true)) {
    device_json(['ok' => false, 'error' => 'Website attendance commands must include the requested IN or OUT action.'], 422);
}

$capturedMoment = null;
if ($capturedAt !== null && $capturedAt !== '') {
    $capturedText = trim((string) $capturedAt);
    if (!preg_match('/^[0-9]{10}$/', $capturedText)) {
        device_json(['ok' => false, 'error' => 'Invalid biometric capture time.'], 422);
    }
    $capturedEpoch = (int) $capturedText;
    if (abs(time() - $capturedEpoch) > 300) {
        device_json(['ok' => false, 'error' => 'Biometric capture time is outside the allowed five-minute window.'], 422);
    }
    $capturedMoment = (new DateTimeImmutable('@' . $capturedEpoch))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()));
}

try {
    $resolved = fingerprint_resolve_sensor_slot($pdo, $sensorSlot);
    if (!$resolved) {
        device_json(['ok' => false, 'error' => 'Fingerprint slot is not assigned to an active employee.'], 404);
    }
    $slot = (int) $resolved['fingerprintSlot'];
    $stmt = $pdo->prepare(
        'SELECT e.*, fr.fingerprint_slot, fr.device_id AS fingerprint_device_id,
                fr.enrollment_version AS fingerprint_mapping_version
         FROM fingerprint_registrations fr
         JOIN employees e ON e.id=fr.employee_id
         WHERE fr.fingerprint_slot=?
           AND fr.mapping_status="Enrolled"
           AND e.fingerprint_status="Enrolled"
           AND e.fingerprint_code REGEXP "^[0-9]+$"
           AND CAST(e.fingerprint_code AS UNSIGNED)=fr.fingerprint_slot
           AND e.status="Active"
         LIMIT 1'
    );
    $stmt->execute([$slot]);
    $employee = $stmt->fetch();
} catch (Throwable) {
    device_json(['ok' => false, 'error' => 'Fingerprint mapping migration is missing. Run database/fingerprint_five_template_update.sql.'], 503);
}
if (!$employee) {
    device_json(['ok' => false, 'error' => 'Fingerprint slot is not assigned to an active employee.'], 404);
}
if (
    !empty($employee['fingerprint_device_id'])
    && !hash_equals((string) $employee['fingerprint_device_id'], $deviceId)
) {
    device_json(['ok' => false, 'error' => 'Fingerprint slot belongs to a different biometric terminal. Re-enroll it on this terminal first.'], 409);
}

$attendanceEventType = $commandId !== '' ? $requestedEventType : 'AUTO';

if ($commandId === '') {
    try {
        $result = record_biometric_attendance(
            $pdo,
            $employee,
            $attendanceEventType,
            $deviceId,
            null,
            $capturedMoment,
            $punchRequestId !== '' ? $punchRequestId : null
        );
    } catch (BiometricAttendanceException $error) {
        device_json(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus);
    } catch (Throwable $error) {
        error_log('[UCCHR attendance] Autonomous punch failed: ' . $error->getMessage());
        device_json(['ok' => false, 'error' => 'Could not save the attendance record.'], 500);
    }
} else {
    // A website-requested scan is bound to its command, selected employee,
    // requested action and two-scan proof. Complete the attendance projection
    // and command in one transaction so a failed command_result.php request can
    // never leave a saved punch attached to a repeatedly polled Running command.
    if (($payload['verifiedTwice'] ?? null) !== true) {
        device_json(['ok' => false, 'error' => 'Two matching fingerprint scans are required.'], 422);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT * FROM device_commands WHERE command_uuid=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$commandId]);
        $command = $stmt->fetch();
        if (!$command || (string) $command['command_type'] !== 'VERIFY_ATTENDANCE') {
            throw new BiometricAttendanceException('Attendance command was not found.', 404);
        }
        if (
            (int) $command['employee_id'] !== (int) $employee['id']
            || (int) $command['fingerprint_slot'] !== $slot
        ) {
            throw new BiometricAttendanceException('Fingerprint does not belong to the selected employee.', 409);
        }
        if (strtoupper((string) ($command['event_type'] ?? '')) !== $requestedEventType) {
            throw new BiometricAttendanceException('Requested attendance action does not match the website command.', 409);
        }
        if (
            empty($command['device_id'])
            || !hash_equals((string) $command['device_id'], $deviceId)
        ) {
            throw new BiometricAttendanceException('This command belongs to a different biometric terminal.', 409);
        }

        $commandStatus = (string) $command['status'];
        if ($commandStatus === 'Done') {
            // The original response may have been lost after commit. Return the
            // stored action/message without touching attendance a second time.
            $stored = biometric_attendance_unpack_command_result(
                (string) ($command['result_message'] ?? '')
            );
            $result = [
                'employeeName' => trim((string) $employee['first_name'] . ' ' . (string) $employee['last_name']),
                'employeeNo' => (string) $employee['employee_no'],
                'action' => $stored['action'] !== '' ? $stored['action'] : 'ALREADY_RECORDED',
                'message' => $stored['message'] !== ''
                    ? $stored['message']
                    : 'This attendance request was already processed safely.',
                'commandAlreadyCompleted' => true,
            ];
            $pdo->commit();
        } else {
            if ($commandStatus !== 'Running') {
                throw new BiometricAttendanceException('Attendance command is not active.', 409);
            }
            $result = record_biometric_attendance(
                $pdo,
                $employee,
                $attendanceEventType,
                $deviceId,
                $commandId,
                $capturedMoment,
                $commandId
            );
            biometric_attendance_complete_web_command($pdo, $command, $deviceId, $result);
            $pdo->commit();
        }
    } catch (BiometricAttendanceException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        device_json(['ok' => false, 'error' => $error->getMessage()], $error->httpStatus);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[UCCHR attendance] Website command failed: ' . $error->getMessage());
        device_json(['ok' => false, 'error' => 'Could not save the attendance record.'], 500);
    }
}

device_json([
    'ok' => true,
    'requestedEventType' => $requestedEventType,
] + $result);
