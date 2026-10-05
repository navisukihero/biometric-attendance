<?php

declare(strict_types=1);

require_once __DIR__ . '/attendance_schedule.php';

/**
 * Deterministic attendance processing.
 *
 * attendance_logs is the append-only biometric source of truth. attendance is
 * the one-row-per-employee/day projection used by reports and payroll.
 */

const ATTENDANCE_PROCESSING_VERSION = 5;

function attendance_processing_schema_ready(PDO $pdo): bool
{
    return attendance_has_integrated_metric_columns($pdo)
        && isset(attendance_table_columns($pdo, 'attendance')['schedule_periods_snapshot'])
        && attendance_has_period_schedule_schema($pdo)
        && isset(attendance_table_columns($pdo, 'attendance_logs')['event_key'])
        && isset(attendance_table_columns($pdo, 'attendance_logs')['session_index'])
        && isset(attendance_table_columns($pdo, 'attendance_logs')['punch_sequence'])
        && isset(attendance_table_columns($pdo, 'attendance_logs')['punch_request_id'])
        && isset(attendance_table_columns($pdo, 'attendance')['employment_type_snapshot'])
        && isset(attendance_table_columns($pdo, 'attendance')['legacy_single_pair'])
        && isset(attendance_table_columns($pdo, 'overtime_requests')['potential_minutes'])
        && isset(attendance_table_columns($pdo, 'holidays')['holiday_type'])
        && isset(attendance_table_columns($pdo, 'leave_requests')['leave_type']);
}

function attendance_validate_date(string $date): DateTimeImmutable
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Attendance date must use YYYY-MM-DD.');
    }

    return $parsed;
}

function attendance_event_key(
    int $employeeId,
    string $attendanceDate,
    string $action,
    int $sessionIndex = 1,
    ?string $deviceId = null,
    ?string $punchRequestId = null
): string {
    $action = strtoupper(trim($action));
    if ($employeeId < 1 || $sessionIndex < 1 || !in_array($action, ['TIME_IN', 'TIME_OUT'], true)) {
        throw new InvalidArgumentException('A valid employee and attendance action are required.');
    }
    attendance_validate_date($attendanceDate);

    $punchRequestId = trim((string) $punchRequestId);
    if ($punchRequestId !== '') {
        // The terminal reuses this id after an uncertain HTTP response. HMAC
        // nonces still change per transport attempt; this is business-event
        // idempotency so a retry can never become the next scheduled punch.
        return hash('sha256', 'ucchr-attendance-request-v2|' . trim((string) $deviceId) . '|' . $punchRequestId);
    }

    return hash(
        'sha256',
        'ucchr-attendance-session-v2|' . $employeeId . '|' . $attendanceDate . '|'
            . $sessionIndex . '|' . $action
    );
}

function attendance_normalize_punch_request_id(?string $punchRequestId): ?string
{
    $punchRequestId = trim((string) $punchRequestId);
    if ($punchRequestId === '') {
        return null;
    }
    if (strlen($punchRequestId) > 96 || !preg_match('/^[A-Za-z0-9._:-]+$/', $punchRequestId)) {
        throw new InvalidArgumentException('The attendance punch request id is invalid.');
    }
    return $punchRequestId;
}

