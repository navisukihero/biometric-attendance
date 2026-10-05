<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/fingerprint_commands.php';
require_once __DIR__ . '/attendance_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    device_json(['ok' => false, 'error' => 'GET is required.'], 405);
}

$body = '';
$deviceId = device_verify_request($pdo, 'GET', $body, '/api/device/commands.php');
$headers = device_headers();
$capabilities = fingerprint_parse_capabilities((string) ($headers['x-device-capabilities'] ?? ''));
$supportsEnrollmentApproval = in_array('enroll-btn1-v1', $capabilities, true);
$supportsFiveTemplateEnrollment = in_array('enroll-five-template-v1', $capabilities, true);
$supportsGuidedEnrollment = fingerprint_supports_guided_enrollment($capabilities);
$firmwareVersion = substr(trim((string) ($headers['x-firmware-version'] ?? '')), 0, 40);

// The authenticated terminal heartbeat also closes Time In-only workdays once
// their frozen Expected Out passes. A database timestamp throttles this across
// all connected terminals; the nightly CLI remains the offline fallback.
try {
    attendance_maybe_finalize_due_open_rows($pdo, new DateTimeImmutable('now'), 30, 50);
} catch (Throwable $error) {
    error_log('[UCCHR attendance] Due-open finalization failed: ' . $error->getMessage());
}

// Every authenticated poll is also the terminal heartbeat used by the web UI.
// The try/catch keeps older installations able to poll while they apply the
// terminal migration that creates device_status.
try {
    $capabilityMessage = $supportsGuidedEnrollment
        ? 'Capabilities: enroll-btn1-v1, enroll-five-template-v1'
        : ($supportsEnrollmentApproval
            ? 'Firmware upgrade required: enroll-five-template-v1'
            : 'Firmware upgrade required for BTN1 and five-position enrollment');
    $stmt = $pdo->prepare('INSERT INTO device_status (device_id, last_seen, firmware_version, last_message) VALUES (?, NOW(), ?, ?) ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen), firmware_version=VALUES(firmware_version), last_message=VALUES(last_message)');
    $stmt->execute([$deviceId, $firmwareVersion !== '' ? $firmwareVersion : null, $capabilityMessage]);
} catch (Throwable) {
}

