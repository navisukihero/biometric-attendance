<?php

declare(strict_types=1);

const FINGERPRINT_PROGRESS_AWAITING_APPROVAL = 'AWAITING_APPROVAL';
const FINGERPRINT_PROGRESS_SCANNING = 'SCANNING';
const FINGERPRINT_MESSAGE_AWAITING_PREFIX = 'Waiting for BTN1:';
const FINGERPRINT_MESSAGE_SCANNING_PREFIX = 'Five-template enrollment:';
const FINGERPRINT_MESSAGE_AWAITING_APPROVAL = 'Waiting for BTN1 approval at biometric terminal.';
const FINGERPRINT_MESSAGE_SCANNING = 'Five-template enrollment: BTN1 approved. Capture thumb positions: Center, Left, Right, Upper, Lower.';
const FINGERPRINT_ENROLLMENT_POSITIONS = ['CENTER', 'LEFT', 'RIGHT', 'UPPER', 'LOWER'];
const FINGERPRINT_ENROLLMENT_CAPTURE_COUNT = 5;
const FINGERPRINT_ENROLLMENT_REQUIRED_CAPABILITIES = ['enroll-btn1-v1', 'enroll-five-template-v1'];

/** Parse the comma/whitespace-separated capability header sent by the ESP32. */
function fingerprint_parse_capabilities(?string $header): array
{
    $capabilities = preg_split(
        '/[\s,]+/',
        strtolower(trim((string) $header)),
        -1,
        PREG_SPLIT_NO_EMPTY
    );
    return $capabilities ?: [];
}

/** True only for firmware that supports approval and the guided five-position workflow. */
function fingerprint_supports_guided_enrollment(array $capabilities): bool
{
    $normalized = array_values(array_unique(array_map(
        static fn(mixed $capability): string => strtolower(trim((string) $capability)),
        $capabilities
    )));

    foreach (FINGERPRINT_ENROLLMENT_REQUIRED_CAPABILITIES as $required) {
        if (!in_array($required, $normalized, true)) {
            return false;
        }
    }
    return true;
}

/** Normalize terminal progress into short plain text suitable for status polling. */
function fingerprint_plain_progress_message(?string $message, int $maxLength = 160): string
{
    $message = trim(strip_tags((string) $message));
    $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
    $message = preg_replace('/\s+/u', ' ', $message) ?? '';
    if ($message === '' || $maxLength < 1) {
        return '';
    }

    return function_exists('mb_substr')
        ? mb_substr($message, 0, $maxLength, 'UTF-8')
        : substr($message, 0, $maxLength);
}

