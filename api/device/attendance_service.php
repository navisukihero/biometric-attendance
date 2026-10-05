<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/attendance_processing.php';

final class BiometricAttendanceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

const BIOMETRIC_ATTENDANCE_COMMAND_RESULT_PREFIX = 'attendance-result:';

/**
 * Persist the attendance action together with its human-readable message in
 * the existing 255-character device_commands.result_message column. Keeping
 * the action lets a lost HTTP response be retried without guessing whether the
 * original result was TIME_IN, ALREADY_IN, DAY_CLOSED, and so on.
 *
 * @return array{action:string,message:string}
 */
function biometric_attendance_unpack_command_result(string $stored): array
{
    if (!str_starts_with($stored, BIOMETRIC_ATTENDANCE_COMMAND_RESULT_PREFIX)) {
        return ['action' => '', 'message' => $stored];
    }

    $encoded = substr($stored, strlen(BIOMETRIC_ATTENDANCE_COMMAND_RESULT_PREFIX));
    $separator = strpos($encoded, '|');
    if ($separator === false) {
        return ['action' => '', 'message' => $stored];
    }

    $action = substr($encoded, 0, $separator);
    if (!preg_match('/^[A-Z0-9_]{1,40}$/', $action)) {
        return ['action' => '', 'message' => $stored];
    }

    return [
        'action' => $action,
        'message' => substr($encoded, $separator + 1),
    ];
}

function biometric_attendance_pack_command_result(array $result): string
{
    $action = strtoupper(trim((string) ($result['action'] ?? 'ATTENDANCE_PROCESSED')));
    if (!preg_match('/^[A-Z0-9_]{1,40}$/', $action)) {
        $action = 'ATTENDANCE_PROCESSED';
    }
    $prefix = BIOMETRIC_ATTENDANCE_COMMAND_RESULT_PREFIX . $action . '|';
    $message = preg_replace('/\s+/u', ' ', trim((string) ($result['message'] ?? 'Attendance request processed.')))
        ?: 'Attendance request processed.';
    return $prefix . substr($message, 0, max(0, 255 - strlen($prefix)));
}

/**
 * Finish a website attendance command in the caller's attendance transaction.
 * The accepted punch, daily projection, command state and audit event therefore
 * either commit together or roll back together.
 */
function biometric_attendance_complete_web_command(
    PDO $pdo,
    array $command,
    string $deviceId,
    array $result
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Attendance command completion requires an active transaction.');
    }
    if (
        (string) ($command['command_type'] ?? '') !== 'VERIFY_ATTENDANCE'
        || (string) ($command['status'] ?? '') !== 'Running'
    ) {
        throw new DomainException('Attendance command is not active.');
    }

    $storedResult = biometric_attendance_pack_command_result($result);
    $stmt = $pdo->prepare(
        'UPDATE device_commands
         SET status="Done", result_message=?, completed_at=NOW()
         WHERE id=? AND command_type="VERIFY_ATTENDANCE"
           AND status="Running" AND device_id=?'
    );
    $stmt->execute([$storedResult, (int) $command['id'], $deviceId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Attendance command changed before completion.');
    }

    $message = trim((string) ($result['message'] ?? 'Attendance request processed.'));
    $activity = 'Completed two-scan attendance verification for employee #'
        . (int) $command['employee_id'] . ': ' . $message;
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, action, created_at) VALUES (?, ?, NOW())'
    );
    $stmt->execute([
        $command['requested_by'] ?: null,
        substr($activity, 0, 255),
    ]);
}

/**
 * Repair the narrow legacy failure window where attendance.php committed an
 * accepted punch but command_result.php never reached the server. New requests
 * are atomic; this keeps already-saved punches from remaining Failed/Running
 * and being offered to the terminal again after an upgrade.
 */
function biometric_attendance_reconcile_saved_commands(PDO $pdo): int
{
    $prefix = BIOMETRIC_ATTENDANCE_COMMAND_RESULT_PREFIX;
    $stmt = $pdo->prepare(
        'UPDATE device_commands dc
         JOIN attendance_logs al ON al.command_uuid=dc.command_uuid
         SET dc.status="Done",
             dc.result_message=CONCAT(
                 ?,
                 CASE al.action
                     WHEN "TIME_IN" THEN "TIME_IN"
                     WHEN "TIME_OUT" THEN "TIME_OUT"
                     ELSE "ALREADY_RECORDED"
                 END,
                 "|Attendance punch was saved; command state recovered safely."
             ),
             dc.completed_at=COALESCE(dc.completed_at, al.scanned_at)
         WHERE dc.command_type="VERIFY_ATTENDANCE"
           AND dc.status IN ("Pending", "Running", "Failed")'
    );
    $stmt->execute([$prefix]);
    return $stmt->rowCount();
}

function attendance_note(string $deviceId, ?string $commandId): string
{
    $note = 'Device: ' . substr($deviceId, 0, 80);
    if ($commandId !== null && $commandId !== '') {
        $note .= '; Command: ' . substr($commandId, 0, 64);
    }
    return substr($note, 0, 255);
}

/**
 * Device-facing schedule/state metadata. The web server remains authoritative;
 * the ESP32 only displays these resolved values and never calculates payroll.
 */
