<?php

declare(strict_types=1);

function firmware_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function firmware_section(string $source, string $start, string $end): string
{
    $startAt = strpos($source, $start);
    $endAt = $startAt === false ? false : strpos($source, $end, $startAt + strlen($start));
    firmware_assert($startAt !== false && $endAt !== false, "Could not locate firmware section: {$start}");
    return substr($source, $startAt, $endAt - $startAt);
}

$source = file_get_contents(__DIR__ . '/../hardware/UCC_HR_ESP32/UCC_HR_ESP32.ino');
firmware_assert(is_string($source) && $source !== '', 'Could not read the ESP32 firmware.');
$attendanceApi = file_get_contents(__DIR__ . '/../api/device/attendance.php');
$biometricPage = file_get_contents(__DIR__ . '/../biometric.php');
firmware_assert(is_string($attendanceApi) && $attendanceApi !== '', 'Could not read the attendance API.');
firmware_assert(is_string($biometricPage) && $biometricPage !== '', 'Could not read the biometric terminal page.');
firmware_assert(
    str_contains($source, '2026.09.28-fingerprint-capture-v13')
        && str_contains($source, 'enroll-five-template-v1')
        && str_contains($source, 'as608-quality-v1')
        && str_contains($source, 'attendance-auto-state-v1')
        && str_contains($source, 'attendance-duplicate-safe-v1')
        && str_contains($source, 'attendance-session-state-v1')
        && str_contains($source, 'attendance-punch-id-v1')
        && str_contains($source, 'attendance-capture-time-v1')
        && str_contains($source, 'https-ca-required-v1')
        && str_contains($source, 'command-result-retry-v1'),
    'The merged AS608 workflow must advertise the website-compatible capability.'
);
firmware_assert(
    str_contains($source, 'const bool secureUrlHasCa =')
        && str_contains($source, 'apiSecureClient.setCACert(SERVER_ROOT_CA);')
        && !str_contains($source, 'apiSecureClient.setInsecure()'),
    'HTTPS device API requests must require certificate verification and fail closed without a CA.'
);
firmware_assert(
    str_contains($source, 'constexpr uint8_t OLED_SDA = 16;')
        && str_contains($source, 'constexpr uint8_t OLED_SCL = 17;')
        && !preg_match('/constexpr uint8_t OLED_(?:SDA|SCL) = 3[4-9];/', $source),
    'OLED I2C must retain its GPIO16/17 output-capable pins, never input-only GPIO34-39.'
);

$commandResultSync = firmware_section(
    $source,
    'void deferCommandResult(',
    'void finishCommandDisplay('
);
firmware_assert(
    str_contains($commandResultSync, 'deferredCommandResult.active = true;')
        && str_contains($commandResultSync, 'void retryDeferredCommandResult()')
        && str_contains($commandResultSync, 'completeCommand(')
        && str_contains($source, '&& !deferredCommandResult.active')
        && str_contains($source, 'retryDeferredCommandResult();'),
    'A lost website command acknowledgement must be retried without starting another physical scan.'
);
firmware_assert(
    str_contains($source, '"CENTER", "LEFT", "RIGHT", "UPPER", "LOWER"'),
    'ESP32 capture order must be Center, Left, Right, Upper, Lower.'
);

$release = firmware_section(
    $source,
    'bool waitForEnrollmentFingerRelease(',
    'void reportEnrollmentPosition('
);
firmware_assert(
    str_contains($release, 'ENROLLMENT_RELEASE_STABLE_SAMPLES')
        && str_contains($release, 'ENROLLMENT_RELEASE_STABLE_MS')
        && str_contains($release, 'FINGERPRINT_NOFINGER'),
    'Enrollment must confirm a stable finger lift between captures.'
);