function attendance_find_log_by_punch_request(
    PDO $pdo,
    string $deviceId,
    ?string $punchRequestId
): ?array {
    $punchRequestId = attendance_normalize_punch_request_id($punchRequestId);
    if ($punchRequestId === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM attendance_logs
         WHERE device_id=? AND punch_request_id=? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([substr(trim($deviceId), 0, 80), $punchRequestId]);
    return $stmt->fetch() ?: null;
}

/**
 * Append one accepted device event. The unique event/daily-action keys make
 * retries idempotent; rejected and duplicate scans never become raw records.
 *
 * @return array{row: array, inserted: bool}
 */
function attendance_append_accepted_log(
    PDO $pdo,
    int $employeeId,
    ?int $fingerprintId,
    string $attendanceDate,
    string $action,
    DateTimeImmutable $scannedAt,
    string $deviceId,
    ?string $commandUuid = null,
    string $source = 'ESP32 Fingerprint',
    int $sessionIndex = 1,
    ?int $punchSequence = null,
    ?string $punchRequestId = null
): array {
    if (!attendance_processing_schema_ready($pdo)) {
        throw new RuntimeException('The integrated attendance migration has not been applied.');
    }

    $action = strtoupper(trim($action));
    if ($sessionIndex < 1 || $sessionIndex > 12) {
        throw new InvalidArgumentException('Attendance session index is invalid.');
    }
    $expectedSequence = (($sessionIndex - 1) * 2) + ($action === 'TIME_IN' ? 1 : 2);
    $punchSequence ??= $expectedSequence;
    if ($punchSequence !== $expectedSequence) {
        throw new InvalidArgumentException('Attendance punch sequence does not match its session action.');
    }
    $punchRequestId = attendance_normalize_punch_request_id($punchRequestId);
    $deviceId = trim($deviceId);
    if ($deviceId === '') {
        throw new InvalidArgumentException('The biometric device id is required.');
    }
    $deviceId = substr($deviceId, 0, 80);
    $eventKey = attendance_event_key(
        $employeeId,
        $attendanceDate,
        $action,
        $sessionIndex,
        $deviceId,
        $punchRequestId
    );

    $stmt = $pdo->prepare(
        'INSERT INTO attendance_logs
            (employee_id, fingerprint_id, attendance_date, action, session_index,
             punch_sequence, scanned_at, device_id, command_uuid, source,
             punch_request_id, event_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
    );
    $stmt->execute([
        $employeeId,
        $fingerprintId && $fingerprintId > 0 ? $fingerprintId : null,
        $attendanceDate,
        $action,
        $sessionIndex,
        $punchSequence,
        $scannedAt->format('Y-m-d H:i:s.u'),
        $deviceId,
        $commandUuid !== null && trim($commandUuid) !== '' ? substr(trim($commandUuid), 0, 64) : null,
        substr(trim($source) ?: 'ESP32 Fingerprint', 0, 40),
        $punchRequestId,
        $eventKey,
    ]);
    $inserted = $stmt->rowCount() === 1;
    $id = (int) $pdo->lastInsertId();
    if ($id < 1) {
        $find = $pdo->prepare('SELECT id FROM attendance_logs WHERE event_key=? LIMIT 1');
        $find->execute([$eventKey]);
        $id = (int) $find->fetchColumn();
    }

    $find = $pdo->prepare('SELECT * FROM attendance_logs WHERE id=? LIMIT 1');
    $find->execute([$id]);
    $row = $find->fetch();
    if (
        !$row
        || (int) $row['employee_id'] !== $employeeId
        || (string) $row['attendance_date'] !== $attendanceDate
        || (string) $row['action'] !== $action
        || (int) $row['session_index'] !== $sessionIndex
    ) {
        throw new RuntimeException('The accepted attendance event could not be verified.');
    }

    return ['row' => $row, 'inserted' => $inserted];
}

/**
 * Convert ordered raw logs into independently paired work sessions.
 *
 * @return array{pairs:list<array{in_at:DateTimeImmutable,out_at:?DateTimeImmutable,session_index:int,in_log:array,out_log:?array}>,invalid:bool,first_in:?DateTimeImmutable,last_out:?DateTimeImmutable}
 */
function attendance_pair_logs(array $logs): array
{
    $pairs = [];
    $open = null;
    $invalid = false;
    $firstIn = null;
    $lastOut = null;

    foreach ($logs as $log) {
        $action = strtoupper((string) ($log['action'] ?? ''));
        try {
            $scannedAt = new DateTimeImmutable((string) ($log['scanned_at'] ?? ''));
        } catch (Throwable) {
            $invalid = true;
            continue;
        }
        $sessionIndex = max(1, (int) ($log['session_index'] ?? 1));
        if ($action === 'TIME_IN') {
            if ($open !== null) {
                $invalid = true;
                continue;
            }
            $firstIn ??= $scannedAt;
            $open = ['log' => $log, 'at' => $scannedAt, 'session_index' => $sessionIndex];
            continue;
        }
        if (
            $action !== 'TIME_OUT' || $open === null
            || $sessionIndex !== (int) $open['session_index']
            || $scannedAt <= $open['at']
        ) {
            $invalid = true;
            continue;
        }
        $pairs[] = [
            'in_at' => $open['at'],
            'out_at' => $scannedAt,
            'session_index' => $sessionIndex,
            'in_log' => $open['log'],
            'out_log' => $log,
        ];
        $lastOut = $scannedAt;
        $open = null;
    }

    if ($open !== null) {
        $pairs[] = [
            'in_at' => $open['at'],
            'out_at' => null,
            'session_index' => (int) $open['session_index'],
            'in_log' => $open['log'],
            'out_log' => null,
        ];
    }

    return [
        'pairs' => $pairs,
        'invalid' => $invalid,
        'first_in' => $firstIn,
        'last_out' => $lastOut,
    ];
}

/** Validate a proposed OUT against the actual dated IN and frozen schedule. */
function attendance_validate_out_sequence(
    string $attendanceDate,
    string $timeIn,
    array $schedule,
    DateTimeImmutable $outAt
): void {
    $inTime = attendance_valid_time($timeIn);
    if (!$inTime) {
        throw new InvalidArgumentException('The existing Time In is invalid.');
    }

    $inAt = new DateTimeImmutable($attendanceDate . ' ' . $inTime);
    $overnight = ($schedule['schedule_type'] ?? null) === ATTENDANCE_SCHEDULE_WORK
        && !empty($schedule['shift_start'])
        && !empty($schedule['shift_end'])
        && strcmp((string) $schedule['shift_end'], (string) $schedule['shift_start']) <= 0;

    if ($outAt <= $inAt) {
        throw new InvalidArgumentException('Time Out must be later than Time In.');
    }
    if (!$overnight && $outAt->format('Y-m-d') !== $attendanceDate) {
        throw new InvalidArgumentException(
            'A next-day Time Out is allowed only for a configured overnight shift.'
        );
    }
}

function attendance_processing_note(array $logs, ?string $existingNote = null): ?string
{
    if (!$logs) {
        $existingNote = trim((string) $existingNote);
        return $existingNote !== '' ? substr($existingNote, 0, 255) : null;
    }

    $devices = [];
    $commands = [];
    foreach ($logs as $log) {
        $device = trim((string) ($log['device_id'] ?? ''));
        $command = trim((string) ($log['command_uuid'] ?? ''));
        if ($device !== '') {
            $devices[$device] = true;
        }
        if ($command !== '') {
            $commands[$command] = true;
        }
    }

    $note = $devices ? 'Device: ' . implode(', ', array_keys($devices)) : 'Processed attendance';
    if ($commands) {
        $note .= '; Command: ' . implode(', ', array_keys($commands));
    }

    return substr($note, 0, 255);
}

/**
 * Synchronize an employee-submitted overtime request without creating one.
 * Biometric attendance may calculate eligible overtime, but only an explicit
 * employee request followed by HR/Admin approval can project payable minutes.
 */
function attendance_sync_overtime_request(
    PDO $pdo,
    int $employeeId,
    int $attendanceId,
    string $attendanceDate,
    int $potentialMinutes,
    int $previousApprovedMinutes
): int {
    $potentialMinutes = max(0, $potentialMinutes);
    $previousApprovedMinutes = max(0, $previousApprovedMinutes);

    $stmt = $pdo->prepare(
        'SELECT * FROM overtime_requests
         WHERE employee_id=? AND attendance_date=? AND request_source="Employee"
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$employeeId, $attendanceDate]);
    $request = $stmt->fetch() ?: null;

    if (!$request) {
        return 0;
    }

    $requestedMinutes = min(max(0, (int) $request['requested_minutes']), $potentialMinutes);
    $requestApproved = (string) $request['status'] === 'Approved'
        ? min(max(0, (int) $request['approved_minutes']), $requestedMinutes, $potentialMinutes)
        : 0;
    $stmt = $pdo->prepare(
        'UPDATE overtime_requests
         SET attendance_id=?, potential_minutes=?, requested_minutes=?, approved_minutes=?
         WHERE id=? AND request_source="Employee"'
    );
    $stmt->execute([
        $attendanceId,
        $potentialMinutes,
        $requestedMinutes,
        $requestApproved,
        (int) $request['id'],
    ]);

    return $requestApproved;
}

/**
 * Rebuild one processed day from schedule/rest, holiday, approved leave and
 * accepted raw scans, in that order.
 *
 * Returns null when a workday has no scans and has not reached shift end yet.
 */
function attendance_process_employee_date(
    PDO $pdo,
    int $employeeId,
    string $attendanceDate,
    ?DateTimeImmutable $asOf = null,
    bool $force = false
): ?array {
    if ($employeeId < 1) {
        throw new InvalidArgumentException('A valid employee is required.');
    }
    $date = attendance_validate_date($attendanceDate);
    $asOf ??= new DateTimeImmutable('now');
    if (!attendance_processing_schema_ready($pdo)) {
        throw new RuntimeException(
            'Run database/integrated_attendance_payroll_update.sql and '
                . 'database/multi_session_attendance_update.sql first.'
        );
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, status, created_at, employment_type
             FROM employees WHERE id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        if (!$employee) {
            throw new InvalidArgumentException('Employee record was not found.');
        }

        $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id=? AND scan_date=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$employeeId, $attendanceDate]);
        $existing = $stmt->fetch() ?: null;

        $schedule = $existing
            ? attendance_schedule_for_record($pdo, $existing, $employeeId, $attendanceDate)
            : attendance_schedule($pdo, $employeeId, $attendanceDate);

        $stmt = $pdo->prepare(
            'SELECT * FROM attendance_logs
             WHERE employee_id=? AND attendance_date=?
             ORDER BY punch_sequence, scanned_at, id FOR UPDATE'
        );
        $stmt->execute([$employeeId, $attendanceDate]);
        $logs = $stmt->fetchAll();
        $pairedLogs = attendance_pair_logs($logs);
        if ($pairedLogs['invalid']) {
            throw new UnexpectedValueException('Raw attendance punches are not in a valid IN/OUT session sequence.');
        }
        $punchPairs = $pairedLogs['pairs'];

        // A partially migrated historical row may not yet have synthetic raw
        // logs. Preserve it as one legacy session until reprocessing creates a
        // fully session-based projection.
        if (!$punchPairs && $existing && !empty($existing['time_in'])) {
            $legacyIn = new DateTimeImmutable($attendanceDate . ' ' . (string) $existing['time_in']);
            $legacyOut = !empty($existing['time_out'])
                ? new DateTimeImmutable($attendanceDate . ' ' . (string) $existing['time_out'])
                : null;
            if (
                $legacyOut && $legacyOut <= $legacyIn
                && $schedule['schedule_type'] === ATTENDANCE_SCHEDULE_WORK
                && $schedule['shift_start'] && $schedule['shift_end']
                && strcmp((string) $schedule['shift_end'], (string) $schedule['shift_start']) <= 0
            ) {
                $legacyOut = $legacyOut->modify('+1 day');
            }
            $punchPairs[] = [
                'in_at' => $legacyIn,
                'out_at' => $legacyOut,
                'session_index' => 1,
            ];
        }

        $firstInAt = $pairedLogs['first_in']
            ?? ($punchPairs ? $punchPairs[0]['in_at'] : null);
        $lastOutAt = $pairedLogs['last_out'];
        if (!$lastOutAt) {
            foreach ($punchPairs as $pair) {
                if (($pair['out_at'] ?? null) instanceof DateTimeImmutable) {
                    $lastOutAt = $pair['out_at'];
                }
            }
        }
        $timeIn = $firstInAt instanceof DateTimeImmutable ? $firstInAt->format('H:i:s') : null;
        $timeOut = $lastOutAt instanceof DateTimeImmutable ? $lastOutAt->format('H:i:s') : null;

        $holidayStmt = $pdo->prepare(
            'SELECT * FROM holidays
             WHERE holiday_date=? AND status="Active" ORDER BY id LIMIT 1'
        );
        $holidayStmt->execute([$attendanceDate]);
        $holiday = $holidayStmt->fetch() ?: null;

        $leaveStmt = $pdo->prepare(
            'SELECT * FROM leave_requests
             WHERE employee_id=? AND status="Approved"
               AND start_date<=? AND end_date>=?
             ORDER BY id LIMIT 1'
        );
        $leaveStmt->execute([$employeeId, $attendanceDate, $attendanceDate]);
        $leave = $leaveStmt->fetch() ?: null;

        $hasAnyScan = (bool) $punchPairs;
        $hasCompleteScans = count(array_filter(
            $punchPairs,
            static fn(array $pair): bool => ($pair['out_at'] ?? null) instanceof DateTimeImmutable
        )) > 0;
        $hasOpenSession = (bool) array_filter(
            $punchPairs,
            static fn(array $pair): bool => ($pair['out_at'] ?? null) === null
        );
        $isFuture = $date > $asOf->setTime(0, 0);
        $scheduledEndAt = null;
        if (
            $schedule['schedule_type'] === ATTENDANCE_SCHEDULE_WORK
            && $schedule['shift_start']
            && $schedule['shift_end']
        ) {
            $scheduledStartAt = new DateTimeImmutable($attendanceDate . ' ' . $schedule['shift_start']);
            $scheduledEndAt = new DateTimeImmutable($attendanceDate . ' ' . $schedule['shift_end']);
            if ($scheduledEndAt <= $scheduledStartAt) {
                $scheduledEndAt = $scheduledEndAt->modify('+1 day');
            }
        }

        $employeeCreatedDate = !empty($employee['created_at'])
            ? substr((string) $employee['created_at'], 0, 10)
            : $attendanceDate;
        $eligibleForAbsence = (string) $employee['status'] === 'Active'
            && $attendanceDate >= $employeeCreatedDate;
        $workdayClosed = $force
            || ($scheduledEndAt && $asOf >= $scheduledEndAt)
            || $date < $asOf->setTime(0, 0);

        if (!$hasAnyScan && !$existing && $isFuture && !$force) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }
        if (
            !$hasAnyScan
            && !$holiday
            && !$leave
            && $schedule['schedule_type'] === ATTENDANCE_SCHEDULE_WORK
            && (!$eligibleForAbsence || !$workdayClosed)
        ) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }

        $employmentType = trim((string) ($existing['employment_type_snapshot']
            ?? $employee['employment_type']
            ?? ''));
        $legacySinglePair = !empty($existing['legacy_single_pair'])
            && count($punchPairs) === 1
            && ($punchPairs[0]['out_at'] ?? null) instanceof DateTimeImmutable;
        if (!empty($existing['legacy_single_pair']) && count($punchPairs) > 1) {
            // A day that receives a real second session has transitioned to the
            // new schedule-session model and must never remain grandfathered.
            $pdo->prepare('UPDATE attendance SET legacy_single_pair=0 WHERE id=?')
                ->execute([(int) $existing['id']]);
            $legacySinglePair = false;
        }

        if ($legacySinglePair) {
            // Records completed before the four-punch release represented the
            // entire day with one IN/OUT envelope. Preserve their historical
            // interpretation so merely upgrading cannot turn an already-paid
            // full day into Half-Day or Absent.
            $metrics = attendance_calculate_metrics(
                $attendanceDate,
                $timeIn,
                $timeOut,
                $schedule['shift_start'],
                $schedule['shift_end'],
                attendance_grace_minutes($pdo),
                (string) $schedule['schedule_type'],
                (int) $schedule['break_minutes'],
                (array) ($schedule['periods'] ?? []),
                attendance_half_day_minimum_percent($pdo),
                attendance_full_day_minimum_percent($pdo)
            );
            $metrics['required_session_count'] = 1;
            $metrics['completed_session_count'] = 1;
            $metrics['open_session'] = false;
        } else {
            $metrics = attendance_calculate_session_metrics(
                $attendanceDate,
                $punchPairs,
                $schedule,
                $employmentType,
                attendance_grace_minutes($pdo),
                attendance_half_day_minimum_percent($pdo),
                attendance_full_day_minimum_percent($pdo)
            );
        }
        if ($metrics['invalid_sequence']) {
            throw new UnexpectedValueException('Time Out is earlier than Time In for a non-overnight shift.');
        }

        $dayClassification = 'Normal Work Day';
        if ($holiday) {
            $dayClassification = (string) $holiday['holiday_type'];
        } elseif ($leave) {
            $dayClassification = (string) $leave['leave_type'];
        } elseif ($schedule['schedule_type'] === ATTENDANCE_SCHEDULE_OFF) {
            $dayClassification = 'Rest Day';
        } elseif ($schedule['schedule_type'] === ATTENDANCE_SCHEDULE_UNSCHEDULED) {
            $dayClassification = 'Unscheduled';
        }

        $lateMinutes = (int) $metrics['late_minutes'];
        $undertimeMinutes = (int) $metrics['undertime_minutes'];
        if ($holiday || $leave || $schedule['schedule_type'] !== ATTENDANCE_SCHEDULE_WORK) {
            $lateMinutes = 0;
            $undertimeMinutes = 0;
        }

        $allRequiredSessionsComplete = (int) ($metrics['required_session_count'] ?? 0) > 0
            && (int) ($metrics['completed_session_count'] ?? 0) >= (int) $metrics['required_session_count'];
        $unrecoverableLateFinalArrival = false;
        if (
            $employmentType === 'Full-Time'
            && $hasOpenSession
            && (int) ($metrics['required_session_count'] ?? 0) > 1
            && (int) ($metrics['completed_session_count'] ?? 0) === 0
        ) {
            $metricSessions = (array) ($metrics['sessions'] ?? []);
            if (count($metricSessions) === 1) {
                $onlySession = reset($metricSessions);
                $unrecoverableLateFinalArrival = (int) ($onlySession['session_index'] ?? 0)
                    === (int) $metrics['required_session_count']
                    && empty($onlySession['on_time_entry']);
            }
        }

        if ($unrecoverableLateFinalArrival) {
            // With all earlier sessions missed and the final session's grace
            // already expired (for example, first arrival at 2:00 PM for a
            // 1:00 PM session), no eligible Half-Day remains possible.
            $status = 'ABSENT';
        } elseif ($hasAnyScan && (
            $hasOpenSession
            || ((int) ($metrics['required_session_count'] ?? 0) > 0
                && !$allRequiredSessionsComplete
                && !$workdayClosed)
        )) {
            // Keep an open punch pending only while the attendance day can still
            // be completed. Once the day is closed, resolve it deterministically
            // from the authoritative calendar/schedule instead of leaving a
            // historical INCOMPLETE row that can block payroll forever.
            if (!$workdayClosed) {
                $status = 'INCOMPLETE';
            } elseif ($leave) {
                $status = (string) $leave['leave_type'] === 'Paid Leave'
                    ? 'PAID_LEAVE'
                    : 'UNPAID_LEAVE';
            } elseif ($holiday) {
                $status = (string) $holiday['holiday_type'] === 'Regular Holiday'
                    ? 'REGULAR_HOLIDAY'
                    : 'SPECIAL_NON_WORKING_DAY';
            } elseif ($schedule['schedule_type'] === ATTENDANCE_SCHEDULE_OFF) {
                // An unfinished punch on a closed Rest Day is not payable work.
                // Preserve the scan for audit, but settle the payroll projection
                // as REST_DAY with zero regular/rest-day earnings.
                $status = 'REST_DAY';
            } elseif ($schedule['schedule_type'] === ATTENDANCE_SCHEDULE_UNSCHEDULED) {
                $status = 'UNSCHEDULED';
            } else {
                // A scheduled Work day that closes with an unfinished session is
                // an absence, not a permanently pending payroll blocker.
                $status = 'ABSENT';
            }
        } elseif ($hasCompleteScans && $holiday) {
            $status = 'HOLIDAY_WORK';
        } elseif ($hasCompleteScans && $schedule['schedule_type'] === ATTENDANCE_SCHEDULE_OFF) {
            $status = 'REST_DAY_WORK';
        } elseif ($hasCompleteScans && $schedule['schedule_type'] === ATTENDANCE_SCHEDULE_UNSCHEDULED) {
            $status = 'UNSCHEDULED';
        } elseif ($hasCompleteScans) {
            // The metrics engine applies the configured scheduled-coverage
            // thresholds before the late/undertime labels. This means a very
            // short visit can never become a paid Present day merely because
            // it has both a Time In and Time Out.
            $status = (string) ($metrics['classification_status'] ?? 'PRESENT');
        } elseif ($leave) {
            $status = (string) $leave['leave_type'] === 'Paid Leave' ? 'PAID_LEAVE' : 'UNPAID_LEAVE';
        } elseif ($holiday) {
            $status = (string) $holiday['holiday_type'] === 'Regular Holiday'
                ? 'REGULAR_HOLIDAY'
                : 'SPECIAL_NON_WORKING_DAY';
        } elseif ($schedule['schedule_type'] === ATTENDANCE_SCHEDULE_OFF) {
            $status = 'REST_DAY';
        } elseif ($schedule['schedule_type'] === ATTENDANCE_SCHEDULE_UNSCHEDULED) {
            $status = 'UNSCHEDULED';
        } else {
            $status = 'ABSENT';
        }

        $source = $logs
            ? (trim((string) ($logs[0]['source'] ?? '')) ?: 'ESP32 Fingerprint')
            : (trim((string) ($existing['source'] ?? '')) ?: 'Attendance Processor');
        $note = attendance_processing_note($logs, $existing['notes'] ?? null);
        $previousApproved = max(0, (int) ($existing['approved_overtime_minutes'] ?? 0));
        $potentialOvertime = $hasCompleteScans
            ? (int) $metrics['potential_overtime_minutes']
            : 0;

        $parameters = [
            $employeeId,
            $attendanceDate,
            $timeIn,
            $timeOut,
            $schedule['shift_start'],
            $schedule['shift_end'],
            $schedule['schedule_type'],
            $schedule['schedule_source'],
            attendance_schedule_periods_json($metrics['schedule_periods']),
            (int) $schedule['break_minutes'],
            $employmentType !== '' ? $employmentType : null,
            (int) $metrics['worked_minutes'],
            (int) $metrics['regular_minutes'],
            $lateMinutes,
            $undertimeMinutes,
            $potentialOvertime,
            $potentialOvertime,
            min($previousApproved, $potentialOvertime),
            $dayClassification,
            $holiday ? (int) $holiday['id'] : null,
            $leave ? (int) $leave['id'] : null,
            $status,
            substr($source, 0, 40),
            $note,
            ATTENDANCE_PROCESSING_VERSION,
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO attendance
                (employee_id, scan_date, time_in, time_out,
                 expected_time_in, expected_time_out, schedule_type, schedule_source,
                 schedule_periods_snapshot, break_minutes, employment_type_snapshot,
                 worked_minutes, regular_minutes, late_minutes,
                 undertime_minutes, overtime_minutes, potential_overtime_minutes,
                 approved_overtime_minutes, day_classification, holiday_id,
                 leave_request_id, status, source, notes, processed_at,
                 processing_version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                 time_in=VALUES(time_in), time_out=VALUES(time_out),
                 expected_time_in=VALUES(expected_time_in), expected_time_out=VALUES(expected_time_out),
                 schedule_type=VALUES(schedule_type), schedule_source=VALUES(schedule_source),
                 schedule_periods_snapshot=VALUES(schedule_periods_snapshot),
                 break_minutes=VALUES(break_minutes),
                 employment_type_snapshot=VALUES(employment_type_snapshot),
                 worked_minutes=VALUES(worked_minutes),
                 regular_minutes=VALUES(regular_minutes), late_minutes=VALUES(late_minutes),
                 undertime_minutes=VALUES(undertime_minutes), overtime_minutes=VALUES(overtime_minutes),
                 potential_overtime_minutes=VALUES(potential_overtime_minutes),
                 approved_overtime_minutes=VALUES(approved_overtime_minutes),
                 day_classification=VALUES(day_classification), holiday_id=VALUES(holiday_id),
                 leave_request_id=VALUES(leave_request_id), status=VALUES(status),
                 source=VALUES(source), notes=VALUES(notes), processed_at=NOW(),
                 processing_version=VALUES(processing_version), id=LAST_INSERT_ID(id)'
        );
        $stmt->execute($parameters);
        $attendanceId = (int) $pdo->lastInsertId();
        if ($attendanceId < 1) {
            $stmt = $pdo->prepare('SELECT id FROM attendance WHERE employee_id=? AND scan_date=?');
            $stmt->execute([$employeeId, $attendanceDate]);
            $attendanceId = (int) $stmt->fetchColumn();
        }

        if ($logs) {
            $stmt = $pdo->prepare(
                'UPDATE attendance_logs SET processed_attendance_id=?
                 WHERE employee_id=? AND attendance_date=?'
            );
            $stmt->execute([$attendanceId, $employeeId, $attendanceDate]);
        }

        $approvedOvertime = attendance_sync_overtime_request(
            $pdo,
            $employeeId,
            $attendanceId,
            $attendanceDate,
            $potentialOvertime,
            $previousApproved
        );
        if ($approvedOvertime !== min($previousApproved, $potentialOvertime)) {
            $stmt = $pdo->prepare('UPDATE attendance SET approved_overtime_minutes=? WHERE id=?');
            $stmt->execute([$approvedOvertime, $attendanceId]);
        }

        $stmt = $pdo->prepare('SELECT * FROM attendance WHERE id=?');
        $stmt->execute([$attendanceId]);
        $result = $stmt->fetch();
        if (!$result) {
            throw new RuntimeException('The processed attendance row could not be read.');
        }

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

/** Process all eligible employees for one date. */
function attendance_process_date(
    PDO $pdo,
    string $attendanceDate,
    ?DateTimeImmutable $asOf = null,
    bool $force = false
): array {
    attendance_validate_date($attendanceDate);
    $stmt = $pdo->prepare(
        'SELECT e.id
         FROM employees e
         WHERE (
                (e.status IN ("Active", "On leave") AND DATE(e.created_at)<=?)
                OR EXISTS (
                    SELECT 1 FROM attendance a
                    WHERE a.employee_id=e.id AND a.scan_date=?
                )
                OR EXISTS (
                    SELECT 1 FROM attendance_logs al
                    WHERE al.employee_id=e.id AND al.attendance_date=?
                )
           )
         ORDER BY e.id'
    );
    $stmt->execute([$attendanceDate, $attendanceDate, $attendanceDate]);

    $processed = 0;
    $deferred = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $employeeId) {
        $row = attendance_process_employee_date(
            $pdo,
            (int) $employeeId,
            $attendanceDate,
            $asOf,
            $force
        );
        if ($row === null) {
            $deferred++;
        } else {
            $processed++;
        }
    }

    return ['processed' => $processed, 'deferred' => $deferred];
}

