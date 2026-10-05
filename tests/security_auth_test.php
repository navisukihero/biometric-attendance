<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/admin_auth.php';

function security_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function security_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true) . ')'
        );
    }
}

// Regression: hash_equals('', '') is true, so CSRF validation must explicitly
// reject missing/blank/non-string values before comparing tokens.
security_assert_same(false, csrf_token_is_valid(null, null), 'Two missing CSRF tokens must fail closed');
security_assert_same(false, csrf_token_is_valid('', ''), 'Two blank CSRF tokens must fail closed');
security_assert_same(false, csrf_token_is_valid('known-token', null), 'A missing submitted CSRF token must fail');
security_assert_same(false, csrf_token_is_valid(null, 'known-token'), 'A missing session CSRF token must fail');
security_assert_same(false, csrf_token_is_valid([], []), 'Non-string CSRF values must fail safely');
security_assert_same(false, csrf_token_is_valid('known-token', 'different-token'), 'Mismatched CSRF tokens must fail');
security_assert_same(true, csrf_token_is_valid('known-token', 'known-token'), 'An exact non-empty CSRF token must pass');

// PASSWORD_DEFAULT currently uses bcrypt on this runtime. Bcrypt ignores bytes
// after 72 unless the application rejects overlong input before verification.
$seventyTwoBytePassword = str_repeat('a', 72);
$hash = ucchr_password_hash($seventyTwoBytePassword);
security_assert(
    ucchr_password_verify($seventyTwoBytePassword, $hash),
    'A valid 72-byte password must verify'
);
security_assert_same(
    false,
    ucchr_password_verify($seventyTwoBytePassword . 'ignored-suffix', $hash),
    'An over-72-byte password must not authenticate through bcrypt truncation'
);

$tableExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admin_auth_throttles'"
)->fetchColumn();
security_assert_same(1, $tableExists, 'Administrator authentication throttle migration must be installed');

$suffix = bin2hex(random_bytes(16));
$scopes = [
    ['type' => 'Client', 'hash' => hash('sha256', 'security-test-client-' . $suffix)],
    ['type' => 'Account', 'hash' => hash('sha256', 'security-test-account-' . $suffix)],
];

$pdo->beginTransaction();
try {
    security_assert_same(false, admin_auth_is_throttled($pdo, $scopes), 'New authentication scopes must not start locked');
    for ($attempt = 1; $attempt < ADMIN_AUTH_MAX_FAILED_ATTEMPTS; $attempt++) {
        security_assert_same(
            false,
            admin_auth_record_failure($pdo, $scopes),
            'Authentication must not lock before the configured failure threshold'
        );
    }
    security_assert_same(
        true,
        admin_auth_record_failure($pdo, $scopes),
        'The configured failure threshold must create a temporary lock'
    );
    security_assert_same(true, admin_auth_is_throttled($pdo, $scopes), 'A newly locked scope must reject further attempts');

    admin_auth_clear_failures($pdo, $scopes);
    security_assert_same(false, admin_auth_is_throttled($pdo, $scopes), 'Successful authentication must clear both scopes');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$biometricSource = file_get_contents(__DIR__ . '/../biometric.php');
security_assert(is_string($biometricSource), 'Biometric terminal source must be readable');
security_assert(
    str_contains($biometricSource, '$adminAuthorized = admin_session_is_valid($pdo);'),
    'Biometric control must validate an administrator session'
);
security_assert(
    str_contains($biometricSource, "http_response_code(401);")
        && str_contains($biometricSource, 'if (!$adminAuthorized)'),
    'A direct unauthenticated biometric POST must return HTTP 401'
);
security_assert(
    str_contains($biometricSource, '<?php if ($adminAuthorized): ?>')
        && str_contains($biometricSource, 'Employee names, fingerprint mappings, and scanner controls remain private'),
    'Public biometric status must not render employee controls'
);

$deviceBootstrapSource = file_get_contents(__DIR__ . '/../api/device/bootstrap.php');
security_assert(is_string($deviceBootstrapSource), 'Device API bootstrap source must be readable');
$canonicalStart = strpos($deviceBootstrapSource, '$canonical = ');
$canonicalEnd = $canonicalStart === false ? false : strpos($deviceBootstrapSource, ';', $canonicalStart);
$canonicalExpression = $canonicalStart !== false && $canonicalEnd !== false
    ? substr($deviceBootstrapSource, $canonicalStart, $canonicalEnd - $canonicalStart)
    : '';
$previousPosition = -1;
foreach (
    [
        '$method',
        '$signedPath',
        '$deviceId',
        '$deviceCapabilities',
        '$firmwareVersion',
        '$timestamp',
        '$nonce',
        '$body',
    ] as $signedField
) {
    $position = strpos($canonicalExpression, $signedField);
    security_assert(
        $position !== false && $position > $previousPosition,
        'Device API canonical signature fields must use the documented deterministic order'
    );
    $previousPosition = $position;
}
security_assert(
    substr_count($canonicalExpression, '"\\n"') === 7,
    'Device API canonical signature fields must be separated unambiguously'
);
security_assert(
    str_contains($deviceBootstrapSource, '$deviceCapabilities = $headers[\'x-device-capabilities\'] ?? \'\';')
        && str_contains($deviceBootstrapSource, '$firmwareVersion = $headers[\'x-firmware-version\'] ?? \'\';'),
    'Absent signed device metadata must have deterministic empty-string values'
);

$routerSource = file_get_contents(__DIR__ . '/../router.php');
$apacheRules = file_get_contents(__DIR__ . '/../.htaccess');
security_assert(is_string($routerSource) && is_string($apacheRules), 'Web-server protection rules must be readable');
foreach (['ps1', 'psm1', 'cmd', 'bat', 'sh'] as $privateExtension) {
    security_assert(
        str_contains($routerSource, "'.{$privateExtension}'")
            && str_contains($apacheRules, $privateExtension),
        'Maintenance script extension must be denied by both supported web-server configurations: ' . $privateExtension
    );
}

$rotationSource = file_get_contents(__DIR__ . '/../rotate-device-secret.ps1');
security_assert(is_string($rotationSource), 'Device-secret rotation helper must be readable');
security_assert(
    !str_contains($rotationSource, "require getcwd() . '/config/database.php'")
        && str_contains($rotationSource, '.secrets.h.rotation-pending')
        && str_contains($rotationSource, '[System.IO.File]::Replace')
        && str_contains($rotationSource, 'DATABASE_UPDATED'),
    'Device-secret rotation must fail closed on database errors and retain an interruption-recovery stage'
);

echo "security_auth_test: PASS\n";