$positionEnrollment = firmware_section(
    $source,
    'bool enrollOnePositionTemplate(',
    'bool enrollFingerprintTemplates('
);
firmware_assert(
    str_contains($positionEnrollment, 'waitForEnrollmentImage(')
        && str_contains($positionEnrollment, 'finger.createModel()')
        && str_contains($positionEnrollment, 'finger.storeModel(sensorSlot)'),
    'Every position must create and store a real AS608 template in its assigned physical slot.'
);
firmware_assert(
    str_contains($positionEnrollment, 'ENROLLMENT_POSITION_ATTEMPTS')
        && str_contains($positionEnrollment, 'FINGERPRINT_ENROLLMISMATCH'),
    'Each position must retry a bounded mismatched confirmation pair.'
);
firmware_assert(
    substr_count($positionEnrollment, 'authorizeEnrollmentCapture(') === 2
        && !str_contains($positionEnrollment, 'positionIndex > 0')
        && str_contains($positionEnrollment, 'finger.createModel()'),
    'The first impression and completed model must be duplicate-checked without rejecting a genuinely new angle.'
);
$firstCaptureAt = strpos($positionEnrollment, 'waitForEnrollmentImage(');
$firstReleaseAt = strpos($positionEnrollment, 'waitForEnrollmentFingerRelease(', (int) $firstCaptureAt);
$firstSearchAt = strpos($positionEnrollment, 'authorizeEnrollmentCapture(', (int) $firstCaptureAt);
firmware_assert(
    $firstCaptureAt !== false
        && $firstReleaseAt !== false
        && $firstSearchAt !== false
        && $firstCaptureAt < $firstReleaseAt
        && $firstReleaseAt < $firstSearchAt,
    'Each enrollment capture must be converted, fully removed, and only then searched for ownership/duplicates.'
);

$profileEnrollment = firmware_section(
    $source,
    'bool enrollFingerprintTemplates(',
    'bool postAttendance('
);
firmware_assert(
    str_contains($profileEnrollment, 'templateSlots[0] != canonicalSlot')
        && str_contains($profileEnrollment, 'templateSlots[earlier] == templateSlots[index]')
        && str_contains($profileEnrollment, 'enrollOnePositionTemplate(')
        && str_contains($profileEnrollment, 'clearFailedEnrollmentProfile(templateSlots, modifiedSlots)'),
    'Five physical template slots must be valid, unique, and tied to one canonical employee profile.'
);

firmware_assert(
    str_contains($source, 'constexpr uint16_t MIN_MATCH_CONFIDENCE = 0;')
        && str_contains($source, 'searchManagedFingerprintBuffer(1)')
        && !str_contains($source, 'finger.fingerFastSearch(')
        && str_contains($source, 'recognitionFingerReleaseRequired'),
    'Attendance matching must use the managed AS608 range without an arbitrary second confidence floor, and retain the release latch.'
);
firmware_assert(
    str_contains($source, 'constexpr uint8_t AS608_RESIDUAL_FINGER = 0x17;')
        && str_contains($source, 'MAX_RESIDUAL_FINGER_RETRIES')
        && str_contains($source, 'preserving the captured template buffer')
        && str_contains($source, 'showMessage("SCAN CAPTURED"')
        && str_contains($source, 'showMessage("REMOVE THUMB"'),
    'Residual-finger responses must recover with bounded, user-friendly removal prompts.'
);

$managedSearch = firmware_section(
    $source,
    'uint8_t searchManagedFingerprintBuffer(',
    'bool identifyCapturedImage('
);
firmware_assert(
    str_contains($managedSearch, 'FINGERPRINT_SEARCH')
        && str_contains($managedSearch, '0x00,')
        && str_contains($managedSearch, '0x01,')
        && str_contains($managedSearch, 'min(fingerprintPhysicalCapacity, AS608_MAX_SLOT)'),
    'PS_Search must cover only website-managed pages starting at physical slot 1.'
);

$identification = firmware_section(
    $source,
    'bool identifyCapturedImage(',
    'bool resolveEmployeeFingerprintSlot('
);
firmware_assert(
    ($convertAt = strpos($identification, 'finger.image2Tz(1)')) !== false
        && ($searchAt = strpos($identification, 'searchManagedFingerprintBuffer(1)', $convertAt)) !== false
        && ($removeAt = strpos($identification, 'waitForFingerRemoved(', $searchAt)) !== false
        && $convertAt < $searchAt
        && $searchAt < $removeAt
        && str_contains($identification, 'searchCode == AS608_RESIDUAL_FINGER'),
    'Attendance must search immediately after conversion and use removal as a bounded residual-finger fallback.'
);