function biometric_attendance_context(
    array $schedule,
    string $attendanceDate,
    string $attendanceState,
    string $nextAction
): array {
    return [
        'attendanceDate' => $attendanceDate,
        'attendanceState' => $attendanceState,
        'nextAction' => $nextAction,
        'expectedTimeIn' => $schedule['shift_start'] ?? null,
        'expectedTimeOut' => $schedule['shift_end'] ?? null,
        'scheduleType' => (string) ($schedule['schedule_type'] ?? ATTENDANCE_SCHEDULE_UNSCHEDULED),
        'scheduleSource' => (string) ($schedule['schedule_source'] ?? 'Unscheduled'),
    ];
}

function biometric_attendance_processed_context(
    array $processed,
    string $attendanceDate,
    string $attendanceState,
    string $nextAction
): array {
    return [
        'attendanceDate' => $attendanceDate,
        'attendanceState' => $attendanceState,
        'nextAction' => $nextAction,
        'expectedTimeIn' => $processed['expected_time_in'] ?? null,
        'expectedTimeOut' => $processed['expected_time_out'] ?? null,
        'scheduleType' => (string) ($processed['schedule_type'] ?? ATTENDANCE_SCHEDULE_UNSCHEDULED),
        'scheduleSource' => (string) ($processed['schedule_source'] ?? 'Unscheduled'),
    ];
}

/** Use INCOMPLETE on partially upgraded schemas that already support it. */
function biometric_attendance_pending_storage_status(PDO $pdo, string $legacyFallback): string
{
    try {
        $column = $pdo->query('SHOW COLUMNS FROM attendance LIKE "status"')->fetch();
        if ($column && str_contains(strtoupper((string) ($column['Type'] ?? '')), "'INCOMPLETE'")) {
            return 'INCOMPLETE';
        }
    } catch (Throwable) {
    }
    return $legacyFallback;
}

/**
 * Decide whether an open attendance row has reached a valid schedule-based
 * Time Out point. This prevents an immediate repeat scan from closing a shift.
 * A conventional balanced full day permits a morning half-day Time Out at the
 * start of the configured break; otherwise the employee's Expected Out is used.
 */
function biometric_attendance_auto_time_out_ready(
    array $schedule,
    string $attendanceDate,
    string $timeIn,
    DateTimeImmutable $moment,
    int $graceMinutes
): bool {
    $actualInAt = new DateTimeImmutable($attendanceDate . ' ' . $timeIn);
    $minimumOutAt = $actualInAt->modify('+1 minute');
    if (($schedule['schedule_type'] ?? null) !== ATTENDANCE_SCHEDULE_WORK
        || empty($schedule['shift_start'])
        || empty($schedule['shift_end'])
    ) {
        return $moment >= $minimumOutAt;
    }

    $scheduledStartAt = new DateTimeImmutable($attendanceDate . ' ' . $schedule['shift_start']);
    $scheduledEndAt = new DateTimeImmutable($attendanceDate . ' ' . $schedule['shift_end']);
    if ($scheduledEndAt <= $scheduledStartAt) {
        $scheduledEndAt = $scheduledEndAt->modify('+1 day');
    }

    $eligibleOutAt = $scheduledEndAt;
    $periods = attendance_normalize_schedule_periods((array) ($schedule['periods'] ?? []));
    $scheduledMinutes = (int) ($schedule['scheduled_minutes'] ?? 0);
    if ($periods) {
        $scheduledMinutes = array_sum(array_column($periods, 'minutes'));
    } elseif ($scheduledMinutes <= 0) {
        $scheduledMinutes = max(
            0,
            intdiv($scheduledEndAt->getTimestamp() - $scheduledStartAt->getTimestamp(), 60)
                - max(0, (int) ($schedule['break_minutes'] ?? 0))
        );
    }

    // Match the attendance classifier's balanced two-session half-day rule.
    if (count($periods) === 2) {
        $firstMinutes = (int) $periods[0]['minutes'];
        $secondMinutes = (int) $periods[1]['minutes'];
        $balancedFullDay = $scheduledMinutes >= 360
            && $firstMinutes >= 120
            && $secondMinutes >= 120
            && abs($firstMinutes - $secondMinutes) <= 15;
        if ($balancedFullDay) {
            $firstStartAt = new DateTimeImmutable($attendanceDate . ' ' . $periods[0]['period_start']);
            if ($actualInAt <= $firstStartAt->modify('+' . max(0, $graceMinutes) . ' minutes')) {
                $eligibleOutAt = new DateTimeImmutable($attendanceDate . ' ' . $periods[0]['period_end']);
            }
        }
    } elseif (!$periods && (int) ($schedule['break_minutes'] ?? 0) > 0 && $scheduledMinutes >= 360) {
        $breakSeconds = min(
            $scheduledEndAt->getTimestamp() - $scheduledStartAt->getTimestamp(),
            max(0, (int) $schedule['break_minutes']) * 60
        );
        $breakStartAt = $scheduledStartAt->modify(
            '+' . (int) floor(
                ($scheduledEndAt->getTimestamp() - $scheduledStartAt->getTimestamp() - $breakSeconds) / 2
            ) . ' seconds'
        );
        if ($actualInAt <= $scheduledStartAt->modify('+' . max(0, $graceMinutes) . ' minutes')) {
            $eligibleOutAt = $breakStartAt;
        }
    }

    if ($eligibleOutAt < $minimumOutAt) {
        $eligibleOutAt = $minimumOutAt;
    }
    return $moment >= $eligibleOutAt;
}

