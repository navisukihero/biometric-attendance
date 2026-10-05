<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/fingerprint_commands.php';

function fingerprint_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fingerprint_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true) . ')'
        );
    }
}

$pdo->beginTransaction();

try {
    fingerprint_assert_same(
        ['CENTER', 'LEFT', 'RIGHT', 'UPPER', 'LOWER'],
        FINGERPRINT_ENROLLMENT_POSITIONS,
        'Enrollment guidance must keep the required five-position order'
    );
    fingerprint_assert_same(5, FINGERPRINT_ENROLLMENT_CAPTURE_COUNT, 'Enrollment must advertise five guided captures');
    fingerprint_assert_same(
        ['enroll-btn1-v1', 'enroll-five-template-v1'],
        fingerprint_parse_capabilities(' enroll-btn1-v1, ENROLL-FIVE-TEMPLATE-V1 '),
        'ESP32 capability header must accept comma-separated, case-insensitive values'
    );
    fingerprint_assert(
        !fingerprint_supports_guided_enrollment(['enroll-btn1-v1']),
        'Legacy BTN1-only firmware must not receive five-position enrollment commands'
    );
    fingerprint_assert(
        fingerprint_supports_guided_enrollment(['ENROLL-FIVE-TEMPLATE-V1', 'enroll-btn1-v1']),
        'Firmware advertising both required capabilities must receive enrollment commands'
    );

    $usedSlots = array_flip(array_map(
        'intval',
        $pdo->query('SELECT fingerprint_slot FROM fingerprint_registrations')->fetchAll(PDO::FETCH_COLUMN)
    ));
    foreach ($pdo->query('SELECT sensor_slot FROM fingerprint_template_slots')->fetchAll(PDO::FETCH_COLUMN) as $usedSlot) {
        $usedSlots[(int) $usedSlot] = true;
    }
    $slot = 0;
    for ($candidate = 127; $candidate >= 1; $candidate--) {
        if (!isset($usedSlots[$candidate])) {
            $slot = $candidate;
            break;
        }
    }
    fingerprint_assert($slot > 0, 'A free rollback-only AS608 slot is required for this test');

    $suffix = bin2hex(random_bytes(5));
    $employeeNo = 'FP-TEST-' . $suffix;
    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (employee_no, first_name, last_name, position, status,
             fingerprint_status, fingerprint_code)
         VALUES (?, "Fingerprint", "Regression", "Tester", "Active",
                 "Not enrolled", ?)'
    );
    $stmt->execute([$employeeNo, (string) $slot]);
    $employeeId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO fingerprint_registrations
            (employee_id, fingerprint_slot, mapping_status, enrollment_version)
         VALUES (?, ?, "Reserved", 0)'
    )->execute([$employeeId, $slot]);

    $attendanceCommandId = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO device_commands
            (command_uuid, command_type, employee_id, fingerprint_slot,
             event_type, status, requested_at)
         VALUES (?, "VERIFY_ATTENDANCE", ?, ?, "IN", "Pending", NOW())'
    )->execute([$attendanceCommandId, $employeeId, $slot]);

    $queued = fingerprint_queue_enrollment($pdo, $employeeId, null);
    fingerprint_assert_same(false, $queued['alreadyQueued'], 'First enrollment request must create a command');
    fingerprint_assert_same('Pending', $queued['status'], 'New enrollment must start Pending');
    fingerprint_assert_same($slot, $queued['slot'], 'Queued command must use the reserved stable slot');
    fingerprint_assert_same(1, $queued['version'], 'First enrollment must use mapping version 1');
    fingerprint_assert_same(5, $queued['captureCount'], 'Queued enrollment must advertise five guided captures');
    fingerprint_assert_same(FINGERPRINT_ENROLLMENT_POSITIONS, $queued['positions'], 'Queued enrollment must preserve capture order');
    fingerprint_assert_same(5, count($queued['templateSlots']), 'Queued enrollment must reserve five physical AS608 slots');
    fingerprint_assert_same($slot, $queued['templateSlots'][0], 'CENTER physical slot must remain the canonical employee slot');
    fingerprint_assert_same(5, count(array_unique($queued['templateSlots'])), 'Each thumb position must use a unique physical sensor slot');
    fingerprint_assert_same(64, strlen($queued['commandId']), 'Command UUID must be a 256-bit hex id');
    $attendanceCommandStatus = $pdo->prepare(
        'SELECT status FROM device_commands WHERE command_uuid=? LIMIT 1'
    );
    $attendanceCommandStatus->execute([$attendanceCommandId]);
    fingerprint_assert_same(
        'Pending',
        $attendanceCommandStatus->fetchColumn(),
        'Queuing enrollment must not supersede an unrelated attendance command'
    );

    $mapping = $pdo->query(
        'SELECT * FROM fingerprint_registrations WHERE employee_id=' . $employeeId
    )->fetch();
    fingerprint_assert_same('Reserved', $mapping['mapping_status'], 'Queuing must not invalidate the sensor mapping');
    fingerprint_assert_same($queued['commandId'], $mapping['enrollment_command_uuid'], 'Mapping must bind the exact queued command');

    $duplicate = fingerprint_queue_enrollment($pdo, $employeeId, null);
    fingerprint_assert_same(true, $duplicate['alreadyQueued'], 'Repeated Sync/Enroll must reuse the active command');
    fingerprint_assert_same($queued['commandId'], $duplicate['commandId'], 'Repeated Sync/Enroll must keep the same UUID');
    fingerprint_assert_same(1, $duplicate['version'], 'Repeated Sync/Enroll must not increment the mapping version');
    $activeCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM device_commands WHERE employee_id=' . $employeeId
            . ' AND command_type="ENROLL" AND status IN ("Pending","Running")'
    )->fetchColumn();
    fingerprint_assert_same(1, $activeCount, 'Only one active enrollment command may exist per employee');

    $deviceId = 'test-esp32-' . $suffix;
    $claimed = fingerprint_claim_enrollment_command($pdo, $queued['commandId'], $deviceId);
    fingerprint_assert_same('Running', $claimed['status'], 'Terminal claim must set Running');
    fingerprint_assert_same(FINGERPRINT_MESSAGE_AWAITING_APPROVAL, $claimed['result_message'], 'Claim must wait for BTN1');
    $mappingStatus = $pdo->query(
        'SELECT mapping_status FROM fingerprint_registrations WHERE employee_id=' . $employeeId
    )->fetchColumn();
    fingerprint_assert_same('Reserved', $mappingStatus, 'Collecting a command must remain non-destructive');

    // The same GET is a normal Wi-Fi retry. Identical UPDATE values produce a
    // zero changed-row count in MySQL and must not become an HTTP 503.
    $claimedAgain = fingerprint_claim_enrollment_command($pdo, $queued['commandId'], $deviceId);
    fingerprint_assert_same('Running', $claimedAgain['status'], 'Idempotent terminal claim must succeed');
    fingerprint_assert_same($deviceId, $claimedAgain['device_id'], 'Idempotent claim must retain terminal ownership');

    $awaiting = fingerprint_set_enrollment_progress(
        $pdo,
        $deviceId,
        $queued['commandId'],
        $slot,
        1,
        FINGERPRINT_PROGRESS_AWAITING_APPROVAL
    );
    fingerprint_assert_same(FINGERPRINT_PROGRESS_AWAITING_APPROVAL, $awaiting['state'], 'Terminal must report awaiting BTN1');
    fingerprint_assert_same(
        'Reserved',
        $pdo->query('SELECT mapping_status FROM fingerprint_registrations WHERE employee_id=' . $employeeId)->fetchColumn(),
        'Waiting for BTN1 must not pause a new employee mapping'
    );

    $wrongVersionRejected = false;
    try {
        fingerprint_set_enrollment_progress(
            $pdo,
            $deviceId,
            $queued['commandId'],
            $slot,
            2,
            FINGERPRINT_PROGRESS_SCANNING
        );
    } catch (DomainException) {
        $wrongVersionRejected = true;
    }
    fingerprint_assert($wrongVersionRejected, 'Wrong mapping version must not start the sensor replacement');

    $scanning = fingerprint_set_enrollment_progress(
        $pdo,
        $deviceId,
        $queued['commandId'],
        $slot,
        1,
        FINGERPRINT_PROGRESS_SCANNING
    );
    fingerprint_assert_same(FINGERPRINT_PROGRESS_SCANNING, $scanning['state'], 'BTN1 must advance to scanning');
    $mapping = $pdo->query(
        'SELECT mapping_status, device_id FROM fingerprint_registrations WHERE employee_id=' . $employeeId
    )->fetch();
    fingerprint_assert_same('Pending', $mapping['mapping_status'], 'Scanning must pause attendance mapping');
    fingerprint_assert_same($deviceId, $mapping['device_id'], 'Scanning must bind mapping to the claiming device');

    // A sensor may retain templates whose old employee rows were removed.
    // Reclaim only a slot with no mapping, legacy employee code, or active
    // command, and only for this exact running enrollment/device/version.
    $blockedSlots = [];
    foreach ($pdo->query('SELECT fingerprint_slot FROM fingerprint_registrations')->fetchAll(PDO::FETCH_COLUMN) as $blockedSlot) {
        $blockedSlots[(int) $blockedSlot] = true;
    }
    foreach ($pdo->query('SELECT sensor_slot FROM fingerprint_template_slots')->fetchAll(PDO::FETCH_COLUMN) as $blockedSlot) {
        $blockedSlots[(int) $blockedSlot] = true;
    }
    foreach ($pdo->query('SELECT fingerprint_code FROM employees WHERE fingerprint_code REGEXP "^[0-9]+$"')->fetchAll(PDO::FETCH_COLUMN) as $blockedSlot) {
        $blockedSlots[(int) $blockedSlot] = true;
    }
    foreach ($pdo->query('SELECT fingerprint_slot FROM device_commands WHERE status IN ("Pending", "Running")')->fetchAll(PDO::FETCH_COLUMN) as $blockedSlot) {
        $blockedSlots[(int) $blockedSlot] = true;
    }
    $orphanSlot = 0;
    for ($candidate = 126; $candidate >= 1; $candidate--) {
        if (!isset($blockedSlots[$candidate])) {
            $orphanSlot = $candidate;
            break;
        }
    }
    fingerprint_assert($orphanSlot > 0, 'A free rollback-only orphan slot is required for reclaimability tests');

    $reclaimable = fingerprint_enrollment_slot_reclaimability(
        $pdo,
        $deviceId,
        $queued['commandId'],
        $slot,
        1,
        $orphanSlot
    );
    fingerprint_assert_same(true, $reclaimable['reclaimable'], 'A completely orphaned sensor slot may be reclaimed');
    fingerprint_assert_same(false, $reclaimable['belongsToEmployee'], 'An orphan is not part of the employee fingerprint profile');

    $ownedTemplate = fingerprint_enrollment_slot_reclaimability(
        $pdo,
        $deviceId,
        $queued['commandId'],
        $slot,
        1,
        $queued['templateSlots'][1]
    );
    fingerprint_assert_same(true, $ownedTemplate['belongsToEmployee'], 'Every allocated angle slot must belong to the same employee');
    fingerprint_assert_same(false, $ownedTemplate['reclaimable'], 'An employee angle slot must never be reclaimed');

    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (employee_no, first_name, last_name, position, status,
             fingerprint_status, fingerprint_code)
         VALUES (?, "Mapped", "Owner", "Tester", "Active", "Not enrolled", ?)'
    );
    $stmt->execute(['FP-MAPPED-' . $suffix, (string) $orphanSlot]);
    $mappedEmployeeId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO fingerprint_registrations
            (employee_id, fingerprint_slot, mapping_status, enrollment_version)
         VALUES (?, ?, "Reserved", 0)'
    )->execute([$mappedEmployeeId, $orphanSlot]);
    $mappedDenied = fingerprint_enrollment_slot_reclaimability(
        $pdo,
        $deviceId,
        $queued['commandId'],
        $slot,
        1,
        $orphanSlot
    );
    fingerprint_assert_same(false, $mappedDenied['reclaimable'], 'A mapped fingerprint slot must never be reclaimed');
    $pdo->prepare('DELETE FROM employees WHERE id=?')->execute([$mappedEmployeeId]);

    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (employee_no, first_name, last_name, position, status,
             fingerprint_status, fingerprint_code)
         VALUES (?, "Legacy", "Owner", "Tester", "Active", "Not enrolled", ?)'
    );
    $stmt->execute(['FP-LEGACY-' . $suffix, (string) $orphanSlot]);
    $legacyEmployeeId = (int) $pdo->lastInsertId();
    $legacyDenied = fingerprint_enrollment_slot_reclaimability(
        $pdo,
        $deviceId,
        $queued['commandId'],
        $slot,
        1,
        $orphanSlot
    );
    fingerprint_assert_same(false, $legacyDenied['reclaimable'], 'A legacy employee fingerprint code must protect its slot');
    $pdo->prepare('DELETE FROM employees WHERE id=?')->execute([$legacyEmployeeId]);

    $stmt = $pdo->prepare(
        'INSERT INTO device_commands
            (command_uuid, command_type, employee_id, fingerprint_slot,
             event_type, status, requested_at)
         VALUES (?, "VERIFY_ATTENDANCE", ?, ?, "IN", "Pending", NOW())'
    );
    $activeCommandId = bin2hex(random_bytes(32));
    $stmt->execute([$activeCommandId, $employeeId, $orphanSlot]);
    $activeDenied = fingerprint_enrollment_slot_reclaimability(
        $pdo,
        $deviceId,
        $queued['commandId'],
        $slot,
        1,
        $orphanSlot
    );
    fingerprint_assert_same(false, $activeDenied['reclaimable'], 'An active biometric command must protect its slot');
    $pdo->prepare('DELETE FROM device_commands WHERE command_uuid=?')->execute([$activeCommandId]);

    $wrongReclaimDeviceRejected = false;
    try {
        fingerprint_enrollment_slot_reclaimability(
            $pdo,
            'different-terminal',
            $queued['commandId'],
            $slot,
            1,
            $orphanSlot
        );
    } catch (DomainException) {
        $wrongReclaimDeviceRejected = true;
    }
    fingerprint_assert($wrongReclaimDeviceRejected, 'A different terminal cannot authorize orphan-slot deletion');

    $wrongReclaimVersionRejected = false;
    try {
        fingerprint_enrollment_slot_reclaimability(
            $pdo,
            $deviceId,
            $queued['commandId'],
            $slot,
            2,
            $orphanSlot
        );
    } catch (DomainException) {
        $wrongReclaimVersionRejected = true;
    }
    fingerprint_assert($wrongReclaimVersionRejected, 'A stale mapping version cannot authorize orphan-slot deletion');

    // Repeated progress is idempotent and delayed network packets cannot move
    // the terminal from SCANNING back to AWAITING_APPROVAL.
    $scanningAgain = fingerprint_set_enrollment_progress(
        $pdo,
        $deviceId,
        $queued['commandId'],
        $slot,
        1,
        FINGERPRINT_PROGRESS_SCANNING,
        "<strong>Center</strong> 1/5\n"
    );
    fingerprint_assert_same(FINGERPRINT_PROGRESS_SCANNING, $scanningAgain['state'], 'Repeated SCANNING must succeed');
    fingerprint_assert_same(
        'Five-template enrollment: Center 1/5',
        $scanningAgain['message'],
        'Live enrollment progress must be stored as normalized plain text'
    );
    $delayedAwaiting = fingerprint_set_enrollment_progress(
        $pdo,
        $deviceId,
        $queued['commandId'],
        $slot,
        1,
        FINGERPRINT_PROGRESS_AWAITING_APPROVAL
    );
    fingerprint_assert_same(FINGERPRINT_PROGRESS_SCANNING, $delayedAwaiting['state'], 'Delayed AWAITING must not regress SCANNING');

    $webStatus = fingerprint_enrollment_status($pdo, $employeeId);
    fingerprint_assert_same('Running', $webStatus['phase'], 'Website must expose the physical scanning phase');
    fingerprint_assert_same(true, $webStatus['active'], 'Website must continue polling an active scan');
    fingerprint_assert_same('Five-template enrollment: Center 1/5', $webStatus['message'], 'Website polling must expose live five-position progress');

    $whileScanning = fingerprint_queue_enrollment($pdo, $employeeId, null);
    fingerprint_assert_same(true, $whileScanning['alreadyQueued'], 'Sync/Enroll during scanning must reuse the physical operation');
    fingerprint_assert_same($queued['commandId'], $whileScanning['commandId'], 'Scanning cannot be superseded by a form retry');
    fingerprint_assert_same(1, $whileScanning['version'], 'Scanning form retry cannot advance the mapping version');

    // A different terminal cannot approve this exact command.
    $wrongDeviceRejected = false;
    try {
        fingerprint_set_enrollment_progress(
            $pdo,
            'different-terminal',
            $queued['commandId'],
            $slot,
            1,
            FINGERPRINT_PROGRESS_SCANNING
        );
    } catch (DomainException) {
        $wrongDeviceRejected = true;
    }
    fingerprint_assert($wrongDeviceRejected, 'A different terminal must not control the claimed enrollment');

    $pdo->rollBack();
    echo "Fingerprint command regression test passed.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Fingerprint command regression test failed: {$error->getMessage()}\n");
    exit(1);
}