/** Run a command mutation atomically without committing an existing caller transaction. */
function fingerprint_transaction(PDO $pdo, callable $callback): mixed
{
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $result = $callback();
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Return the five physical AS608 slots for one employee in capture order.
 *
 * PHP allocates and records slot numbers only. Image capture, model creation,
 * sensor matching, and storage are performed entirely by the ESP32/AS608.
 */
function fingerprint_ensure_template_slots(PDO $pdo, int $employeeId, int $canonicalSlot): array
{
    if ($employeeId < 1 || $canonicalSlot < 1 || $canonicalSlot > 127) {
        throw new InvalidArgumentException('Employee and canonical fingerprint slot are invalid.');
    }

    $registrationStmt = $pdo->prepare(
        'SELECT fingerprint_slot, mapping_status, enrollment_version, device_id, enrolled_at
         FROM fingerprint_registrations
         WHERE employee_id=? LIMIT 1 FOR UPDATE'
    );
    $registrationStmt->execute([$employeeId]);
    $registration = $registrationStmt->fetch();
    if (!$registration || (int) $registration['fingerprint_slot'] !== $canonicalSlot) {
        throw new RuntimeException('Employee fingerprint registration is missing or changed.');
    }

    $rowStmt = $pdo->prepare(
        'SELECT position, sensor_slot
         FROM fingerprint_template_slots
         WHERE employee_id=? FOR UPDATE'
    );
    $rowStmt->execute([$employeeId]);
    $slotByPosition = [];
    foreach ($rowStmt->fetchAll() as $row) {
        $position = strtoupper((string) $row['position']);
        if (in_array($position, FINGERPRINT_ENROLLMENT_POSITIONS, true)) {
            $slotByPosition[$position] = (int) $row['sensor_slot'];
        }
    }

    if (isset($slotByPosition['CENTER']) && $slotByPosition['CENTER'] !== $canonicalSlot) {
        throw new RuntimeException('The employee center fingerprint slot does not match the canonical mapping.');
    }

    $usedSlots = [];
    $usedStmt = $pdo->prepare(
        'SELECT sensor_slot FROM fingerprint_template_slots
         WHERE employee_id<>? FOR UPDATE'
    );
    $usedStmt->execute([$employeeId]);
    foreach ($usedStmt->fetchAll(PDO::FETCH_COLUMN) as $usedSlot) {
        $usedSlots[(int) $usedSlot] = true;
    }
    $usedStmt = $pdo->prepare(
        'SELECT fingerprint_slot FROM fingerprint_registrations
         WHERE employee_id<>? FOR UPDATE'
    );
    $usedStmt->execute([$employeeId]);
    foreach ($usedStmt->fetchAll(PDO::FETCH_COLUMN) as $usedSlot) {
        $usedSlots[(int) $usedSlot] = true;
    }
    $legacyStmt = $pdo->prepare(
        'SELECT fingerprint_code FROM employees
         WHERE id<>? AND fingerprint_code REGEXP "^[0-9]+$" FOR UPDATE'
    );
    $legacyStmt->execute([$employeeId]);
    foreach ($legacyStmt->fetchAll(PDO::FETCH_COLUMN) as $usedSlot) {
        $usedSlots[(int) $usedSlot] = true;
    }
    foreach ($slotByPosition as $usedSlot) {
        $usedSlots[$usedSlot] = true;
    }

    if (!isset($slotByPosition['CENTER'])) {
        if (isset($usedSlots[$canonicalSlot])) {
            throw new RuntimeException('The employee center fingerprint slot is already assigned.');
        }
        $pdo->prepare(
            'INSERT INTO fingerprint_template_slots
                (employee_id, position, sensor_slot, mapping_status,
                 enrollment_version, device_id, enrolled_at)
             VALUES (?, "CENTER", ?, ?, ?, ?, ?)'
        )->execute([
            $employeeId,
            $canonicalSlot,
            (string) $registration['mapping_status'],
            (int) $registration['enrollment_version'],
            $registration['device_id'] ?: null,
            $registration['enrolled_at'] ?: null,
        ]);
        $slotByPosition['CENTER'] = $canonicalSlot;
        $usedSlots[$canonicalSlot] = true;
    }

    $insert = $pdo->prepare(
        'INSERT INTO fingerprint_template_slots
            (employee_id, position, sensor_slot, mapping_status, enrollment_version)
         VALUES (?, ?, ?, "Reserved", ?)'
    );
    foreach (FINGERPRINT_ENROLLMENT_POSITIONS as $position) {
        if (isset($slotByPosition[$position])) {
            continue;
        }

        $sensorSlot = 0;
        for ($candidate = 1; $candidate <= 127; $candidate++) {
            if (!isset($usedSlots[$candidate])) {
                $sensorSlot = $candidate;
                break;
            }
        }
        if ($sensorSlot === 0) {
            throw new RuntimeException('The AS608 does not have five free template slots for this employee.');
        }

        $insert->execute([
            $employeeId,
            $position,
            $sensorSlot,
            (int) $registration['enrollment_version'],
        ]);
        $slotByPosition[$position] = $sensorSlot;
        $usedSlots[$sensorSlot] = true;
    }

    $ordered = [];
    foreach (FINGERPRINT_ENROLLMENT_POSITIONS as $position) {
        $sensorSlot = (int) ($slotByPosition[$position] ?? 0);
        if ($sensorSlot < 1 || $sensorSlot > 127) {
            throw new RuntimeException('The five-template fingerprint slot mapping is incomplete.');
        }
        $ordered[] = $sensorSlot;
    }
    if (count(array_unique($ordered)) !== FINGERPRINT_ENROLLMENT_CAPTURE_COUNT) {
        throw new RuntimeException('Each fingerprint position must use a unique AS608 sensor slot.');
    }

    return $ordered;
}

/** Read an already allocated five-template slot list without mutating it. */
function fingerprint_template_slots(PDO $pdo, int $employeeId): array
{
    $stmt = $pdo->prepare(
        'SELECT position, sensor_slot
         FROM fingerprint_template_slots WHERE employee_id=?'
    );
    $stmt->execute([$employeeId]);
    $slotByPosition = [];
    foreach ($stmt->fetchAll() as $row) {
        $slotByPosition[strtoupper((string) $row['position'])] = (int) $row['sensor_slot'];
    }

    $ordered = [];
    foreach (FINGERPRINT_ENROLLMENT_POSITIONS as $position) {
        if (!isset($slotByPosition[$position])) {
            throw new RuntimeException('Fingerprint template slots are incomplete. Apply database/fingerprint_five_template_update.sql.');
        }
        $ordered[] = $slotByPosition[$position];
    }
    return $ordered;
}

/**
 * Queue exactly one current enrollment command for an employee.
 *
 * The fingerprint_registrations row is the canonical employee/slot/version
 * mapping. Repeated form submissions return the already-active command rather
 * than creating another version. A late device reply can therefore never
 * remap the slot to a different command generation.
 */
function fingerprint_queue_enrollment(PDO $pdo, int $employeeId, ?int $requestedBy): array
{
    if ($employeeId < 1) {
        throw new InvalidArgumentException('Choose a valid employee.');
    }

    return fingerprint_transaction($pdo, function () use ($pdo, $employeeId, $requestedBy): array {
        $stmt = $pdo->prepare(
            'SELECT e.id, e.employee_no, e.fingerprint_code,
                    fr.fingerprint_slot, fr.mapping_status,
                    fr.enrollment_version, fr.enrollment_command_uuid
             FROM employees e
             LEFT JOIN fingerprint_registrations fr ON fr.employee_id=e.id
             WHERE e.id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$employeeId]);
        $mapping = $stmt->fetch();
        if (!$mapping) {
            throw new RuntimeException('Employee not found.');
        }

        $slot = (int) ($mapping['fingerprint_slot'] ?? $mapping['fingerprint_code'] ?? 0);
        if ($slot < 1 || $slot > 127) {
            throw new RuntimeException('This employee does not have a valid AS608 fingerprint slot.');
        }

        if ($mapping['fingerprint_slot'] === null) {
            $pdo->prepare(
                'INSERT INTO fingerprint_registrations
                    (employee_id, fingerprint_slot, mapping_status, enrollment_version)
                 VALUES (?, ?, "Reserved", 0)'
            )->execute([$employeeId, $slot]);
            $mapping['fingerprint_slot'] = $slot;
            $mapping['mapping_status'] = 'Reserved';
            $mapping['enrollment_version'] = 0;
            $mapping['enrollment_command_uuid'] = null;
        }

        $templateSlots = fingerprint_ensure_template_slots($pdo, $employeeId, $slot);

        $activeStmt = $pdo->prepare(
            'SELECT command_uuid, fingerprint_slot, enrollment_version, status,
                    result_message, requested_at
             FROM device_commands
             WHERE employee_id=? AND command_type="ENROLL"
               AND status IN ("Pending", "Running")
             ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $activeStmt->execute([$employeeId]);
        $active = $activeStmt->fetch();
        if (
            $active
            && (string) $active['status'] === 'Running'
            && fingerprint_progress_state($active) === FINGERPRINT_PROGRESS_SCANNING
            && ((int) $active['fingerprint_slot'] !== $slot
                || (int) $active['enrollment_version'] !== (int) $mapping['enrollment_version']
                || !is_string($mapping['enrollment_command_uuid'])
                || !hash_equals((string) $mapping['enrollment_command_uuid'], (string) $active['command_uuid']))
        ) {
            throw new RuntimeException('The biometric terminal is currently replacing this fingerprint. Wait for the scan result before retrying.');
        }
        if (
            $active
            && (int) $active['fingerprint_slot'] === $slot
            && (int) $active['enrollment_version'] === (int) $mapping['enrollment_version']
            && is_string($mapping['enrollment_command_uuid'])
            && hash_equals((string) $mapping['enrollment_command_uuid'], (string) $active['command_uuid'])
        ) {
            return [
                'alreadyQueued' => true,
                'commandId' => (string) $active['command_uuid'],
                'status' => (string) $active['status'],
                'slot' => $slot,
                'version' => (int) $active['enrollment_version'],
                'employeeNo' => (string) $mapping['employee_no'],
                'captureCount' => FINGERPRINT_ENROLLMENT_CAPTURE_COUNT,
                'positions' => FINGERPRINT_ENROLLMENT_POSITIONS,
                'templateSlots' => $templateSlots,
            ];
        }

        // Any unmatched active row is stale relative to the locked canonical
        // mapping. Keep it for audit, but never deliver it to the terminal.
        $stmt = $pdo->prepare(
            'UPDATE device_commands
             SET status="Failed",
                 result_message="Superseded by a newer fingerprint enrollment request",
                 completed_at=NOW()
             WHERE employee_id=? AND command_type="ENROLL"
               AND status IN ("Pending", "Running")'
        );
        $stmt->execute([$employeeId]);

        $version = (int) $mapping['enrollment_version'] + 1;
        $commandId = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            'UPDATE fingerprint_registrations
             SET enrollment_version=?, enrollment_command_uuid=?
             WHERE employee_id=? AND fingerprint_slot=?'
        );
        $stmt->execute([$version, $commandId, $employeeId, $slot]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Fingerprint mapping changed while the request was being queued.');
        }

        $pdo->prepare(
            'UPDATE fingerprint_template_slots
             SET enrollment_version=? WHERE employee_id=?'
        )->execute([$version, $employeeId]);

        $pdo->prepare('UPDATE employees SET fingerprint_code=? WHERE id=?')
            ->execute([(string) $slot, $employeeId]);
        $stmt = $pdo->prepare(
            'INSERT INTO device_commands
                (command_uuid, command_type, employee_id, fingerprint_slot,
                 enrollment_version, status, requested_by, requested_at)
             VALUES (?, "ENROLL", ?, ?, ?, "Pending", ?, NOW())'
        );
        $stmt->execute([$commandId, $employeeId, $slot, $version, $requestedBy ?: null]);

        return [
            'alreadyQueued' => false,
            'commandId' => $commandId,
            'status' => 'Pending',
            'slot' => $slot,
            'version' => $version,
            'employeeNo' => (string) $mapping['employee_no'],
            'captureCount' => FINGERPRINT_ENROLLMENT_CAPTURE_COUNT,
            'positions' => FINGERPRINT_ENROLLMENT_POSITIONS,
            'templateSlots' => $templateSlots,
        ];
    });
}

