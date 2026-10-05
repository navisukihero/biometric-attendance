<?php

declare(strict_types=1);

/**
 * Non-mutating source regression checks for the ESP32 network integration.
 *
 * The test intentionally avoids Wi-Fi, HTTP, and database calls. It protects
 * the firmware/server contract that previously caused overlapping reconnects,
 * misleading negative status codes, malformed API URLs, and false API-online
 * state after an invalid JSON response.
 */

function esp32_network_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function esp32_network_source(string $path, string $label): string
{
    $source = file_get_contents($path);
    esp32_network_assert(is_string($source) && $source !== '', $label . ' must be readable');
    return $source;
}

function esp32_network_section(string $source, string $start, string $end): string
{
    $startAt = strpos($source, $start);
    $endAt = $startAt === false ? false : strpos($source, $end, $startAt + strlen($start));
    esp32_network_assert(
        $startAt !== false && $endAt !== false,
        'Could not locate firmware section beginning with ' . $start
    );
    return substr($source, $startAt, $endAt - $startAt);
}

$root = realpath(__DIR__ . '/..');
esp32_network_assert(is_string($root), 'Project root must resolve');

try {
    $firmware = esp32_network_source(
        $root . '/hardware/UCC_HR_ESP32/UCC_HR_ESP32.ino',
        'ESP32 firmware'
    );
    $secretsExample = esp32_network_source(
        $root . '/hardware/UCC_HR_ESP32/secrets.example.h',
        'ESP32 secrets example'
    );
    $startServer = esp32_network_source($root . '/start-server.cmd', 'LAN start script');
    $fixedLauncher = esp32_network_source($root . '/start-fixed-system.ps1', 'verified LAN launcher');
    $router = esp32_network_source($root . '/router.php', 'PHP development-server router');
    $apacheRules = esp32_network_source($root . '/.htaccess', 'Apache access rules');

    // Only the explicit retry state machine may reconnect. Letting the ESP32
    // SDK auto-reconnect in parallel can overlap WiFi.begin() and trigger
    // "sta is connecting, cannot set config" indefinitely.
    esp32_network_assert(
        str_contains($firmware, 'WiFi.setAutoReconnect(false);'),
        'Firmware must disable SDK auto-reconnect'
    );
    esp32_network_assert(
        !str_contains($firmware, 'WiFi.setAutoReconnect(true);'),
        'Firmware must never enable SDK auto-reconnect alongside its state machine'
    );

    $wifiState = esp32_network_section(
        $firmware,
        'enum class WifiConnectionState',
        'WifiConnectionState wifiConnectionState'
    );
    foreach (['IDLE', 'CONNECTING', 'ONLINE'] as $state) {
        esp32_network_assert(
            str_contains($wifiState, $state),
            'Wi-Fi state machine must define ' . $state
        );
    }

    $beginAttempt = esp32_network_section(
        $firmware,
        'void beginWifiAttempt()',
        'void markWifiOnline()'
    );
    esp32_network_assert(
        str_contains($beginAttempt, 'wifiConnectionState = WifiConnectionState::CONNECTING;')
            && str_contains($beginAttempt, 'wifiAttemptStarted = millis();')
            && str_contains($beginAttempt, 'WiFi.begin(WIFI_SSID, WIFI_PASSWORD);'),
        'A controlled Wi-Fi attempt must enter CONNECTING, start its timeout, and call WiFi.begin'
    );
    preg_match_all('/^\s*(?!\/\/)WiFi\.begin\s*\(/m', $firmware, $wifiBeginCalls);
    esp32_network_assert(
        count($wifiBeginCalls[0]) === 1,
        'WiFi.begin must have exactly one controlled call site'
    );

    $retry = esp32_network_section(
        $firmware,
        'void scheduleWifiRetry(unsigned long now)',
        'void serviceWifi()'
    );
    esp32_network_assert(
        str_contains($retry, 'WiFi.disconnect(false, false)')
            && str_contains($retry, 'wifiConnectionState = WifiConnectionState::IDLE;')
            && str_contains($retry, 'wifiNextAttemptAt = now + wifiRetryDelayMs;')
            && str_contains($retry, 'WIFI_RETRY_MAX_MS'),
        'Timed-out Wi-Fi attempts must be cancelled and rescheduled with bounded backoff'
    );

    $wifiService = esp32_network_section(
        $firmware,
        'void serviceWifi()',
        'void beginClockSync()'
    );
    esp32_network_assert(
        str_contains($wifiService, 'WifiConnectionState::ONLINE')
            && str_contains($wifiService, 'WifiConnectionState::CONNECTING')
            && str_contains($wifiService, 'WIFI_CONNECT_TIMEOUT_MS')
            && str_contains($wifiService, 'scheduleWifiRetry(now)')
            && str_contains($wifiService, 'deadlineReached(now, wifiNextAttemptAt)')
            && str_contains($wifiService, 'beginWifiAttempt()'),
        'serviceWifi must exclusively drive online, timeout, backoff, and retry transitions'
    );

    // HTTPClient reserves small negative values for real socket errors. Keep
    // firmware preflight results at -100 or below and unique so -1 is never
    // mislabeled as the firmware's clock/config error.
    preg_match_all(
        '/constexpr\s+int\s+(REQUEST_STATUS_[A-Z0-9_]+)\s*=\s*(-\d+)\s*;/',
        $firmware,
        $statusMatches,
        PREG_SET_ORDER
    );
    esp32_network_assert(
        count($statusMatches) >= 6,
        'Firmware must define explicit internal request status constants'
    );
    $statusValues = [];
    foreach ($statusMatches as $statusMatch) {
        $name = (string) $statusMatch[1];
        $value = (int) $statusMatch[2];
        esp32_network_assert(
            $value <= -100,
            $name . ' must stay outside HTTPClient reserved errors -1 through -11'
        );
        esp32_network_assert(
            !isset($statusValues[$value]),
            $name . ' must not reuse another internal request status value'
        );
        $statusValues[$value] = $name;
        esp32_network_assert(
            substr_count($firmware, $name) >= 3,
            $name . ' must be declared, assigned, and translated for diagnostics'
        );
    }
    esp32_network_assert(
        preg_match('/statusCode\s*=\s*-(?:[1-9]|1[01])\b/', $firmware) !== 1,
        'Internal status assignments must not overlap HTTPClient errors -1 through -11'
    );

    // Normalize one configured base URL at boot and refuse malformed input
    // before opening a network client. This catches copied Markdown links and
    // trailing slashes that otherwise produce misleading 404/auth failures.
    $apiInitialization = esp32_network_section(
        $firmware,
        'void initializeApiConfiguration()',
        'bool apiUsesHttps()'
    );
    esp32_network_assert(
        str_contains($apiInitialization, 'String(API_BASE_URL)')
            && str_contains($apiInitialization, '.trim()')
            && str_contains($apiInitialization, '.endsWith("/")')
            && str_contains($apiInitialization, '.remove('),
        'API_BASE_URL must be trimmed and have trailing slashes removed once at startup'
    );
    esp32_network_assert(
        str_contains($apiInitialization, 'startsWith("http://")')
            && str_contains($apiInitialization, 'startsWith("https://")')
            && str_contains($apiInitialization, "indexOf(' ')")
            && str_contains($apiInitialization, "indexOf('[')")
            && str_contains($apiInitialization, 'apiConfigurationValid'),
        'API_BASE_URL must reject unsupported schemes, whitespace, and copied Markdown links'
    );

    $signedRequest = esp32_network_section(
        $firmware,
        'String signedRequest(',
        'bool testApiConnection('
    );
    $configurationCheckAt = strpos($signedRequest, 'if (!apiConfigurationValid)');
    $httpBeginAt = strpos($signedRequest, 'http.begin(');
    esp32_network_assert(
        $configurationCheckAt !== false
            && $httpBeginAt !== false
            && $configurationCheckAt < $httpBeginAt
            && str_contains($signedRequest, 'REQUEST_STATUS_INVALID_API_URL'),
        'signedRequest must reject an invalid normalized API URL before HTTPClient.begin'
    );
    $canonicalPathAt = strpos($signedRequest, 'canonical += path;');
    $canonicalDeviceAt = strpos($signedRequest, 'canonical += DEVICE_ID;');
    $canonicalCapabilitiesAt = strpos($signedRequest, 'canonical += DEVICE_CAPABILITIES;');
    $canonicalFirmwareAt = strpos($signedRequest, 'canonical += FIRMWARE_VERSION;');
    $canonicalTimestampAt = strpos($signedRequest, 'canonical += timestamp;');
    $canonicalNonceAt = strpos($signedRequest, 'canonical += nonce;');
    $canonicalBodyAt = strpos($signedRequest, 'canonical += body;');
    esp32_network_assert(
        $canonicalPathAt !== false
            && $canonicalDeviceAt !== false
            && $canonicalCapabilitiesAt !== false
            && $canonicalFirmwareAt !== false
            && $canonicalTimestampAt !== false
            && $canonicalNonceAt !== false
            && $canonicalBodyAt !== false
            && $canonicalPathAt < $canonicalDeviceAt
            && $canonicalDeviceAt < $canonicalCapabilitiesAt
            && $canonicalCapabilitiesAt < $canonicalFirmwareAt
            && $canonicalFirmwareAt < $canonicalTimestampAt
            && $canonicalTimestampAt < $canonicalNonceAt
            && $canonicalNonceAt < $canonicalBodyAt
            && str_contains($signedRequest, 'http.addHeader("X-Device-ID", DEVICE_ID);')
            && str_contains($signedRequest, 'http.addHeader("X-Device-Capabilities", DEVICE_CAPABILITIES);')
            && str_contains($signedRequest, 'http.addHeader("X-Firmware-Version", FIRMWARE_VERSION);'),
        'The HMAC canonical string must bind device identity and firmware claims before timestamp, nonce, and body'
    );
    esp32_network_assert(
        str_contains($firmware, 'return configuredApiBaseUrl + path;'),
        'All API paths must use the normalized base URL'
    );

    // A TCP 200 response is not sufficient authentication. The command poll
    // must parse JSON and require the API's explicit ok=true before restoring
    // the authenticated state.
    $pollCommand = esp32_network_section(
        $firmware,
        'void pollCommand(bool manualRequest = false)',
        'const char* resetReasonName('
    );
    $deserializeAt = strpos($pollCommand, 'deserializeJson(doc, response)');
    $okValidationAt = strpos($pollCommand, 'doc["ok"]');
    $authenticatedAt = strpos($pollCommand, 'apiAuthenticated = true;');
    esp32_network_assert(
        $deserializeAt !== false
            && $okValidationAt !== false
            && $authenticatedAt !== false
            && $deserializeAt < $authenticatedAt
            && $okValidationAt < $authenticatedAt,
        'Command JSON parsing and ok=true validation must precede apiAuthenticated=true'
    );
    $beforeAuthenticated = substr($pollCommand, 0, $authenticatedAt);
    esp32_network_assert(
        str_contains($beforeAuthenticated, 'parseError')
            && str_contains($beforeAuthenticated, 'apiAuthenticated = false;')
            && preg_match('/parseError\s*\|\|\s*!?\(?doc\["ok"\]/', $beforeAuthenticated) === 1,
        'Invalid command JSON or ok=false must explicitly clear API authentication'
    );

    $normalizedSecretsExample = preg_replace('/\s+/', ' ', $secretsExample) ?? $secretsExample;
    esp32_network_assert(
        str_contains(
            $normalizedSecretsExample,
            'C:\\xampp\\php\\php.exe -S 0.0.0.0:8080 router.php'
        ),
        'secrets.example.h must document the complete LAN-safe PHP command including router.php'
    );
    esp32_network_assert(
        !str_contains($normalizedSecretsExample, '-S localhost:8080'),
        'ESP32 setup documentation must never bind the PHP server to localhost'
    );

    $normalizedStartServer = preg_replace('/\s+/', ' ', $startServer) ?? $startServer;
    $normalizedFixedLauncher = preg_replace('/\s+/', ' ', $fixedLauncher) ?? $fixedLauncher;
    esp32_network_assert(
        str_contains($normalizedStartServer, 'cd /d "%~dp0"'),
        'start-server.cmd must run from the project directory'
    );
    esp32_network_assert(
        preg_match(
            '/(?:pwsh|powershell)\.exe\s+-NoProfile\s+-ExecutionPolicy\s+Bypass\s+-File\s+"%~dp0start-fixed-system\.ps1"/i',
            $normalizedStartServer
        ) === 1,
        'start-server.cmd must delegate to the verified LAN launcher'
    );
    esp32_network_assert(
        preg_match(
            '/&\s*\$phpExecutable(?:\s+-d\s+\S+)*\s+-S\s+0\.0\.0\.0:8080\s+router\.php/i',
            $normalizedFixedLauncher
        ) === 1,
        'The verified launcher must apply its PHP hardening flags, bind port 8080 on the LAN, and load router.php'
    );
    esp32_network_assert(
        str_contains($fixedLauncher, "@('127.0.0.1', '::1')")
            && str_contains($fixedLauncher, "'--console'")
            && str_contains($fixedLauncher, 'function Get-LanIPv4Address')
            && str_contains($fixedLauncher, 'API_BASE_URL'),
        'The launcher must detect IPv4/IPv6 port conflicts, keep recovered MariaDB alive, and warn when the ESP32 API address changed'
    );
    esp32_network_assert(
        preg_match('/(?:\$phpExecutable|"%UCCHR_PHP%")\s+-S\s+localhost:8080/i', $normalizedStartServer . ' ' . $normalizedFixedLauncher) !== 1,
        'The server launchers must not start a localhost-only listener'
    );

    // LAN binding must not turn local database/cache artifacts into downloads.
    // Protect both the supported private directories and an accidentally
    // literal Windows %SystemDrive% directory created by an old command.
    esp32_network_assert(
        str_contains($router, "'/config/'")
            && str_contains($router, "'/database/'")
            && str_contains($router, "'/hardware/'")
            && str_contains($router, "'.db'")
            && str_contains($router, "'/%systemdrive%/'"),
        'The PHP router must block application internals and project-local database/cache files'
    );
    esp32_network_assert(
        str_contains($apacheRules, '(?:config|database|hardware|includes|pages|employee_pages|tests)')
            && str_contains($apacheRules, '|db|')
            && str_contains($apacheRules, '|ps1|psm1|cmd|bat|sh)'),
        'Apache rules must block the same private source and database/cache extensions'
    );

    echo "esp32_network_integration_test: PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, "esp32_network_integration_test: FAIL - {$error->getMessage()}\n");
    exit(1);
}