try {
    $pdo->beginTransaction();
    biometric_attendance_reconcile_saved_commands($pdo);
    $pdo->exec('UPDATE device_commands SET status = "Failed", result_message = "Command expired before completion.", completed_at = NOW() WHERE command_type = "VERIFY_ATTENDANCE" AND status IN ("Pending", "Running") AND requested_at < NOW() - INTERVAL 2 MINUTE');
    $pdo->exec(
        'UPDATE device_commands dc
         LEFT JOIN fingerprint_registrations fr
           ON fr.employee_id=dc.employee_id
          AND fr.fingerprint_slot=dc.fingerprint_slot
          AND fr.enrollment_version=dc.enrollment_version
          AND fr.enrollment_command_uuid=dc.command_uuid
         SET dc.status="Failed",
             dc.result_message="Superseded fingerprint mapping version",
             dc.completed_at=NOW()
         WHERE dc.command_type="ENROLL"
           AND dc.status IN ("Pending","Running")
           AND fr.employee_id IS NULL'
    );
    $pdo->exec(
        'UPDATE device_commands dc
         LEFT JOIN fingerprint_registrations fr
           ON fr.employee_id=dc.employee_id
          AND fr.fingerprint_slot=dc.fingerprint_slot
          AND fr.mapping_status="Enrolled"
         SET dc.status="Failed",
             dc.result_message="Employee fingerprint mapping is no longer active",
             dc.completed_at=NOW()
         WHERE dc.command_type="VERIFY_ATTENDANCE"
           AND dc.status IN ("Pending","Running")
           AND fr.employee_id IS NULL'
    );
    $stmt = $pdo->prepare('
        SELECT dc.*, e.first_name, e.last_name, e.employee_no
        FROM device_commands dc
        JOIN employees e ON e.id = dc.employee_id
        LEFT JOIN fingerprint_registrations fr
          ON fr.employee_id=dc.employee_id
         AND fr.fingerprint_slot=dc.fingerprint_slot
         AND fr.enrollment_version=dc.enrollment_version
         AND fr.enrollment_command_uuid=dc.command_uuid
        LEFT JOIN fingerprint_registrations verified_mapping
          ON verified_mapping.employee_id=dc.employee_id
         AND verified_mapping.fingerprint_slot=dc.fingerprint_slot
         AND verified_mapping.mapping_status="Enrolled"
         WHERE dc.status IN ("Pending", "Running")
           AND (dc.device_id IS NULL OR dc.device_id = ?)
           AND (dc.command_type <> "ENROLL" OR ? = 1)
           AND (dc.command_type <> "ENROLL" OR fr.employee_id IS NOT NULL)
          AND (dc.command_type <> "VERIFY_ATTENDANCE"
               OR (verified_mapping.employee_id IS NOT NULL
                   AND (verified_mapping.device_id IS NULL OR verified_mapping.device_id=?)))
        ORDER BY CASE
            WHEN dc.status = "Running" THEN 0
            WHEN dc.command_type = "ENROLL" THEN 1
            ELSE 2
        END, dc.requested_at ASC, dc.id ASC
        LIMIT 1
        FOR UPDATE
    ');
    $stmt->execute([$deviceId, $supportsGuidedEnrollment ? 1 : 0, $deviceId]);
    $command = $stmt->fetch();

    if ($command) {
        if ($command['command_type'] === 'ENROLL') {
            // Merely collecting a command is non-destructive. The signed
            // command_progress SCANNING event sent after BTN1 is the exact
            // point at which the old mapping is paused.
            $command = fingerprint_claim_enrollment_command($pdo, (string) $command['command_uuid'], $deviceId);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE device_commands SET status="Running", device_id=?
                 WHERE id=? AND status IN ("Pending", "Running")'
            );
            $stmt->execute([$deviceId, (int) $command['id']]);
            $command['status'] = 'Running';
            $command['device_id'] = $deviceId;
        }
    }
    $pdo->commit();
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    device_json(['ok' => false, 'error' => 'Biometric command migration is missing. Run the latest database update SQL.'], 503);
}

if (!$command) {
    device_json([
        'ok' => true,
        'command' => null,
        'enrollmentApprovalSupported' => $supportsEnrollmentApproval,
        'fiveTemplateEnrollmentSupported' => $supportsFiveTemplateEnrollment,
        'guidedEnrollmentSupported' => $supportsGuidedEnrollment,
    ]);
}

$templateSlots = $command['command_type'] === 'ENROLL'
    ? fingerprint_template_slots($pdo, (int) $command['employee_id'])
    : null;

device_json([
    'ok' => true,
    'command' => [
        'id' => $command['command_uuid'],
        'type' => $command['command_type'],
        'slot' => (int) $command['fingerprint_slot'],
        'employeeId' => (int) $command['employee_id'],
        'employeeNo' => $command['employee_no'],
        'employeeName' => trim((string) $command['first_name'] . ' ' . (string) $command['last_name']),
        'eventType' => $command['event_type'] ?? null,
        'mappingVersion' => $command['enrollment_version'] !== null ? (int) $command['enrollment_version'] : null,
        'verificationScans' => $command['command_type'] === 'VERIFY_ATTENDANCE' ? 2 : null,
        'enrollmentCaptureCount' => $command['command_type'] === 'ENROLL'
            ? FINGERPRINT_ENROLLMENT_CAPTURE_COUNT
            : null,
        'enrollmentPositions' => $command['command_type'] === 'ENROLL'
            ? FINGERPRINT_ENROLLMENT_POSITIONS
            : null,
        'enrollmentSensorSlots' => $templateSlots,
        'approvalRequired' => $command['command_type'] === 'ENROLL',
        'progressState' => $command['command_type'] === 'ENROLL'
            ? fingerprint_progress_state($command)
            : null,
    ],
]);