/** @return list<array<string,mixed>> */
function biometric_attendance_logs(PDO $pdo, int $employeeId, string $attendanceDate): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM attendance_logs
         WHERE employee_id=? AND attendance_date=?
         ORDER BY punch_sequence, scanned_at, id FOR UPDATE'
    );
    $stmt->execute([$employeeId, $attendanceDate]);
    return $stmt->fetchAll();
}

/**
 * Resolve the next schedule-backed punch from raw session events.
 *
 * @return array<string,mixed>
 */
function biometric_attendance_session_state(
    array $schedule,
    string $attendanceDate,
    string $employmentType,
    array $logs,
    DateTimeImmutable $moment,
    int $graceMinutes
): array {
    $sessions = attendance_effective_schedule_sessions($attendanceDate, $schedule, $employmentType);
    $logsBySession = [];
    foreach ($logs as $log) {
        $index = max(1, (int) ($log['session_index'] ?? 1));
        $logsBySession[$index][(string) $log['action']] = $log;
    }

    $requiredSessionCount = count($sessions);
    $requiredPunches = $requiredSessionCount * 2;
    $completedPunches = count($logs);
    $completedSessions = 0;
    $openSession = null;
    foreach ($logsBySession as $index => $events) {
        if (!empty($events['TIME_IN']) && !empty($events['TIME_OUT'])) {
            $completedSessions++;
        } elseif (!empty($events['TIME_IN'])) {
            $openSession = $index;
        }
    }

    // Rest-day/unscheduled attendance retains its existing single-pair flow.
    // It is not a required scheduled session and is never used to invent
    // regular scheduled hours.
    if (!$sessions) {
        $in = $logsBySession[1]['TIME_IN'] ?? null;
        $out = $logsBySession[1]['TIME_OUT'] ?? null;
        if ($in && !$out) {
            $inAt = new DateTimeImmutable((string) $in['scanned_at']);
            return [
                'state' => 'WAITING_FOR_SESSION_OUT',
                'next_action' => 'OUT',
                'session_index' => 1,
                'session_label' => 'ATTENDANCE',
                'session_count' => 1,
                'required_punches' => 2,
                'completed_punches' => $completedPunches,
                'completed_sessions' => 0,
                'punch_ordinal' => 2,
                'next_eligible_at' => $inAt->modify('+1 minute'),
                'sessions' => [],
            ];
        }
        if ($in && $out) {
            return [
                'state' => 'COMPLETE',
                'next_action' => 'NONE',
                'session_index' => 1,
                'session_label' => 'ATTENDANCE',
                'session_count' => 1,
                'required_punches' => 2,
                'completed_punches' => $completedPunches,
                'completed_sessions' => 1,
                'punch_ordinal' => 2,
                'next_eligible_at' => null,
                'sessions' => [],
            ];
        }
        return [
            'state' => 'WAITING_FOR_SESSION_IN',
            'next_action' => 'IN',
            'session_index' => 1,
            'session_label' => 'ATTENDANCE',
            'session_count' => 1,
            'required_punches' => 2,
            'completed_punches' => 0,
            'completed_sessions' => 0,
            'punch_ordinal' => 1,
            'next_eligible_at' => null,
            'sessions' => [],
        ];
    }

    if ($openSession !== null && isset($sessions[$openSession - 1])) {
        $session = $sessions[$openSession - 1];
        return [
            'state' => 'WAITING_FOR_SESSION_OUT',
            'next_action' => 'OUT',
            'session_index' => $openSession,
            'session_label' => $session['label'],
            'session_count' => $requiredSessionCount,
            'required_punches' => $requiredPunches,
            'completed_punches' => $completedPunches,
            'completed_sessions' => $completedSessions,
            'punch_ordinal' => (($openSession - 1) * 2) + 2,
            'next_eligible_at' => $session['end_at']->modify('-' . max(0, $graceMinutes) . ' minutes'),
            'sessions' => $sessions,
        ];
    }

    $candidate = null;
    foreach ($sessions as $session) {
        $index = (int) $session['session_index'];
        if (!empty($logsBySession[$index]['TIME_OUT'])) {
            continue;
        }
        // A missed earlier period does not block a later valid half-day. The
        // first 14:00 scan therefore maps only to Afternoon, never Morning.
        if ($moment >= $session['end_at']) {
            continue;
        }
        $candidate = $session;
        break;
    }

    if (!$candidate) {
        return [
            'state' => $completedSessions === $requiredSessionCount ? 'COMPLETE' : 'DAY_CLOSED',
            'next_action' => 'NONE',
            'session_index' => $requiredSessionCount,
            'session_label' => $sessions[array_key_last($sessions)]['label'],
            'session_count' => $requiredSessionCount,
            'required_punches' => $requiredPunches,
            'completed_punches' => $completedPunches,
            'completed_sessions' => $completedSessions,
            'punch_ordinal' => $requiredPunches,
            'next_eligible_at' => null,
            'sessions' => $sessions,
        ];
    }

    $candidateIndex = (int) $candidate['session_index'];
    $earlyInAt = $candidateIndex === 1 && !$logs
        ? null
        : $candidate['start_at']->modify('-' . max(0, $graceMinutes) . ' minutes');
    $betweenSessions = $earlyInAt && $moment < $earlyInAt;
    return [
        'state' => $betweenSessions ? 'BETWEEN_SESSIONS' : 'WAITING_FOR_SESSION_IN',
        'next_action' => 'IN',
        'session_index' => $candidateIndex,
        'session_label' => $candidate['label'],
        'session_count' => $requiredSessionCount,
        'required_punches' => $requiredPunches,
        'completed_punches' => $completedPunches,
        'completed_sessions' => $completedSessions,
        'punch_ordinal' => (($candidateIndex - 1) * 2) + 1,
        'next_eligible_at' => $earlyInAt,
        'sessions' => $sessions,
    ];
}