/**
 * Finalize pending session-based rows whose frozen Expected Out has passed.
 *
 * PHP has no background worker of its own. The ESP32 command heartbeat invokes
 * the throttled wrapper below, while the documented nightly CLI remains the
 * fallback when the terminal is offline. This includes a completed morning
 * pair that is still waiting for its afternoon pair; scanless absences remain
 * the nightly processor's job.
 */
function attendance_finalize_due_open_rows(
    PDO $pdo,
    ?DateTimeImmutable $asOf = null,
    int $limit = 50
): array {
    $asOf ??= new DateTimeImmutable('now');
    $limit = max(1, min(200, $limit));
    if (!attendance_processing_schema_ready($pdo)) {
        return ['inspected' => 0, 'finalized' => 0];
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM attendance
         WHERE time_in IS NOT NULL
           AND status="INCOMPLETE"
           AND scan_date<=?
         ORDER BY scan_date, id
         LIMIT ' . $limit
    );
    $stmt->execute([$asOf->format('Y-m-d')]);

    $inspected = 0;
    $finalized = 0;
    foreach ($stmt->fetchAll() as $record) {
        $inspected++;
        $employeeId = (int) $record['employee_id'];
        $attendanceDate = (string) $record['scan_date'];
        $schedule = attendance_schedule_for_record($pdo, $record, $employeeId, $attendanceDate);
        if (
            $schedule['schedule_type'] !== ATTENDANCE_SCHEDULE_WORK
            || empty($schedule['shift_start'])
            || empty($schedule['shift_end'])
        ) {
            continue;
        }

        $startAt = new DateTimeImmutable($attendanceDate . ' ' . $schedule['shift_start']);
        $endAt = new DateTimeImmutable($attendanceDate . ' ' . $schedule['shift_end']);
        if ($endAt <= $startAt) {
            $endAt = $endAt->modify('+1 day');
        }
        if ($asOf < $endAt) {
            continue;
        }

        $processed = attendance_process_employee_date(
            $pdo,
            $employeeId,
            $attendanceDate,
            $asOf,
            false
        );
        if ($processed && in_array(
            strtoupper((string) $processed['status']),
            ['ABSENT', 'HALF_DAY'],
            true
        )) {
            $finalized++;
        }
    }

    return ['inspected' => $inspected, 'finalized' => $finalized];
}