function fingerprint_progress_state(array $command): string
{
    $status = (string) ($command['status'] ?? '');
    if ($status !== 'Running') {
        return '';
    }

    $message = (string) ($command['result_message'] ?? '');
    return str_starts_with($message, FINGERPRINT_MESSAGE_SCANNING_PREFIX)
        ? FINGERPRINT_PROGRESS_SCANNING
        : FINGERPRINT_PROGRESS_AWAITING_APPROVAL;
}

/** Claim (or re-read) one exact ENROLL command without changing its mapping. */
function fingerprint_claim_enrollment_command(PDO $pdo, string $commandId, string $deviceId): array
{
    return fingerprint_transaction($pdo, function () use ($pdo, $commandId, $deviceId): array {
        $stmt = $pdo->prepare(
            'SELECT dc.*, e.first_name, e.last_name, e.employee_no
             FROM device_commands dc
             JOIN employees e ON e.id=dc.employee_id
             WHERE dc.command_uuid=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$commandId]);
        $command = $stmt->fetch();
        if (!$command || (string) $command['command_type'] !== 'ENROLL') {
            throw new OutOfBoundsException('Enrollment command not found.');
        }
        if (!in_array((string) $command['status'], ['Pending', 'Running'], true)) {
            throw new DomainException('Enrollment command is already complete.');
        }
        if (!empty($command['device_id']) && !hash_equals((string) $command['device_id'], $deviceId)) {
            throw new DomainException('Enrollment command belongs to a different biometric terminal.');
        }

        $mappingStmt = $pdo->prepare(
            'SELECT employee_id FROM fingerprint_registrations
             WHERE employee_id=? AND fingerprint_slot=?
               AND enrollment_version=? AND enrollment_command_uuid=?
             LIMIT 1 FOR UPDATE'
        );
        $mappingStmt->execute([
            (int) $command['employee_id'],
            (int) $command['fingerprint_slot'],
            (int) $command['enrollment_version'],
            (string) $command['command_uuid'],
        ]);
        if (!$mappingStmt->fetchColumn()) {
            throw new DomainException('Enrollment command no longer matches the employee fingerprint mapping.');
        }

        $message = trim((string) ($command['result_message'] ?? '')) !== ''
            ? (string) $command['result_message']
            : FINGERPRINT_MESSAGE_AWAITING_APPROVAL;
        $stmt = $pdo->prepare(
            'UPDATE device_commands
             SET status="Running", device_id=?, result_message=?
             WHERE id=? AND status IN ("Pending", "Running")'
        );
        $stmt->execute([$deviceId, $message, (int) $command['id']]);

        // UPDATE rowCount() may be zero on an idempotent retry. The row was
        // already locked and verified above, so no changed-row test is valid.
        $command['status'] = 'Running';
        $command['device_id'] = $deviceId;
        $command['result_message'] = $message;
        return $command;
    });
}