function biometric_attendance_state_context(
    array $schedule,
    string $attendanceDate,
    array $state
): array {
    $nextEligible = $state['next_eligible_at'] ?? null;
    return [
        'attendanceDate' => $attendanceDate,
        'attendanceState' => (string) $state['state'],
        'nextAction' => (string) $state['next_action'],
        'expectedTimeIn' => $schedule['shift_start'] ?? null,
        'expectedTimeOut' => $schedule['shift_end'] ?? null,
        'scheduleType' => (string) ($schedule['schedule_type'] ?? ATTENDANCE_SCHEDULE_UNSCHEDULED),
        'scheduleSource' => (string) ($schedule['schedule_source'] ?? 'Unscheduled'),
        'sessionIndex' => (int) $state['session_index'],
        'sessionCount' => (int) $state['session_count'],
        'sessionLabel' => (string) $state['session_label'],
        'punchOrdinal' => (int) $state['punch_ordinal'],
        'completedPunches' => (int) $state['completed_punches'],
        'requiredPunches' => (int) $state['required_punches'],
        'completedSessions' => (int) $state['completed_sessions'],
        'nextEligibleAt' => $nextEligible instanceof DateTimeImmutable
            ? $nextEligible->format('Y-m-d H:i:s')
            : null,
    ];
}

/**
 * Records one biometric attendance event and returns a device/API-friendly result.
 * AUTO atomically resolves the next action from ordered raw session punches.
 * The employee lock plus stable punchRequestId makes concurrent/retried scans safe.
 */
function record_biometric_attendance(
    PDO $pdo,
    array $employee,
    string $eventType,
    string $deviceId,
    ?string $commandId = null,
    ?DateTimeImmutable $moment = null,
    ?string $punchRequestId = null
): array {
    // Keep scanning operational while an older installation is awaiting the
    // additive migration. Once migrated, every accepted scan is written to the
    // append-only log and the daily row is rebuilt deterministically.
    if (!attendance_processing_schema_ready($pdo)) {
        return record_biometric_attendance_legacy(
            $pdo,
            $employee,
            $eventType,
            $deviceId,
            $commandId,
            $moment
        );
    }

    return record_biometric_attendance_integrated(
        $pdo,
        $employee,
        $eventType,
        $deviceId,
        $commandId,
        $moment,
        $punchRequestId
    );
}