$recognitionWait = firmware_section(
    $source,
    'bool waitForIdentifiedFinger(',
    'bool verifyFingerTwice('
);
firmware_assert(
    str_contains($recognitionWait, 'qualityRetries')
        && str_contains($recognitionWait, 'residualSearch')
        && str_contains($recognitionWait, 'failedSearchUart')
        && str_contains($recognitionWait, 'markFingerprintOffline("finger identification search")'),
    'A poor image or recoverable residual/UART response must retry within the same attendance scan.'
);

$sensorDelay = firmware_section(
    $source,
    'void serviceSensorDelay(unsigned long durationMs) {',
    'void serviceOperationDelay(unsigned long durationMs) {'
);
firmware_assert(
    str_contains($sensorDelay, 'serviceWifi();')
        && !str_contains($sensorDelay, 'testApiConnection('),
    'Sensor sampling must service Wi-Fi without a blocking HTTP ping between image polls.'
);

$verification = firmware_section(
    $source,
    'bool verifyFingerTwice(',
    'bool waitForEnrollmentImage('
);
firmware_assert(
    substr_count($verification, 'waitForIdentifiedFinger(') === 2
        && substr_count($verification, 'resolveEmployeeFingerprintSlot(') === 2,
    'Attendance must remain two scans and resolve either physical angle to the same employee profile.'
);
firmware_assert(
    str_contains($verification, 'secondSlot != firstSlot')
        && str_contains($verification, 'matchedSlot = (int) secondSlot'),
    'Both attendance scans must resolve to the same employee before posting attendance.'
);

$approval = firmware_section(
    $source,
    'void approvePendingEnrollment()',
    'void cancelPendingEnrollment()'
);
firmware_assert(
    str_contains($approval, 'enrollFingerprintTemplates(')
        && str_contains($approval, 'enrollmentWorkflow.templateSlots')
        && ($clearAt = strpos($approval, 'waitForFingerRemoved(4000')) !== false
        && ($scanningAt = strpos($approval, '"SCANNING"')) !== false
        && $clearAt < $scanningAt,
    'BTN1 must require a clear sensor before reporting SCANNING and starting five-template enrollment.'
);

$autonomous = firmware_section(
    $source,
    'void handleAutonomousAttendance(',
    'void setup()'
);
firmware_assert(
    !str_contains($autonomous, 'pollCommand(false)')
        && str_contains($source, 'pollCommand(false);')
        && str_contains($autonomous, 'verifyFingerTwice('),
    'Website commands must be polled before idle scanning, without adding HTTP latency after an image was captured.'
);
firmware_assert(
    str_contains($source, 'String eventType = "IN";')
        && str_contains($autonomous, 'postAttendance(')
        && str_contains($autonomous, 'matchedSlot,')
        && str_contains($autonomous, 'eventType,')
        && str_contains($autonomous, 'const String punchRequestId = randomNonce();')
        && str_contains($source, 'showMessage("ALREADY TIMED IN"')
        && str_contains($source, 'showMessage("ALREADY TIMED OUT"')
        && str_contains($source, 'eventType = "IN";')
        && str_contains($source, 'eventType = "OUT";')
        && !str_contains($source, 'eventType = eventType == "IN" ? "OUT" : "IN";'),
    'IN/OUT buttons must remain available while autonomous attendance lets the API enforce schedule state.'
);