/**
 * Record the physical terminal's enrollment progress.
 *
 * AWAITING_APPROVAL is non-destructive. SCANNING is the exact point where the
 * employee's old template may be replaced, so attendance is invalidated only
 * then. Both calls are idempotent and require command + slot + mapping version.
 */
function fingerprint_set_enrollment_progress(
    PDO $pdo,
    string $deviceId,
    string $commandId,
    int $slot,
    int $version,
    string $state,
    ?string $progressMessage = null
): array {
    $state = strtoupper(trim($state));
    if (!in_array($state, [FINGERPRINT_PROGRESS_AWAITING_APPROVAL, FINGERPRINT_PROGRESS_SCANNING], true)) {
        throw new InvalidArgumentException('Enrollment progress state is invalid.');
    }
    if ($commandId === '' || $slot < 1 || $slot > 127 || $version < 1) {
        throw new InvalidArgumentException('Command id, fingerprint slot, and mapping version are required.');
    }

    $progressMessage = fingerprint_plain_progress_message($progressMessage);

    return fingerprint_transaction($pdo, function () use ($pdo, $deviceId, $commandId, $slot, $version, $state, $progressMessage): array {
        $stmt = $pdo->prepare(
            'SELECT * FROM device_commands
             WHERE command_uuid=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$commandId]);
        $command = $stmt->fetch();
        if (!$command) {
            throw new OutOfBoundsException('Command not found.');
        }
        if ((string) $command['command_type'] !== 'ENROLL') {
            throw new DomainException('Only fingerprint enrollment commands accept this progress update.');
        }
        if (
            (string) $command['status'] !== 'Running'
            || empty($command['device_id'])
            || !hash_equals((string) $command['device_id'], $deviceId)
        ) {
            throw new DomainException('The enrollment command is not claimed by this biometric terminal.');
        }
        if (
            (int) $command['fingerprint_slot'] !== $slot
            || (int) $command['enrollment_version'] !== $version
        ) {
            throw new DomainException('Fingerprint slot or mapping version does not match the claimed command.');
        }

        $mappingStmt = $pdo->prepare(
            'SELECT * FROM fingerprint_registrations
             WHERE employee_id=? LIMIT 1 FOR UPDATE'
        );
        $mappingStmt->execute([(int) $command['employee_id']]);
        $mapping = $mappingStmt->fetch();
        if (
            !$mapping
            || (int) $mapping['fingerprint_slot'] !== $slot
            || (int) $mapping['enrollment_version'] !== $version
            || !is_string($mapping['enrollment_command_uuid'])
            || !hash_equals((string) $mapping['enrollment_command_uuid'], $commandId)
        ) {
            throw new DomainException('Enrollment progress is stale and cannot change the employee mapping.');
        }

        $currentState = fingerprint_progress_state($command);
        if (
            $currentState === FINGERPRINT_PROGRESS_SCANNING
            && $state === FINGERPRINT_PROGRESS_AWAITING_APPROVAL
        ) {
            // A delayed retry must not move a physical operation backwards.
            return [
                'status' => 'Running',
                'state' => FINGERPRINT_PROGRESS_SCANNING,
                'message' => (string) ($command['result_message'] ?? FINGERPRINT_MESSAGE_SCANNING),
            ];
        }

        if ($state === FINGERPRINT_PROGRESS_SCANNING) {
            $stmt = $pdo->prepare(
                'UPDATE fingerprint_registrations
                 SET mapping_status="Pending", device_id=?
                 WHERE employee_id=? AND fingerprint_slot=?
                   AND enrollment_version=? AND enrollment_command_uuid=?'
            );
            $stmt->execute([$deviceId, (int) $command['employee_id'], $slot, $version, $commandId]);

            // PDO reports zero when an idempotent retry writes the same values.
            // Verify the exact row instead of treating zero changed rows as a
            // missing mapping (the former source of the repeating HTTP 503).
            $mappingStmt->execute([(int) $command['employee_id']]);
            $verified = $mappingStmt->fetch();
            if (
                !$verified
                || (int) $verified['fingerprint_slot'] !== $slot
                || (int) $verified['enrollment_version'] !== $version
                || (string) $verified['mapping_status'] !== 'Pending'
                || !hash_equals((string) $verified['enrollment_command_uuid'], $commandId)
                || !hash_equals((string) ($verified['device_id'] ?? ''), $deviceId)
            ) {
                throw new RuntimeException('Fingerprint mapping could not enter scanning state.');
            }

            $pdo->prepare(
                'UPDATE fingerprint_template_slots
                 SET mapping_status="Pending", enrollment_version=?, device_id=?
                 WHERE employee_id=?'
            )->execute([$version, $deviceId, (int) $command['employee_id']]);
            $templateCheck = $pdo->prepare(
                'SELECT COUNT(*) FROM fingerprint_template_slots
                 WHERE employee_id=? AND mapping_status="Pending"
                   AND enrollment_version=? AND device_id=?'
            );
            $templateCheck->execute([(int) $command['employee_id'], $version, $deviceId]);
            if ((int) $templateCheck->fetchColumn() !== FINGERPRINT_ENROLLMENT_CAPTURE_COUNT) {
                throw new RuntimeException('All five physical fingerprint slots must enter scanning state.');
            }

            $pdo->prepare(
                'UPDATE employees SET fingerprint_status="Not enrolled"
                 WHERE id=? AND fingerprint_code=?'
            )->execute([(int) $command['employee_id'], (string) $slot]);
            $message = $progressMessage !== ''
                ? substr(FINGERPRINT_MESSAGE_SCANNING_PREFIX . ' ' . $progressMessage, 0, 255)
                : FINGERPRINT_MESSAGE_SCANNING;
        } else {
            $message = $progressMessage !== ''
                ? substr(FINGERPRINT_MESSAGE_AWAITING_PREFIX . ' ' . $progressMessage, 0, 255)
                : FINGERPRINT_MESSAGE_AWAITING_APPROVAL;
        }

        $stmt = $pdo->prepare(
            'UPDATE device_commands SET result_message=?
             WHERE id=? AND status="Running" AND device_id=?'
        );
        $stmt->execute([$message, (int) $command['id'], $deviceId]);

        return ['status' => 'Running', 'state' => $state, 'message' => $message];
    });
}

