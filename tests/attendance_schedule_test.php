<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../api/device/attendance_service.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

$pdo->beginTransaction();

try {
    // Normalize defaults inside this rollback-only test transaction.
    $saveDefault = $pdo->prepare(
        'INSERT INTO default_work_schedules (day_of_week, schedule_type, shift_start, shift_end, break_minutes)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE schedule_type=VALUES(schedule_type), shift_start=VALUES(shift_start),
             shift_end=VALUES(shift_end), break_minutes=VALUES(break_minutes)'
    );
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
        $saveDefault->execute([$day, 'Work', '08:00:00', '17:00:00', 60]);
    }
    $saveDefault->execute(['Sunday', 'Off', null, null, 0]);
    $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES ("grace_minutes","15") ON DUPLICATE KEY UPDATE `value`="15"')->execute();
    assert_same(15, attendance_grace_minutes($pdo), 'Regression test must start with an explicit 15-minute grace');

    $employeeNo = 'TEST-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO employees
            (employee_no, first_name, last_name, position, employment_type, status, fingerprint_status)
         VALUES (?, "Schedule", "Regression", "Tester", "Full-Time", "Active", "Enrolled")'
    );
    $stmt->execute([$employeeNo]);
    $employeeId = (int) $pdo->lastInsertId();
    $employee = [
        'id' => $employeeId,
        'employee_no' => $employeeNo,
        'first_name' => 'Schedule',
        'last_name' => 'Regression',
        'employment_type' => 'Full-Time',
    ];

    // Website attendance must finalize its command in the same transaction as
    // the punch/projection, and retain enough result data for a lost-response
    // retry to return the original action without creating another punch.
    $commandId = bin2hex(random_bytes(32));
    $deviceId = 'atomic-test-' . bin2hex(random_bytes(4));
    $pdo->prepare(
        'INSERT INTO device_commands
            (command_uuid,command_type,employee_id,fingerprint_slot,event_type,
             device_id,status,requested_at)
         VALUES (?,"VERIFY_ATTENDANCE",?,127,"IN",?,"Running",NOW())'
    )->execute([$commandId, $employeeId, $deviceId]);
    $commandStmt = $pdo->prepare('SELECT * FROM device_commands WHERE command_uuid=? FOR UPDATE');
    $commandStmt->execute([$commandId]);
    $command = $commandStmt->fetch();
    biometric_attendance_complete_web_command($pdo, $command, $deviceId, [
        'action' => 'ALREADY_IN',
        'message' => 'Morning time-in is already saved.',
    ]);
    $completed = $pdo->query(
        'SELECT status,result_message FROM device_commands WHERE id=' . (int) $command['id']
    )->fetch();
    assert_same('Done', $completed['status'], 'Attendance command must become Done inside the attendance transaction');
    $storedResult = biometric_attendance_unpack_command_result((string) $completed['result_message']);
    assert_same('ALREADY_IN', $storedResult['action'], 'Lost-response retry must retain the original attendance action');
    assert_same('Morning time-in is already saved.', $storedResult['message'], 'Lost-response retry must retain a safe user message');

    // Upgrade reconciliation repairs only VERIFY_ATTENDANCE commands that
    // already own an accepted append-only punch.
    $legacyCommandId = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO device_commands
            (command_uuid,command_type,employee_id,fingerprint_slot,event_type,
             device_id,status,result_message,requested_at,completed_at)
         VALUES (?,"VERIFY_ATTENDANCE",?,127,"IN",?,"Failed",
                 "Command result was not synchronized",NOW(),NOW())'
    )->execute([$legacyCommandId, $employeeId, $deviceId]);
    $pdo->prepare(
        'INSERT INTO attendance_logs
            (employee_id,fingerprint_id,attendance_date,action,session_index,
             punch_sequence,scanned_at,device_id,command_uuid,source,event_key)
         VALUES (?,NULL,"2026-01-02","TIME_IN",1,1,"2026-01-02 08:00:00",
                 ?,?,"ESP32 Fingerprint",?)'
    )->execute([$employeeId, $deviceId, $legacyCommandId, hash('sha256', $legacyCommandId)]);
    assert_same(1, biometric_attendance_reconcile_saved_commands($pdo), 'One saved legacy punch must repair its command state');
    $recovered = $pdo->query(
        'SELECT status,result_message FROM device_commands WHERE command_uuid=' . $pdo->quote($legacyCommandId)
    )->fetch();
    assert_same('Done', $recovered['status'], 'A command with a committed punch cannot remain Failed');
    assert_same(
        'TIME_IN',
        biometric_attendance_unpack_command_result((string) $recovered['result_message'])['action'],
        'Reconciliation must preserve the committed punch action'
    );

    $schedule = $pdo->prepare(
        'INSERT INTO work_schedules
            (employee_id, day_of_week, schedule_type, shift_start, shift_end, break_minutes)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $schedule->execute([$employeeId, 'Monday', 'Work', '09:00:00', '18:00:00', 60]);
    $schedule->execute([$employeeId, 'Wednesday', 'Work', '22:00:00', '06:00:00', 0]);
    $schedule->execute([$employeeId, 'Thursday', 'Off', null, null, 0]);

    // Employee override wins over the normalized weekday default.
    $resolved = attendance_schedule($pdo, $employeeId, '2026-08-24');
    assert_same('Employee', $resolved['schedule_source'], 'Employee weekday override must have first precedence');
    assert_same('09:00:00', $resolved['shift_start'], 'Employee override start should replace the default start');

    // No employee row on Tuesday: automatic 08:00-17:00 default snapshot.
    $in = record_biometric_attendance($pdo, $employee, 'IN', 'test-device', null, new DateTimeImmutable('2026-08-25 08:20:00'));
    assert_same('INCOMPLETE', $in['status'], 'A Time In alone must remain incomplete until a valid Time Out arrives');
    assert_same(5, $in['lateMinutes'], 'Default shift lateness should count only whole minutes beyond grace');
    assert_same('Default', $in['scheduleSource'], 'Time In should snapshot the default source');

    $morningOut = record_biometric_attendance($pdo, $employee, 'OUT', 'test-device', null, new DateTimeImmutable('2026-08-25 12:00:00'));
    assert_same('INCOMPLETE', $morningOut['status'], 'Morning Time Out must remain incomplete until the afternoon session is punched');
    assert_same('BETWEEN_SESSIONS', $morningOut['attendanceState'], 'Lunch checkout must advance to the between-sessions state');
    assert_same('IN', $morningOut['nextAction'], 'Lunch checkout must require the afternoon Time In next');

    $afternoonIn = record_biometric_attendance($pdo, $employee, 'IN', 'test-device', null, new DateTimeImmutable('2026-08-25 13:00:00'));
    assert_same('INCOMPLETE', $afternoonIn['status'], 'Afternoon Time In must remain incomplete until final Time Out');
    assert_same('WAITING_FOR_SESSION_OUT', $afternoonIn['attendanceState'], 'Afternoon Time In must advance to its Time Out state');

    $out = record_biometric_attendance($pdo, $employee, 'OUT', 'test-device', null, new DateTimeImmutable('2026-08-25 17:30:00'));
    assert_same('PRESENT', $out['status'], 'All four scheduled punches must complete a Present full-time day');
    assert_same('COMPLETE', $out['attendanceState'], 'The final afternoon Time Out must complete the device state');
    assert_same('NONE', $out['nextAction'], 'A completed four-punch day must have no next action');
    assert_same(490, $out['workedMinutes'], 'Worked minutes should total both recorded sessions');
    assert_same(0, $out['approvedOvertimeMinutes'], 'Late departure must not become payable without an approved manual request');
    $row = $pdo->query('SELECT * FROM attendance WHERE employee_id=' . $employeeId . ' AND scan_date="2026-08-25"')->fetch();
    assert_same(30, (int) $row['potential_overtime_minutes'], 'Attendance may retain potential minutes for manual OT validation');
    assert_same(0, (int) $row['approved_overtime_minutes'], 'Unrequested potential OT must not become approved attendance');
    assert_same('08:00:00', $row['expected_time_in'], 'Default Expected In must be snapshotted');
    assert_same('17:00:00', $row['expected_time_out'], 'Default Expected Out must be snapshotted');
    assert_same('Work', $row['schedule_type'], 'Default workday type must be snapshotted');
    assert_same('Default', $row['schedule_source'], 'Default schedule source must be snapshotted');

    // Autonomous terminal mode asks the locked API service for the next valid
    // action. Full-Time attendance requires independent morning and afternoon
    // IN/OUT pairs, and remains idempotent after all four punches are complete.
    $autoIn = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-18 08:00:00')
    );
    assert_same('TIME_IN', $autoIn['action'], 'First autonomous scan must record Time In');
    assert_same('WAITING_FOR_SESSION_OUT', $autoIn['attendanceState'], 'Morning Time In must wait for the scheduled morning Time Out');
    assert_same('OUT', $autoIn['nextAction'], 'The server must report OUT as the eventual next action');

    $autoRepeat = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-18 08:05:00')
    );
    assert_same('ALREADY_IN', $autoRepeat['action'], 'Early repeat scan must not create duplicate Time In or an accidental Time Out');

    $autoHalfDayOut = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-18 12:00:00')
    );
    assert_same('TIME_OUT', $autoHalfDayOut['action'], 'Morning half-day boundary must permit autonomous Time Out');
    assert_same('INCOMPLETE', $autoHalfDayOut['status'], 'Morning completion must stay incomplete while the afternoon session is still open');
    assert_same('BETWEEN_SESSIONS', $autoHalfDayOut['attendanceState'], 'Morning Time Out must advance to the lunch gap');
    assert_same('IN', $autoHalfDayOut['nextAction'], 'The next required punch after morning Time Out must be afternoon Time In');
    assert_same(false, $autoHalfDayOut['overtimePayable'], 'Potential time never becomes payable without an approved request');

    $autoLunchRepeat = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-18 12:05:00')
    );
    assert_same('NOT_YET_ELIGIBLE', $autoLunchRepeat['action'], 'A lunch-gap repeat must not create the afternoon Time In early');
    assert_same('BETWEEN_SESSIONS', $autoLunchRepeat['attendanceState'], 'A lunch-gap repeat must retain the between-sessions state');

    $autoAfternoonIn = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-18 13:00:00')
    );
    assert_same('TIME_IN', $autoAfternoonIn['action'], 'The next eligible scan must record afternoon Time In');
    assert_same('WAITING_FOR_SESSION_OUT', $autoAfternoonIn['attendanceState'], 'Afternoon Time In must wait for the final Time Out');
    assert_same('INCOMPLETE', $autoAfternoonIn['status'], 'Three punches cannot complete a full-time workday');

    $autoFinalOut = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-18 17:00:00')
    );
    assert_same('TIME_OUT', $autoFinalOut['action'], 'The fourth scan must record afternoon Time Out');
    assert_same('PRESENT', $autoFinalOut['status'], 'Four on-schedule punches must classify the employee as Present');
    assert_same('COMPLETE', $autoFinalOut['attendanceState'], 'Four on-schedule punches must complete the day');
    assert_same('NONE', $autoFinalOut['nextAction'], 'A complete day must have no remaining punch');
    assert_same(4, $autoFinalOut['completedPunches'], 'Full-Time attendance must report all four completed punches');

    $autoCompleteRepeat = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-18 17:05:00')
    );
    assert_same('ALREADY_OUT', $autoCompleteRepeat['action'], 'A completed day must reject duplicate Time Out');
    assert_same('COMPLETE', $autoCompleteRepeat['attendanceState'], 'Completed device state must be explicit');
    assert_same('NONE', $autoCompleteRepeat['nextAction'], 'Completed attendance must have no next punch');

    // Once all earlier periods are missed, a first 14:00 arrival is already
    // too late to qualify for the configured afternoon half-day.
    $lateFinalIn = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-21 14:00:00')
    );
    assert_same('TIME_IN', $lateFinalIn['action'], 'A late arrival is retained as an auditable biometric punch');
    assert_same(2, $lateFinalIn['sessionIndex'], 'A 14:00 first arrival must map to Afternoon, never Morning');
    assert_same('ABSENT', $lateFinalIn['status'], 'A 14:00 first arrival must be classified Absent immediately');
    $lateFinalOut = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-21 17:00:00')
    );
    assert_same('ABSENT', $lateFinalOut['status'], 'A late 14:00-17:00 pair must remain Absent after Time Out');

    // A first scan after a working schedule has closed is rejected as a punch,
    // while the processor immediately creates the configured Absent projection.
    $afterEnd = record_biometric_attendance(
        $pdo,
        $employee,
        'AUTO',
        'test-device',
        null,
        new DateTimeImmutable('2026-08-22 18:00:00')
    );
    assert_same('DAY_CLOSED', $afterEnd['action'], 'A first scan after Expected Out must not create a biometric punch');
    assert_same('ABSENT', $afterEnd['status'], 'A first scan after Expected Out must immediately apply Absent');
    assert_same('DAY_CLOSED', $afterEnd['attendanceState'], 'The device must explicitly report that the assigned workday is closed');
    $afterEndPunches = $pdo->query(
        'SELECT COUNT(*) FROM attendance_logs WHERE employee_id=' . $employeeId . ' AND attendance_date="2026-08-22"'
    )->fetchColumn();
    assert_same(0, (int) $afterEndPunches, 'A DAY_CLOSED response must not append an attendance punch');

    // Direct session-metric regressions protect the Full-Time policy: each
    // 08:00-12:00 / 13:00-17:00 session needs its own IN/OUT pair.
    $fullTimeSchedule = [
        'schedule_type' => ATTENDANCE_SCHEDULE_WORK,
        'shift_start' => '08:00:00',
        'shift_end' => '17:00:00',
        'break_minutes' => 60,
        'periods' => [],
    ];
    $fourPunchDay = attendance_calculate_session_metrics(
        '2026-08-25',
        [
            [
                'session_index' => 1,
                'in_at' => new DateTimeImmutable('2026-08-25 08:00:00'),
                'out_at' => new DateTimeImmutable('2026-08-25 12:00:00'),
            ],
            [
                'session_index' => 2,
                'in_at' => new DateTimeImmutable('2026-08-25 13:00:00'),
                'out_at' => new DateTimeImmutable('2026-08-25 17:00:00'),
            ],
        ],
        $fullTimeSchedule,
        'Full-Time',
        15
    );
    assert_same('PRESENT', $fourPunchDay['classification_status'], 'Four scheduled Full-Time punches must classify Present');
    assert_same(2, $fourPunchDay['required_session_count'], 'An 08:00-17:00 Full-Time day must derive two required sessions');
    assert_same(2, $fourPunchDay['completed_session_count'], 'Both Full-Time sessions must be complete');
    assert_same(480, $fourPunchDay['regular_minutes'], 'Four-punch attendance must cover all eight scheduled hours');

    $morningHalf = attendance_calculate_session_metrics(
        '2026-08-25',
        [[
            'session_index' => 1,
            'in_at' => new DateTimeImmutable('2026-08-25 08:00:00'),
            'out_at' => new DateTimeImmutable('2026-08-25 12:00:00'),
        ]],
        $fullTimeSchedule,
        'Full-Time',
        15
    );
    assert_same('HALF_DAY', $morningHalf['classification_status'], '08:00-12:00 must qualify as the derived morning half-day');
    assert_same('MORNING', $morningHalf['half_day_session'], 'Morning half-day session must be identified');
    assert_same(240, $morningHalf['regular_minutes'], 'Morning half-day must preserve four eligible hours');

    $afternoonHalf = attendance_calculate_session_metrics(
        '2026-08-25',
        [[
            'session_index' => 2,
            'in_at' => new DateTimeImmutable('2026-08-25 13:00:00'),
            'out_at' => new DateTimeImmutable('2026-08-25 17:00:00'),
        ]],
        $fullTimeSchedule,
        'Full-Time',
        15
    );
    assert_same('HALF_DAY', $afternoonHalf['classification_status'], '13:00-17:00 must qualify as the derived afternoon half-day');
    assert_same('AFTERNOON', $afternoonHalf['half_day_session'], 'Afternoon half-day session must be identified');
    assert_same(0, $afternoonHalf['late_minutes'], 'On-time afternoon attendance must not inherit morning lateness');

    $tooLate = attendance_calculate_session_metrics(
        '2026-08-25',
        [[
            'session_index' => 2,
            'in_at' => new DateTimeImmutable('2026-08-25 14:00:00'),
            'out_at' => new DateTimeImmutable('2026-08-25 17:00:00'),
        ]],
        $fullTimeSchedule,
        'Full-Time',
        15
    );
    assert_same(180, $tooLate['regular_minutes'], 'A 14:00 arrival must have only three eligible hours');
    assert_same('ABSENT', $tooLate['classification_status'], 'A first arrival at 14:00 must be classified Absent');

    $longSinglePair = attendance_calculate_session_metrics(
        '2026-08-25',
        [[
            'session_index' => 1,
            'in_at' => new DateTimeImmutable('2026-08-25 10:23:00'),
            'out_at' => new DateTimeImmutable('2026-08-25 20:59:00'),
        ]],
        $fullTimeSchedule,
        'Full-Time',
        15
    );
    assert_same('ABSENT', $longSinglePair['classification_status'], 'A 10:23-20:59 single pair must not imitate both required Full-Time sessions');
    assert_same(1, $longSinglePair['completed_session_count'], 'One long pair may complete only one assigned session');

    // The grace boundary uses whole minutes consistently for both the stored
    // duration and status: 08:15:59 is still on time, 08:16:00 is one minute late.
    $graceBoundary = attendance_calculate_metrics(
        '2026-08-25',
        '08:15:59',
        null,
        '08:00:00',
        '17:00:00',
        15
    );
    assert_same(0, $graceBoundary['late_minutes'], 'Seconds within the final grace minute must not become late');
    assert_same('Present', $graceBoundary['status'], '08:15:59 must remain Present with 15-minute grace');
    $firstLateMinute = attendance_calculate_metrics(
        '2026-08-25',
        '08:16:00',
        null,
        '08:00:00',
        '17:00:00',
        15
    );
    assert_same(1, $firstLateMinute['late_minutes'], 'The first whole minute after grace must record one late minute');
    assert_same('Late', $firstLateMinute['status'], '08:16:00 must be Late with 15-minute grace');

    $atExpectedOut = attendance_calculate_metrics(
        '2026-08-25',
        '08:00:00',
        '17:00:00',
        '08:00:00',
        '17:00:00',
        15
    );
    assert_same(0, $atExpectedOut['overtime_minutes'], 'Time Out exactly at Expected Out must not earn overtime');
    $firstOvertimeMinute = attendance_calculate_metrics(
        '2026-08-25',
        '08:00:00',
        '17:01:00',
        '08:00:00',
        '17:00:00',
        15
    );
    assert_same(1, $firstOvertimeMinute['overtime_minutes'], 'The first whole minute after Expected Out must record one overtime minute');

    // Explicit employee Off suppresses an otherwise working Thursday default.
    $offIn = record_biometric_attendance($pdo, $employee, 'IN', 'test-device', null, new DateTimeImmutable('2026-09-03 12:00:00'));
    assert_same('Off', $offIn['scheduleType'], 'Explicit Off must override the working default');
    assert_same(0, $offIn['lateMinutes'], 'Off-day attendance cannot be late');
    $offOut = record_biometric_attendance($pdo, $employee, 'OUT', 'test-device', null, new DateTimeImmutable('2026-09-03 13:00:00'));
    assert_same(60, $offOut['workedMinutes'], 'Off-day scans should still calculate actual worked time');
    assert_same('REST_DAY_WORK', $offOut['status'], 'A completed current-day rest-day pair must finalize immediately');
    $row = $pdo->query('SELECT * FROM attendance WHERE employee_id=' . $employeeId . ' AND scan_date="2026-09-03"')->fetch();
    assert_same(0, (int) $row['potential_overtime_minutes'], 'Off-day work is not potential ordinary overtime');
    assert_same(0, (int) $row['approved_overtime_minutes'], 'Off-day work cannot become approved ordinary overtime automatically');
    assert_same(null, $row['expected_time_in'], 'Explicit Off must snapshot null Expected In');
    assert_same('Employee', $row['schedule_source'], 'Explicit Off should retain Employee source');

    // Sunday is a normalized default Off day.
    $sunday = attendance_schedule($pdo, $employeeId, '2026-08-30');
    assert_same('Off', $sunday['schedule_type'], 'Sunday default should be Off');
    assert_same('Default', $sunday['schedule_source'], 'Sunday Off should come from the default table');

    // Overnight override snapshots the start date and accepts next-day OUT.
    record_biometric_attendance($pdo, $employee, 'IN', 'test-device', null, new DateTimeImmutable('2026-08-26 22:10:00'));
    $overnight = record_biometric_attendance($pdo, $employee, 'OUT', 'test-device', null, new DateTimeImmutable('2026-08-27 06:30:00'));
    assert_same(500, $overnight['workedMinutes'], 'Overnight worked minutes should cross midnight');
    assert_same(0, $overnight['approvedOvertimeMinutes'], 'Overnight potential OT must remain unapproved at the terminal');
    $overnightRow = $pdo->query('SELECT * FROM attendance WHERE employee_id=' . $employeeId . ' AND scan_date="2026-08-26"')->fetch();
    assert_same(30, (int) $overnightRow['potential_overtime_minutes'], 'Overnight potential OT should use next-day Expected Out');
    assert_same(0, (int) $overnightRow['approved_overtime_minutes'], 'Overnight potential OT must require a manual approved request');

    // A frozen Time In snapshot survives a later override edit before Time Out.
    record_biometric_attendance($pdo, $employee, 'IN', 'test-device', null, new DateTimeImmutable('2026-08-31 09:05:00'));
    $pdo->prepare('UPDATE work_schedules SET shift_start="10:00:00", shift_end="19:00:00" WHERE employee_id=? AND day_of_week="Monday"')
        ->execute([$employeeId]);
    record_biometric_attendance($pdo, $employee, 'OUT', 'test-device', null, new DateTimeImmutable('2026-08-31 13:00:00'));
    record_biometric_attendance($pdo, $employee, 'IN', 'test-device', null, new DateTimeImmutable('2026-08-31 14:00:00'));
    $frozenOut = record_biometric_attendance($pdo, $employee, 'OUT', 'test-device', null, new DateTimeImmutable('2026-08-31 18:30:00'));
    assert_same('PRESENT', $frozenOut['status'], 'The frozen four-punch schedule must complete as Present');
    assert_same(0, $frozenOut['approvedOvertimeMinutes'], 'Frozen-schedule potential OT must remain unapproved at the terminal');
    $row = $pdo->query('SELECT * FROM attendance WHERE employee_id=' . $employeeId . ' AND scan_date="2026-08-31"')->fetch();
    assert_same('09:00:00', $row['expected_time_in'], 'Later override edits must not change the Time In snapshot');
    assert_same('18:00:00', $row['expected_time_out'], 'Frozen Expected Out must survive an edit');
    assert_same(30, (int) $row['potential_overtime_minutes'], 'Potential OT must use the frozen 18:00 expected end');

    // Existing completed snapshots stay immutable while truly missing legacy
    // rows resolve exactly once and are marked as backfills.
    $pdo->prepare(
        'INSERT INTO attendance
            (employee_id, scan_date, time_in, time_out, expected_time_in, expected_time_out,
             schedule_type, schedule_source, worked_minutes, status, source)
         VALUES (?, "2026-08-17", "07:00:00", "16:00:00", "07:00:00", "16:00:00",
                 "Work", "Existing Snapshot", 540, "Present", "ESP32 Fingerprint")'
    )->execute([$employeeId]);
    $pdo->prepare(
        'INSERT INTO attendance (employee_id, scan_date, time_in, time_out, status, source)
         VALUES (?, "2026-08-10", "10:30:00", "20:00:00", "Present", "ESP32 Fingerprint")'
    )->execute([$employeeId]);

    $backfilled = attendance_backfill_missing_schedule_snapshots($pdo, $employeeId);
    assert_same(1, $backfilled, 'Only the row with a null schedule snapshot should be backfilled');
    $existing = $pdo->query('SELECT * FROM attendance WHERE employee_id=' . $employeeId . ' AND scan_date="2026-08-17"')->fetch();
    assert_same('07:00:00', $existing['expected_time_in'], 'Completed existing snapshots must remain unchanged');
    $legacy = $pdo->query('SELECT * FROM attendance WHERE employee_id=' . $employeeId . ' AND scan_date="2026-08-10"')->fetch();
    assert_same('10:00:00', $legacy['expected_time_in'], 'Missing legacy row should resolve the current employee override once');
    assert_same('Backfill Employee', $legacy['schedule_source'], 'Historical inferred override must be labeled as a backfill');
    assert_same(60, (int) $legacy['overtime_minutes'], 'Backfilled overtime should use overlap after expected end');

    // Completed one-pair records from the former terminal model keep their
    // historical whole-day meaning when version 5 refreshes payroll inputs.
    $pdo->prepare(
        'INSERT INTO attendance
            (employee_id, scan_date, time_in, time_out, expected_time_in, expected_time_out,
             schedule_type, schedule_source, break_minutes, employment_type_snapshot,
             legacy_single_pair, status, source, processed_at, processing_version)
         VALUES (?, "2026-08-11", "08:00:00", "17:00:00", "08:00:00", "17:00:00",
                 "Work", "Default", 60, "Full-Time", 1, "PRESENT", "ESP32 Fingerprint", NOW(), 4)'
    )->execute([$employeeId]);
    attendance_append_accepted_log(
        $pdo,
        $employeeId,
        null,
        '2026-08-11',
        'TIME_IN',
        new DateTimeImmutable('2026-08-11 08:00:00'),
        'pre-session-terminal'
    );
    attendance_append_accepted_log(
        $pdo,
        $employeeId,
        null,
        '2026-08-11',
        'TIME_OUT',
        new DateTimeImmutable('2026-08-11 17:00:00'),
        'pre-session-terminal'
    );
    $grandfathered = attendance_process_employee_date(
        $pdo,
        $employeeId,
        '2026-08-11',
        new DateTimeImmutable('2026-08-12 00:00:00')
    );
    assert_same('PRESENT', $grandfathered['status'], 'An old 08:00-17:00 envelope must not become Half-Day after migration');
    assert_same(1, (int) $grandfathered['legacy_single_pair'], 'The legacy envelope marker must survive safe reprocessing');

    // A configured zero-minute grace must remain zero (not silently become 15).
    $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES ("grace_minutes","0") ON DUPLICATE KEY UPDATE `value`="0"')->execute();
    assert_same(0, attendance_grace_minutes($pdo), 'A configured zero-minute grace is valid');
    $zeroGrace = record_biometric_attendance($pdo, $employee, 'IN', 'test-device', null, new DateTimeImmutable('2026-08-28 08:01:00'));
    assert_same(1, $zeroGrace['lateMinutes'], 'Zero grace must count the first whole minute after Expected In');
    assert_same('INCOMPLETE', $zeroGrace['status'], 'A lone Time In remains incomplete even when its provisional late minutes are known');

    attendance_sync_current_schedule(
        $pdo,
        $employeeId,
        'Friday',
        '08:30:00',
        '17:30:00',
        ATTENDANCE_SCHEDULE_WORK,
        60
    );
    $openAfterScheduleEdit = $pdo->query(
        'SELECT status FROM attendance WHERE employee_id=' . $employeeId . ' AND scan_date="2026-08-28"'
    )->fetch();
    assert_same(
        'INCOMPLETE',
        $openAfterScheduleEdit['status'],
        'Schedule edits must preserve the integrated INCOMPLETE classification for a Time In-only row'
    );

    // The user-facing weekly assignment saves every day in one operation, so
    // unchecked days remain explicit Rest Days instead of silently inheriting
    // the organization fallback.
    $pdo->prepare(
        'UPDATE employees SET employment_type="Part-Time", pay_type="Hourly", basic_rate=100 WHERE id=?'
    )->execute([$employeeId]);
    $employee['employment_type'] = 'Part-Time';
    $weeklyAssignment = [];
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
        $isWork = in_array($day, ['Monday', 'Wednesday', 'Friday'], true);
        $periods = match ($day) {
            'Monday' => [
                ['period_start' => '09:00', 'period_end' => '12:00'],
                ['period_start' => '13:00', 'period_end' => '14:00'],
            ],
            'Wednesday' => [
                ['period_start' => '08:00', 'period_end' => '10:00'],
                ['period_start' => '13:00', 'period_end' => '15:00'],
            ],
            'Friday' => [
                ['period_start' => '10:00', 'period_end' => '12:00'],
                ['period_start' => '14:00', 'period_end' => '16:00'],
            ],
            default => [],
        };
        $weeklyAssignment[$day] = [
            'schedule_type' => $isWork ? 'Work' : 'Off',
            'shift_start' => null,
            'shift_end' => null,
            'break_minutes' => 0,
            'periods' => $periods,
        ];
    }
    attendance_save_weekly_schedule($pdo, $employeeId, $weeklyAssignment);
    $savedDayCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM work_schedules WHERE employee_id=' . $employeeId
    )->fetchColumn();
    assert_same(7, $savedDayCount, 'A weekly assignment must persist all seven days');
    $savedMonday = attendance_schedule($pdo, $employeeId, '2026-09-07');
    assert_same('Employee', $savedMonday['schedule_source'], 'Saved weekly assignment must take precedence for attendance');
    assert_same('09:00:00', $savedMonday['shift_start'], 'Flexible Monday Expected In must resolve in attendance');
    assert_same('14:00:00', $savedMonday['shift_end'], 'Flexible Monday Expected Out must use the final period');
    assert_same(60, $savedMonday['break_minutes'], 'The gap between Monday periods must remain unpaid');
    assert_same(2, count($savedMonday['periods']), 'Monday must preserve both flexible time periods');
    assert_same(240, (int) $savedMonday['scheduled_minutes'], 'Monday periods must total four required hours');
    $savedTuesday = attendance_schedule($pdo, $employeeId, '2026-09-08');
    assert_same('Off', $savedTuesday['schedule_type'], 'Unchecked Tuesday must be an explicit Rest Day');
    $fullFlexibleDay = attendance_calculate_session_metrics(
        '2026-09-07',
        [
            [
                'session_index' => 1,
                'in_at' => new DateTimeImmutable('2026-09-07 09:00:00'),
                'out_at' => new DateTimeImmutable('2026-09-07 12:00:00'),
            ],
            [
                'session_index' => 2,
                'in_at' => new DateTimeImmutable('2026-09-07 13:00:00'),
                'out_at' => new DateTimeImmutable('2026-09-07 14:00:00'),
            ],
        ],
        $savedMonday,
        'Part-Time',
        15
    );
    assert_same('PRESENT', $fullFlexibleDay['classification_status'], 'Completing all flexible Part-Time pairs must classify Present');
    assert_same(240, $fullFlexibleDay['worked_minutes'], 'The gap between flexible Part-Time pairs must not count as worked time');
    assert_same(240, $fullFlexibleDay['regular_minutes'], 'Only four assigned period hours must be payroll-eligible');
    assert_same(0, $fullFlexibleDay['late_minutes'], 'On-time split-period attendance must not be late');
    $afternoonOnly = attendance_calculate_session_metrics(
        '2026-09-07',
        [[
            'session_index' => 2,
            'in_at' => new DateTimeImmutable('2026-09-07 13:00:00'),
            'out_at' => new DateTimeImmutable('2026-09-07 14:00:00'),
        ]],
        $savedMonday,
        'Part-Time',
        15
    );
    assert_same(60, $afternoonOnly['regular_minutes'], 'Afternoon-only presence must earn only its overlapping hour');
    assert_same(0, $afternoonOnly['late_minutes'], 'On-time flexible afternoon attendance must not inherit the first period as lateness');
    assert_same('ABSENT', $afternoonOnly['classification_status'], 'Working below the half-day threshold must classify as Absent');
    $halfFlexibleDay = attendance_calculate_session_metrics(
        '2026-09-07',
        [[
            'session_index' => 1,
            'in_at' => new DateTimeImmutable('2026-09-07 09:00:00'),
            'out_at' => new DateTimeImmutable('2026-09-07 11:00:00'),
        ]],
        $savedMonday,
        'Part-Time',
        15,
        50,
        75
    );
    assert_same(120, $halfFlexibleDay['regular_minutes'], 'Half-day fixture must overlap exactly half the assigned periods');
    assert_same('HALF_DAY', $halfFlexibleDay['classification_status'], 'Coverage between configured thresholds must classify as Half-Day');
    $savedSaturday = attendance_schedule($pdo, $employeeId, '2026-09-05');
    assert_same('Off', $savedSaturday['schedule_type'], 'Unchecked weekly days must resolve as explicit Rest Days');

    echo "attendance_schedule_test: PASS\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