/** Run due-open finalization at most once per interval across all terminals. */
function attendance_maybe_finalize_due_open_rows(
    PDO $pdo,
    ?DateTimeImmutable $asOf = null,
    int $minimumIntervalSeconds = 30,
    int $limit = 50
): array {
    $asOf ??= new DateTimeImmutable('now');
    $minimumIntervalSeconds = max(10, min(3600, $minimumIntervalSeconds));
    if (!attendance_processing_schema_ready($pdo)) {
        return ['ran' => false, 'inspected' => 0, 'finalized' => 0];
    }

    $key = 'attendance_due_finalize_at';
    $pdo->prepare(
        'INSERT INTO settings (`key`,`value`) VALUES (?, "1970-01-01 00:00:00")
         ON DUPLICATE KEY UPDATE `key`=VALUES(`key`)'
    )->execute([$key]);
    $claim = $pdo->prepare(
        'UPDATE settings SET `value`=? WHERE `key`=? AND `value`<=?'
    );
    $claim->execute([
        $asOf->format('Y-m-d H:i:s'),
        $key,
        $asOf->modify('-' . $minimumIntervalSeconds . ' seconds')->format('Y-m-d H:i:s'),
    ]);
    if ($claim->rowCount() !== 1) {
        return ['ran' => false, 'inspected' => 0, 'finalized' => 0];
    }

    return ['ran' => true] + attendance_finalize_due_open_rows($pdo, $asOf, $limit);
}

/** Process an inclusive date range. */
function attendance_process_period(
    PDO $pdo,
    string $periodStart,
    string $periodEnd,
    ?DateTimeImmutable $asOf = null,
    bool $force = false
): array {
    $start = attendance_validate_date($periodStart);
    $end = attendance_validate_date($periodEnd);
    if ($end < $start) {
        throw new InvalidArgumentException('Attendance period end cannot precede its start.');
    }

    $processed = 0;
    $deferred = 0;
    $days = 0;
    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $result = attendance_process_date($pdo, $date->format('Y-m-d'), $asOf, $force);
        $processed += (int) $result['processed'];
        $deferred += (int) $result['deferred'];
        $days++;
    }

    return ['days' => $days, 'processed' => $processed, 'deferred' => $deferred];
}