/**
 * Decide whether a sensor template found in a different slot is an orphan.
 *
 * The ESP32 calls this only after BTN1 has started an exact enrollment
 * command. A slot is reclaimable solely when no canonical mapping, legacy
 * employee reference, or active device command owns it. All command and
 * mapping checks are locked in one transaction so an unrelated fingerprint
 * can never be deleted based on stale website state.
 */
function fingerprint_enrollment_slot_reclaimability(
    PDO $pdo,
    string $deviceId,
    string $commandId,
    int $intendedSlot,
    int $version,
    int $matchedSlot
): array {
    $deviceId = trim($deviceId);
    $commandId = trim($commandId);
    if ($deviceId === '' || $commandId === '') {
        throw new InvalidArgumentException('Device and enrollment command identifiers are required.');
    }
    if (
        $intendedSlot < 1 || $intendedSlot > 127
        || $matchedSlot < 1 || $matchedSlot > 127
        || $version < 1
    ) {
        throw new InvalidArgumentException('Fingerprint slots and mapping version are invalid.');
    }

    return fingerprint_transaction(
        $pdo,
        function () use ($pdo, $deviceId, $commandId, $intendedSlot, $version, $matchedSlot): array {
            $stmt = $pdo->prepare(
                'SELECT * FROM device_commands
                 WHERE command_uuid=? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$commandId]);
            $command = $stmt->fetch();
            if (!$command) {
                throw new OutOfBoundsException('Enrollment command not found.');
            }
            if ((string) $command['command_type'] !== 'ENROLL') {
                throw new DomainException('The command is not a fingerprint enrollment.');
            }
            if (
                (string) $command['status'] !== 'Running'
                || empty($command['device_id'])
                || !hash_equals((string) $command['device_id'], $deviceId)
            ) {
                throw new DomainException('The enrollment command is not running on this biometric terminal.');
            }
            if (
                (int) $command['fingerprint_slot'] !== $intendedSlot
                || (int) $command['enrollment_version'] !== $version
            ) {
                throw new DomainException('Fingerprint slot or mapping version does not match the running enrollment.');
            }

            $stmt = $pdo->prepare(
                'SELECT employee_id, fingerprint_slot, mapping_status,
                        enrollment_version, enrollment_command_uuid, device_id
                 FROM fingerprint_registrations
                 WHERE employee_id=? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([(int) $command['employee_id']]);
            $mapping = $stmt->fetch();
            if (
                !$mapping
                || (int) $mapping['fingerprint_slot'] !== $intendedSlot
                || (int) $mapping['enrollment_version'] !== $version
                || !is_string($mapping['enrollment_command_uuid'])
                || !hash_equals((string) $mapping['enrollment_command_uuid'], $commandId)
                || (string) $mapping['mapping_status'] !== 'Pending'
                || empty($mapping['device_id'])
                || !hash_equals((string) $mapping['device_id'], $deviceId)
            ) {
                throw new DomainException('The employee fingerprint mapping is not in the active scanning state.');
            }

            $stmt = $pdo->prepare(
                'SELECT employee_id FROM fingerprint_template_slots
                 WHERE sensor_slot=? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$matchedSlot]);
            $templateOwner = $stmt->fetchColumn();
            if ($templateOwner !== false) {
                if ((int) $templateOwner === (int) $command['employee_id']) {
                    return [
                        'belongsToEmployee' => true,
                        'reclaimable' => false,
                        'reason' => 'Matched template belongs to this employee fingerprint profile.',
                    ];
                }
                return [
                    'belongsToEmployee' => false,
                    'reclaimable' => false,
                    'reason' => 'Matched template belongs to another employee.',
                ];
            }

            if ($matchedSlot === $intendedSlot) {
                return [
                    'belongsToEmployee' => true,
                    'reclaimable' => false,
                    'reason' => 'Matched slot is the intended employee fingerprint slot.',
                ];
            }

            $stmt = $pdo->prepare(
                'SELECT employee_id FROM fingerprint_registrations
                 WHERE fingerprint_slot=? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$matchedSlot]);
            if ($stmt->fetchColumn() !== false) {
                return [
                    'belongsToEmployee' => false,
                    'reclaimable' => false,
                    'reason' => 'Matched slot is assigned in the fingerprint mapping.',
                ];
            }

            $stmt = $pdo->prepare(
                'SELECT id FROM employees
                 WHERE fingerprint_code REGEXP "^[0-9]+$"
                   AND CAST(fingerprint_code AS UNSIGNED)=?
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$matchedSlot]);
            if ($stmt->fetchColumn() !== false) {
                return [
                    'belongsToEmployee' => false,
                    'reclaimable' => false,
                    'reason' => 'Matched slot is referenced by an employee fingerprint code.',
                ];
            }

            $stmt = $pdo->prepare(
                'SELECT id FROM device_commands
                 WHERE fingerprint_slot=? AND status IN ("Pending", "Running")
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$matchedSlot]);
            if ($stmt->fetchColumn() !== false) {
                return [
                    'belongsToEmployee' => false,
                    'reclaimable' => false,
                    'reason' => 'Matched slot is reserved by an active biometric command.',
                ];
            }

            return [
                'belongsToEmployee' => false,
                'reclaimable' => true,
                'reason' => 'Matched slot has no database owner and may be reclaimed.',
            ];
        }
    );
}