function record_biometric_attendance_integrated(
    PDO $pdo,
    array $employee,
    string $eventType,
    string $deviceId,
    ?string $commandId = null,
    ?DateTimeImmutable $moment = null,
    ?string $punchRequestId = null
): array {
    $eventType = strtoupper(trim($eventType));
    if (!in_array($eventType, ['AUTO', 'IN', 'OUT'], true)) {
        throw new BiometricAttendanceException('Event type must be AUTO, IN, or OUT.');
    }

    $employeeId = (int) ($employee['id'] ?? 0);
    if ($employeeId <= 0) {
        throw new BiometricAttendanceException('Employee record is invalid.');
    }

    $moment ??= new DateTimeImmutable('now');
    $today = $moment->format('Y-m-d');
    $employeeName = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''));
    $employeeNo = (string) ($employee['employee_no'] ?? '');
    $fingerprintId = (int) ($employee['fingerprint_slot'] ?? $employee['fingerprint_code'] ?? 0);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Lock the employee even before the daily projection exists. This
        // serializes two terminals scanning the same employee concurrently.
        $lockEmployee = $pdo->prepare('SELECT id FROM employees WHERE id=? LIMIT 1 FOR UPDATE');
        $lockEmployee->execute([$employeeId]);
        if (!$lockEmployee->fetchColumn()) {
            throw new BiometricAttendanceException('Employee record is invalid.', 404);
        }

        $punchRequestId = attendance_normalize_punch_request_id(
            $punchRequestId ?: ($commandId ?: null)
        );
        $retryLog = attendance_find_log_by_punch_request($pdo, $deviceId, $punchRequestId);
        if ($retryLog) {
            if ((int) $retryLog['employee_id'] !== $employeeId) {
                throw new BiometricAttendanceException('Punch request id belongs to a different employee.', 409);
            }
            $scanDate = (string) $retryLog['attendance_date'];
            $recordStmt = $pdo->prepare(
                'SELECT * FROM attendance WHERE employee_id=? AND scan_date=? LIMIT 1 FOR UPDATE'
            );
            $recordStmt->execute([$employeeId, $scanDate]);
            $record = $recordStmt->fetch() ?: null;
            $schedule = $record
                ? attendance_schedule_for_record($pdo, $record, $employeeId, $scanDate)
                : attendance_schedule($pdo, $employeeId, $scanDate);
            $employmentType = trim((string) ($record['employment_type_snapshot']
                ?? $employee['employment_type']
                ?? ''));
            $processed = attendance_process_employee_date($pdo, $employeeId, $scanDate, $moment);
            $logs = biometric_attendance_logs($pdo, $employeeId, $scanDate);
            $state = biometric_attendance_session_state(
                $schedule,
                $scanDate,
                $employmentType,
                $logs,
                $moment,
                attendance_grace_minutes($pdo)
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'employeeName' => $employeeName,
                'employeeNo' => $employeeNo,
                'action' => 'ALREADY_RECORDED',
                'recordedAction' => (string) $retryLog['action'],
                'message' => 'This biometric punch was already saved safely.',
                'attendanceId' => (int) ($processed['id'] ?? 0),
                'status' => (string) ($processed['status'] ?? ''),
                'approvedOvertimeMinutes' => (int) ($processed['approved_overtime_minutes'] ?? 0),
            ] + biometric_attendance_state_context($schedule, $scanDate, $state);
        }

        $scanDate = $today;
        $recordStmt = $pdo->prepare(
            'SELECT * FROM attendance WHERE employee_id=? AND scan_date=? LIMIT 1 FOR UPDATE'
        );
        $recordStmt->execute([$employeeId, $scanDate]);
        $record = $recordStmt->fetch() ?: null;

        // Resolve a next-day OUT against yesterday only when yesterday has an
        // open punch and its frozen schedule is genuinely overnight.
        if (in_array($eventType, ['AUTO', 'OUT'], true)) {
            $yesterday = $moment->modify('-1 day')->format('Y-m-d');
            $yesterdayLogs = biometric_attendance_logs($pdo, $employeeId, $yesterday);
            if ($yesterdayLogs) {
                $pairedYesterday = attendance_pair_logs($yesterdayLogs);
                $hasOpenYesterday = (bool) array_filter(
                    $pairedYesterday['pairs'],
                    static fn(array $pair): bool => ($pair['out_at'] ?? null) === null
                );
                if ($hasOpenYesterday) {
                    $previousStmt = $pdo->prepare(
                        'SELECT * FROM attendance WHERE employee_id=? AND scan_date=? LIMIT 1 FOR UPDATE'
                    );
                    $previousStmt->execute([$employeeId, $yesterday]);
                    $previousRecord = $previousStmt->fetch() ?: null;
                    $previousSchedule = $previousRecord
                        ? attendance_schedule_for_record($pdo, $previousRecord, $employeeId, $yesterday)
                        : attendance_schedule($pdo, $employeeId, $yesterday);
                    if (
                        $previousSchedule['schedule_type'] === ATTENDANCE_SCHEDULE_WORK
                        && $previousSchedule['shift_start'] && $previousSchedule['shift_end']
                        && strcmp((string) $previousSchedule['shift_end'], (string) $previousSchedule['shift_start']) <= 0
                    ) {
                        $scanDate = $yesterday;
                        $record = $previousRecord;
                    }
                }
            }
        }

        $schedule = $record
            ? attendance_schedule_for_record($pdo, $record, $employeeId, $scanDate)
            : attendance_schedule($pdo, $employeeId, $scanDate);
        $employmentType = trim((string) ($record['employment_type_snapshot']
            ?? $employee['employment_type']
            ?? ''));
        $logs = biometric_attendance_logs($pdo, $employeeId, $scanDate);
        $state = biometric_attendance_session_state(
            $schedule,
            $scanDate,
            $employmentType,
            $logs,
            $moment,
            attendance_grace_minutes($pdo)
        );

        $context = biometric_attendance_state_context($schedule, $scanDate, $state);
        if ($state['state'] === 'COMPLETE') {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'employeeName' => $employeeName,
                'employeeNo' => $employeeNo,
                'action' => 'ALREADY_OUT',
                'message' => 'All required attendance punches are already complete.',
                'attendanceId' => (int) ($record['id'] ?? 0),
                'status' => (string) ($record['status'] ?? ''),
            ] + $context;
        }
        if ($state['state'] === 'DAY_CLOSED') {
            $processed = attendance_process_employee_date($pdo, $employeeId, $scanDate, $moment, true);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'employeeName' => $employeeName,
                'employeeNo' => $employeeNo,
                'action' => 'DAY_CLOSED',
                'message' => 'The assigned work schedule has ended. No duplicate punch was created.',
                'attendanceId' => (int) ($processed['id'] ?? 0),
                'status' => (string) ($processed['status'] ?? 'ABSENT'),
            ] + $context;
        }
        if ($state['state'] === 'BETWEEN_SESSIONS') {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            $nextTime = $state['next_eligible_at'] instanceof DateTimeImmutable
                ? $state['next_eligible_at']->format('g:i A')
                : 'the next session';
            return [
                'employeeName' => $employeeName,
                'employeeNo' => $employeeNo,
                'action' => 'NOT_YET_ELIGIBLE',
                'message' => 'Next ' . strtolower((string) $state['session_label']) . ' time-in opens at ' . $nextTime . '.',
                'attendanceId' => (int) ($record['id'] ?? 0),
                'status' => (string) ($record['status'] ?? 'INCOMPLETE'),
            ] + $context;
        }

        $expectedAction = (string) $state['next_action'];
        if ($expectedAction === 'OUT') {
            if ($eventType === 'IN') {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return [
                    'employeeName' => $employeeName,
                    'employeeNo' => $employeeNo,
                    'action' => 'ALREADY_IN',
                    'message' => ucfirst(strtolower((string) $state['session_label'])) . ' time-in is already saved.',
                    'attendanceId' => (int) ($record['id'] ?? 0),
                    'status' => (string) ($record['status'] ?? 'INCOMPLETE'),
                ] + $context;
            }
            if (
                $eventType === 'AUTO'
                && $state['next_eligible_at'] instanceof DateTimeImmutable
                && $moment < $state['next_eligible_at']
            ) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return [
                    'employeeName' => $employeeName,
                    'employeeNo' => $employeeNo,
                    'action' => 'ALREADY_IN',
                    'message' => 'Time-in is already saved; wait until the scheduled time-out window.',
                    'attendanceId' => (int) ($record['id'] ?? 0),
                    'status' => (string) ($record['status'] ?? 'INCOMPLETE'),
                ] + $context;
            }
            $sessionIn = null;
            foreach ($logs as $log) {
                if (
                    (int) ($log['session_index'] ?? 0) === (int) $state['session_index']
                    && (string) $log['action'] === 'TIME_IN'
                ) {
                    $sessionIn = new DateTimeImmutable((string) $log['scanned_at']);
                    break;
                }
            }
            if (!$sessionIn || $moment <= $sessionIn->modify('+1 minute')) {
                throw new BiometricAttendanceException('Wait at least one minute after Time In before recording Time Out.', 409);
            }
        } elseif ($eventType === 'OUT') {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'employeeName' => $employeeName,
                'employeeNo' => $employeeNo,
                'action' => $logs ? 'ALREADY_OUT' : 'TIME_IN_REQUIRED',
                'message' => $logs
                    ? 'The previous session is timed out. The next required punch is Time In.'
                    : 'Time In must be recorded before Time Out.',
                'attendanceId' => (int) ($record['id'] ?? 0),
                'status' => (string) ($record['status'] ?? ''),
            ] + $context;
        }

        $acceptedAction = $expectedAction === 'OUT' ? 'TIME_OUT' : 'TIME_IN';
        $raw = attendance_append_accepted_log(
            $pdo,
            $employeeId,
            $fingerprintId > 0 ? $fingerprintId : null,
            $scanDate,
            $acceptedAction,
            $moment,
            $deviceId,
            $commandId,
            'ESP32 Fingerprint',
            (int) $state['session_index'],
            (int) $state['punch_ordinal'],
            $punchRequestId
        );
        $processed = attendance_process_employee_date($pdo, $employeeId, $scanDate, $moment);
        if (!$processed) {
            throw new RuntimeException('The accepted attendance event was not processed.');
        }

        $updatedLogs = biometric_attendance_logs($pdo, $employeeId, $scanDate);
        $updatedState = biometric_attendance_session_state(
            $schedule,
            $scanDate,
            $employmentType,
            $updatedLogs,
            $moment,
            attendance_grace_minutes($pdo)
        );
        $attendanceId = (int) $processed['id'];
        if ($ownsTransaction) {
            $pdo->commit();
        }

        if (!$raw['inserted']) {
            return [
                'employeeName' => $employeeName,
                'employeeNo' => $employeeNo,
                'action' => 'ALREADY_RECORDED',
                'recordedAction' => $acceptedAction,
                'message' => 'This biometric punch was already saved safely.',
                'attendanceId' => $attendanceId,
                'status' => (string) ($processed['status'] ?? ''),
                'approvedOvertimeMinutes' => (int) ($processed['approved_overtime_minutes'] ?? 0),
            ] + biometric_attendance_state_context($schedule, $scanDate, $updatedState);
        }

        $isIn = $acceptedAction === 'TIME_IN';
        $sessionName = ucfirst(strtolower((string) $state['session_label']));
        return [
            'employeeName' => $employeeName,
            'employeeNo' => $employeeNo,
            'action' => $acceptedAction,
            'message' => $sessionName . ' ' . ($isIn ? 'time-in' : 'time-out')
                . ' recorded at ' . $moment->format('g:i A') . '.',
            'attendanceId' => $attendanceId,
            'status' => (string) $processed['status'],
            'workedMinutes' => (int) $processed['worked_minutes'],
            'regularMinutes' => (int) $processed['regular_minutes'],
            'lateMinutes' => (int) $processed['late_minutes'],
            'undertimeMinutes' => (int) $processed['undertime_minutes'],
            'approvedOvertimeMinutes' => (int) $processed['approved_overtime_minutes'],
            'late' => (int) $processed['late_minutes'] > 0,
            'undertime' => (int) $processed['undertime_minutes'] > 0,
            'overtimePayable' => (int) $processed['approved_overtime_minutes'] > 0,
        ] + biometric_attendance_state_context($schedule, $scanDate, $updatedState);
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** Pre-migration compatibility path; remove only after every deployment migrates. */
function record_biometric_attendance_legacy(
    PDO $pdo,
    array $employee,
    string $eventType,
    string $deviceId,
    ?string $commandId = null,
    ?DateTimeImmutable $moment = null
): array {
    $eventType = strtoupper(trim($eventType));
    if (!in_array($eventType, ['AUTO', 'IN', 'OUT'], true)) {
        throw new BiometricAttendanceException('Event type must be AUTO, IN, or OUT.');
    }

    $employeeId = (int) ($employee['id'] ?? 0);
    if ($employeeId <= 0) {
        throw new BiometricAttendanceException('Employee record is invalid.');
    }

    $moment ??= new DateTimeImmutable('now');
    $today = $moment->format('Y-m-d');
    $now = $moment->format('H:i:s');
    $employeeName = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? ''));
    $employeeNo = (string) ($employee['employee_no'] ?? '');
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND scan_date = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$employeeId, $today]);
        $record = $stmt->fetch() ?: null;

        if (
            in_array($eventType, ['AUTO', 'OUT'], true)
            && (!$record || empty($record['time_in']))
        ) {
            // Support a shift that begins before midnight and ends the next day.
            // Do not let an ordinary missed time-out from yesterday consume
            // today's OUT request; only a configured overnight shift qualifies.
            $stmt = $pdo->prepare('SELECT * FROM attendance WHERE employee_id = ? AND scan_date >= DATE_SUB(?, INTERVAL 1 DAY) AND scan_date < ? AND time_in IS NOT NULL AND time_out IS NULL ORDER BY scan_date DESC LIMIT 1 FOR UPDATE');
            $stmt->execute([$employeeId, $today, $today]);
            $overnightRecord = $stmt->fetch() ?: null;
            if ($overnightRecord) {
                $overnightSchedule = attendance_schedule_for_record(
                    $pdo,
                    $overnightRecord,
                    $employeeId,
                    (string) $overnightRecord['scan_date']
                );
                $overnightExpectedIn = $overnightSchedule['shift_start'];
                $overnightExpectedOut = $overnightSchedule['shift_end'];
                if (
                    $overnightSchedule['schedule_type'] === ATTENDANCE_SCHEDULE_WORK
                    && $overnightExpectedIn
                    && $overnightExpectedOut
                    && strcmp((string) $overnightExpectedOut, (string) $overnightExpectedIn) <= 0
                ) {
                    $record = $overnightRecord;
                }
            }
        }

        if ($eventType === 'AUTO') {
            if ($record && !empty($record['time_in']) && empty($record['time_out'])) {
                $scanDate = (string) $record['scan_date'];
                $schedule = attendance_schedule_for_record($pdo, $record, $employeeId, $scanDate);
                $eventType = biometric_attendance_auto_time_out_ready(
                    $schedule,
                    $scanDate,
                    (string) $record['time_in'],
                    $moment,
                    attendance_grace_minutes($pdo)
                ) ? 'OUT' : 'IN';
            } else {
                $eventType = $record && !empty($record['time_in']) ? 'OUT' : 'IN';
            }
        }

        if ($eventType === 'IN') {
            if ($record && !empty($record['time_in'])) {
                $scanDate = (string) $record['scan_date'];
                $schedule = attendance_schedule_for_record($pdo, $record, $employeeId, $scanDate);
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return [
                    'employeeName' => $employeeName,
                    'employeeNo' => $employeeNo,
                    'action' => 'ALREADY_IN',
                    'message' => 'Time-in already recorded at ' . date('g:i A', strtotime((string) $record['time_in'])) . '.',
                    'attendanceId' => (int) $record['id'],
                ] + biometric_attendance_context($schedule, $scanDate, 'PENDING_TIME_OUT', 'OUT');
            }

            $schedule = attendance_schedule($pdo, $employeeId, $today);
            $expectedIn = $schedule['shift_start'];
            $expectedOut = $schedule['shift_end'];
            $graceMinutes = attendance_grace_minutes($pdo);
            $metrics = attendance_calculate_metrics(
                $today,
                $now,
                null,
                $expectedIn,
                $expectedOut,
                $graceMinutes,
                $schedule['schedule_type'],
                (int) $schedule['break_minutes'],
                $schedule['periods'],
                attendance_half_day_minimum_percent($pdo),
                attendance_full_day_minimum_percent($pdo)
            );
            $lateMinutes = (int) $metrics['late_minutes'];
            // A Time In by itself is never a final Present decision. Retain a
            // legacy fallback only when an old ENUM cannot store INCOMPLETE.
            $status = biometric_attendance_pending_storage_status(
                $pdo,
                (string) ($metrics['status'] ?? 'Present')
            );
            $note = attendance_note($deviceId, $commandId);

            if ($record) {
                if (attendance_has_metric_columns($pdo)) {
                    if (attendance_has_schedule_snapshot_columns($pdo)) {
                        $stmt = $pdo->prepare('UPDATE attendance SET time_in = ?, status = ?, source = "ESP32 Fingerprint", notes = ?, expected_time_in = ?, expected_time_out = ?, late_minutes = ?, schedule_type = ?, schedule_source = ? WHERE id = ?');
                        $stmt->execute([$now, $status, $note, $expectedIn, $expectedOut, $lateMinutes, $schedule['schedule_type'], $schedule['schedule_source'], (int) $record['id']]);
                    } else {
                        $stmt = $pdo->prepare('UPDATE attendance SET time_in = ?, status = ?, source = "ESP32 Fingerprint", notes = ?, expected_time_in = ?, expected_time_out = ?, late_minutes = ? WHERE id = ?');
                        $stmt->execute([$now, $status, $note, $expectedIn, $expectedOut, $lateMinutes, (int) $record['id']]);
                    }
                } else {
                    $stmt = $pdo->prepare('UPDATE attendance SET time_in = ?, status = ?, source = "ESP32 Fingerprint", notes = ? WHERE id = ?');
                    $stmt->execute([$now, $status, $note, (int) $record['id']]);
                }
                $attendanceId = (int) $record['id'];
            } else {
                if (attendance_has_metric_columns($pdo)) {
                    if (attendance_has_schedule_snapshot_columns($pdo)) {
                        $stmt = $pdo->prepare('INSERT INTO attendance (employee_id, scan_date, time_in, status, source, notes, expected_time_in, expected_time_out, late_minutes, schedule_type, schedule_source) VALUES (?, ?, ?, ?, "ESP32 Fingerprint", ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$employeeId, $today, $now, $status, $note, $expectedIn, $expectedOut, $lateMinutes, $schedule['schedule_type'], $schedule['schedule_source']]);
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO attendance (employee_id, scan_date, time_in, status, source, notes, expected_time_in, expected_time_out, late_minutes) VALUES (?, ?, ?, ?, "ESP32 Fingerprint", ?, ?, ?, ?)');
                        $stmt->execute([$employeeId, $today, $now, $status, $note, $expectedIn, $expectedOut, $lateMinutes]);
                    }
                } else {
                    $stmt = $pdo->prepare('INSERT INTO attendance (employee_id, scan_date, time_in, status, source, notes) VALUES (?, ?, ?, ?, "ESP32 Fingerprint", ?)');
                    $stmt->execute([$employeeId, $today, $now, $status, $note]);
                }
                $attendanceId = (int) $pdo->lastInsertId();
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'employeeName' => $employeeName,
                'employeeNo' => $employeeNo,
                'action' => 'TIME_IN',
                'message' => 'Time-in recorded at ' . $moment->format('g:i A') . '.',
                'attendanceId' => $attendanceId,
                'status' => $status,
                'lateMinutes' => $lateMinutes,
                'late' => $lateMinutes > 0,
                'undertime' => false,
                'overtimePayable' => false,
            ] + biometric_attendance_context($schedule, $today, 'PENDING_TIME_OUT', 'OUT');
        }

        if (!$record || empty($record['time_in'])) {
            throw new BiometricAttendanceException('Time-in must be recorded before time-out.', 409);
        }

        if (!empty($record['time_out'])) {
            $scanDate = (string) $record['scan_date'];
            $schedule = attendance_schedule_for_record($pdo, $record, $employeeId, $scanDate);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'employeeName' => $employeeName,
                'employeeNo' => $employeeNo,
                'action' => 'ALREADY_OUT',
                'message' => 'Time-out already recorded at ' . date('g:i A', strtotime((string) $record['time_out'])) . '.',
                'attendanceId' => (int) $record['id'],
                'status' => (string) ($record['status'] ?? ''),
            ] + biometric_attendance_context($schedule, $scanDate, 'COMPLETE', 'NONE');
        }

        $scanDate = (string) $record['scan_date'];
        $schedule = attendance_schedule_for_record($pdo, $record, $employeeId, $scanDate);
        $expectedIn = $schedule['shift_start'];
        $expectedOut = $schedule['shift_end'];
        $metrics = attendance_calculate_metrics(
            $scanDate,
            (string) $record['time_in'],
            $moment->format('H:i:s'),
            $expectedIn,
            $expectedOut,
            attendance_grace_minutes($pdo),
            $schedule['schedule_type'],
            (int) $schedule['break_minutes'],
            $schedule['periods'],
            attendance_half_day_minimum_percent($pdo),
            attendance_full_day_minimum_percent($pdo)
        );
        $timeOutAt = $metrics['actual_out_at'] instanceof DateTimeImmutable
            ? $metrics['actual_out_at']
            : $moment;
        $workedMinutes = (int) $metrics['worked_minutes'];
        $lateMinutes = (int) $metrics['late_minutes'];
        $overtimeMinutes = (int) $metrics['overtime_minutes'];
        $status = (string) ($metrics['classification_status'] ?? $metrics['status'] ?? $record['status']);
        $note = attendance_note($deviceId, $commandId);

        if (attendance_has_metric_columns($pdo)) {
            if (attendance_has_schedule_snapshot_columns($pdo)) {
                $stmt = $pdo->prepare('UPDATE attendance SET time_out = ?, source = "ESP32 Fingerprint", notes = ?, expected_time_in = ?, expected_time_out = ?, worked_minutes = ?, late_minutes = ?, overtime_minutes = ?, status = ?, schedule_type = ?, schedule_source = ? WHERE id = ?');
                $stmt->execute([$timeOutAt->format('H:i:s'), $note, $expectedIn, $expectedOut, $workedMinutes, $lateMinutes, $overtimeMinutes, $status, $schedule['schedule_type'], $schedule['schedule_source'], (int) $record['id']]);
            } else {
                $stmt = $pdo->prepare('UPDATE attendance SET time_out = ?, source = "ESP32 Fingerprint", notes = ?, expected_time_in = ?, expected_time_out = ?, worked_minutes = ?, late_minutes = ?, overtime_minutes = ?, status = ? WHERE id = ?');
                $stmt->execute([$timeOutAt->format('H:i:s'), $note, $expectedIn, $expectedOut, $workedMinutes, $lateMinutes, $overtimeMinutes, $status, (int) $record['id']]);
            }
        } else {
            $stmt = $pdo->prepare('UPDATE attendance SET time_out = ?, source = "ESP32 Fingerprint", notes = ? WHERE id = ?');
            $stmt->execute([$timeOutAt->format('H:i:s'), $note, (int) $record['id']]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return [
            'employeeName' => $employeeName,
            'employeeNo' => $employeeNo,
            'action' => 'TIME_OUT',
            'message' => 'Time-out recorded at ' . $moment->format('g:i A') . '.',
            'attendanceId' => (int) $record['id'],
            'workedMinutes' => $workedMinutes,
            'lateMinutes' => $lateMinutes,
            // Legacy installations cannot synchronize approvals reliably;
            // never tell the terminal that potential overtime is payable.
            'approvedOvertimeMinutes' => 0,
            'late' => $lateMinutes > 0,
            'undertime' => (int) ($metrics['undertime_minutes'] ?? 0) > 0,
            'overtimePayable' => false,
        ] + biometric_attendance_context($schedule, $scanDate, 'COMPLETE', 'NONE');
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