$attendancePost = firmware_section(
    $source,
    'bool postAttendance(',
    'bool completeCommand('
);
firmware_assert(
    str_contains($attendancePost, 'const bool websiteCommand = commandId.length() > 0;')
        && str_contains($attendancePost, 'const String submittedEventType = "AUTO";')
        && str_contains($attendancePost, 'const String stablePunchRequestId = websiteCommand ? commandId : punchRequestId;')
        && str_contains($attendancePost, 'requestDoc["eventType"] = submittedEventType;')
        && str_contains($attendancePost, 'requestDoc["punchRequestId"] = stablePunchRequestId;')
        && str_contains($attendancePost, 'requestDoc["requestedEventType"] = requestedEventType;')
        && str_contains($attendancePost, 'requestDoc["capturedAt"] = (uint32_t) time(nullptr);'),
    'Attendance posts must keep a stable punch id while the API resolves the actual schedule state.'
);
firmware_assert(
    str_contains($attendancePost, 'formatAttendanceMinutes(lateMinutes)')
        && str_contains($attendancePost, 'formatAttendanceMinutes(undertimeMinutes)')
        && str_contains($attendancePost, 'formatAttendanceMinutes(approvedOvertimeMinutes)')
        && !str_contains($attendancePost, 'potentialOvertimeMinutes')
        && !str_contains($attendancePost, 'potentialOT='),
    'OLED results must format Late, Undertime, and only approved overtime.'
);
firmware_assert(
    str_contains($attendancePost, 'responseDoc["sessionLabel"]')
        && str_contains($attendancePost, 'responseDoc["punchOrdinal"]')
        && str_contains($attendancePost, 'responseDoc["requiredPunches"]')
        && str_contains($attendancePost, 'responseDoc["attendanceState"]')
        && str_contains($attendancePost, 'responseDoc["nextAction"]')
        && str_contains($attendancePost, 'responseAction == "NOT_YET_ELIGIBLE"')
        && str_contains($attendancePost, 'responseAction == "BETWEEN_SESSIONS"')
        && str_contains($attendancePost, 'responseAction == "ALREADY_RECORDED"')
        && str_contains($attendancePost, 'responseAction == "DAY_CLOSED"'),
    'Firmware must display schedule session progress and non-mutating attendance states.'
);
firmware_assert(
    str_contains($attendanceApi, "(\$payload['requestedEventType'] ?? \$eventType)")
        && str_contains($attendanceApi, "\$punchRequestId = trim((string) (\$payload['punchRequestId'] ?? ''));")
        && str_contains($attendanceApi, "\$attendanceEventType = \$commandId !== '' ? \$requestedEventType : 'AUTO';")
        && str_contains($attendanceApi, '!hash_equals($commandId, $punchRequestId)')
        && str_contains($attendanceApi, '$capturedMoment')
        && str_contains($attendanceApi, '!== $requestedEventType')
        && str_contains($attendanceApi, "if ((\$payload['verifiedTwice'] ?? null) !== true)")
        && str_contains($attendanceApi, '$pdo->beginTransaction();')
        && str_contains($attendanceApi, 'SELECT * FROM device_commands WHERE command_uuid=? LIMIT 1 FOR UPDATE')
        && str_contains($attendanceApi, "if (\$commandStatus === 'Done')")
        && str_contains($attendanceApi, 'biometric_attendance_unpack_command_result(')
        && str_contains($attendanceApi, 'record_biometric_attendance(')
        && str_contains($attendanceApi, "\$commandId,\n                \$capturedMoment,\n                \$commandId")
        && str_contains($attendanceApi, 'biometric_attendance_complete_web_command($pdo, $command, $deviceId, $result);')
        && str_contains($attendanceApi, '$pdo->commit();'),
    'The API must authenticate requested IN/OUT intent and atomically finish the state-authoritative attendance command with a stable punch id.'
);
firmware_assert(
    str_contains($biometricPage, '<option value="IN">Time In</option>')
        && str_contains($biometricPage, '<option value="OUT">Time Out</option>')
        && str_contains($biometricPage, 'server safely determines the actual Time In'),
    'The website must preserve both IN/OUT controls and explain schedule/state resolution.'
);

$cleanup = firmware_section(
    $source,
    'bool clearFailedEnrollmentProfile(',
    'bool enrollFingerprintTemplates('
);
firmware_assert(
    !str_contains($cleanup, 'FINGERPRINT_DBRANGEFAIL'),
    'AS608 database-range/read errors must never be reported as successful cleanup.'
);

echo "Fingerprint firmware static regression test passed.\n";