/** Resolve any of an employee's five physical AS608 slots to one canonical profile. */
function fingerprint_resolve_sensor_slot(PDO $pdo, int $sensorSlot): ?array
{
    if ($sensorSlot < 1 || $sensorSlot > 127) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT fts.sensor_slot, fts.position,
                fr.employee_id, fr.fingerprint_slot, fr.device_id,
                e.first_name, e.last_name, e.employee_no
         FROM fingerprint_template_slots fts
         JOIN fingerprint_registrations fr ON fr.employee_id=fts.employee_id
         JOIN employees e ON e.id=fr.employee_id
         WHERE fts.sensor_slot=?
           AND fts.mapping_status="Enrolled"
           AND fr.mapping_status="Enrolled"
           AND e.fingerprint_status="Enrolled"
           AND e.fingerprint_code REGEXP "^[0-9]+$"
           AND CAST(e.fingerprint_code AS UNSIGNED)=fr.fingerprint_slot
           AND e.status="Active"
         LIMIT 1'
    );
    $stmt->execute([$sensorSlot]);
    $mapping = $stmt->fetch();
    if (!$mapping) {
        return null;
    }

    return [
        'sensorSlot' => (int) $mapping['sensor_slot'],
        'position' => (string) $mapping['position'],
        'employeeId' => (int) $mapping['employee_id'],
        'fingerprintSlot' => (int) $mapping['fingerprint_slot'],
        'deviceId' => (string) ($mapping['device_id'] ?? ''),
        'employeeNo' => (string) $mapping['employee_no'],
        'employeeName' => trim((string) $mapping['first_name'] . ' ' . (string) $mapping['last_name']),
    ];
}

/** A stable web-facing status payload for employee enrollment polling. */
function fingerprint_enrollment_status(PDO $pdo, int $employeeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT e.id, e.employee_no, e.fingerprint_status, e.fingerprint_code,
                fr.fingerprint_slot, fr.mapping_status, fr.enrollment_version,
                fr.device_id, fr.enrolled_at
         FROM employees e
         LEFT JOIN fingerprint_registrations fr ON fr.employee_id=e.id
         WHERE e.id=? LIMIT 1'
    );
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    if (!$employee) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT command_uuid, status, result_message, fingerprint_slot,
                enrollment_version, requested_at, completed_at
         FROM device_commands
         WHERE employee_id=? AND command_type="ENROLL"
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$employeeId]);
    $command = $stmt->fetch() ?: null;

    $status = (string) ($command['status'] ?? '');
    $message = trim((string) ($command['result_message'] ?? ''));
    $phase = match ($status) {
        'Pending' => 'Queued',
        'Running' => fingerprint_progress_state($command) === FINGERPRINT_PROGRESS_SCANNING
            ? 'Running'
            : 'Awaiting BTN1 Approval',
        'Done' => 'Done',
        'Failed' => 'Failed',
        default => ((string) ($employee['mapping_status'] ?? '') === 'Enrolled'
            && (string) $employee['fingerprint_status'] === 'Enrolled') ? 'Done' : 'Not Enrolled',
    };

    if ($message === '') {
        $message = match ($phase) {
            'Queued' => 'Command queued. Waiting for the ESP32 biometric terminal.',
            'Awaiting BTN1 Approval' => FINGERPRINT_MESSAGE_AWAITING_APPROVAL,
            'Running' => FINGERPRINT_MESSAGE_SCANNING,
            'Done' => 'Five-position thumb fingerprint enrolled and synchronized. Attendance is enabled.',
            'Failed' => 'Fingerprint enrollment failed. You can safely queue it again.',
            default => 'Save the employee, then queue fingerprint enrollment.',
        };
    }

    $active = in_array($status, ['Pending', 'Running'], true);
    $templateSlots = [];
    try {
        $templateSlots = fingerprint_template_slots($pdo, (int) $employee['id']);
    } catch (Throwable) {
        // The status page remains readable while the migration is being applied.
    }
    return [
        'employeeId' => (int) $employee['id'],
        'employeeNo' => (string) $employee['employee_no'],
        'phase' => $phase,
        'active' => $active,
        'message' => $message,
        'commandId' => (string) ($command['command_uuid'] ?? ''),
        'commandStatus' => $status,
        'fingerprintStatus' => (string) $employee['fingerprint_status'],
        'mappingStatus' => (string) ($employee['mapping_status'] ?? ''),
        'slot' => (int) ($command['fingerprint_slot'] ?? $employee['fingerprint_slot'] ?? $employee['fingerprint_code'] ?? 0),
        'mappingVersion' => (int) ($command['enrollment_version'] ?? $employee['enrollment_version'] ?? 0),
        'captureCount' => FINGERPRINT_ENROLLMENT_CAPTURE_COUNT,
        'positions' => FINGERPRINT_ENROLLMENT_POSITIONS,
        'templateSlots' => $templateSlots,
        'deviceId' => (string) ($employee['device_id'] ?? ''),
        'requestedAt' => $command['requested_at'] ?? null,
        'completedAt' => $command['completed_at'] ?? null,
    ];
}
