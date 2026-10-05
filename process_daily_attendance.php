<?php
declare(strict_types=1);

/**
 * Process one calendar date of raw attendance into the daily attendance table.
 *
 * Intended for Windows Task Scheduler or a manual CLI recovery run. This file
 * deliberately has no web entry point and never accepts dates from HTTP.
 *
 * Examples:
 *   C:\xampp\php\php.exe database\process_daily_attendance.php
 *   C:\xampp\php\php.exe database\process_daily_attendance.php --date=2026-09-05
 *   C:\xampp\php\php.exe database\process_daily_attendance.php --date=2026-09-05 --force=1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

/** @return array{date: string, force: bool, help: bool} */
function ucchr_daily_attendance_options(array $arguments, DateTimeImmutable $now): array
{
    $date = $now->modify('-1 day')->format('Y-m-d');
    $force = false;
    $help = false;
    $seen = [];

    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            if (isset($seen['help'])) {
                throw new InvalidArgumentException('The help option was supplied more than once.');
            }
            $seen['help'] = true;
            $help = true;
            continue;
        }

        if (str_starts_with($argument, '--date=')) {
            if (isset($seen['date'])) {
                throw new InvalidArgumentException('The --date option was supplied more than once.');
            }
            $seen['date'] = true;
            $date = substr($argument, strlen('--date='));
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $now->getTimezone());
            $errors = DateTimeImmutable::getLastErrors();
            if (!$parsed
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $parsed->format('Y-m-d') !== $date
            ) {
                throw new InvalidArgumentException('--date must be a real calendar date in YYYY-MM-DD format.');
            }
            continue;
        }

        if (str_starts_with($argument, '--force=')) {
            if (isset($seen['force'])) {
                throw new InvalidArgumentException('The --force option was supplied more than once.');
            }
            $seen['force'] = true;
            $forceValue = substr($argument, strlen('--force='));
            if (!in_array($forceValue, ['0', '1'], true)) {
                throw new InvalidArgumentException('--force accepts only 0 or 1.');
            }
            $force = $forceValue === '1';
            continue;
        }

        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }

    if ($help && count($seen) > 1) {
        throw new InvalidArgumentException('--help cannot be combined with processing options.');
    }

    return ['date' => $date, 'force' => $force, 'help' => $help];
}

function ucchr_daily_attendance_usage(): string
{
    return implode(PHP_EOL, [
        'Usage:',
        '  php database\\process_daily_attendance.php [--date=YYYY-MM-DD] [--force=0|1]',
        '',
        'Options:',
        '  --date=YYYY-MM-DD  Date to process; defaults to yesterday in UCCHR_TIMEZONE.',
        '  --force=0|1        Force final classification when 1; defaults to 0.',
        '  --help, -h         Show this help without connecting to the database.',
        '',
    ]);
}

function ucchr_daily_attendance_connection(): PDO
{
    $host = getenv('UCCHR_DB_HOST') ?: '127.0.0.1';
    $port = getenv('UCCHR_DB_PORT') ?: '3307';
    $database = getenv('UCCHR_DB_NAME') ?: 'ucchr_system_recovered';
    $user = getenv('UCCHR_DB_USER') ?: 'root';
    $password = getenv('UCCHR_DB_PASS') ?: '';

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/**
 * Write the integrated structured audit row, while preserving compatibility
 * with the original three-column activity_logs table.
 */
function ucchr_daily_attendance_audit(
    PDO $pdo,
    string $date,
    bool $force,
    string $action,
    string $description,
    array $result
): void {
    $payload = [
        'date' => $date,
        'force' => $force,
        'processed' => isset($result['processed']) ? (int) $result['processed'] : null,
        'deferred' => isset($result['deferred']) ? (int) $result['deferred'] : null,
        'outcome' => (string) ($result['outcome'] ?? 'unknown'),
        'executed_at' => date(DATE_ATOM),
        'timezone' => date_default_timezone_get(),
    ];
    if (isset($result['error'])) {
        $payload['error'] = substr((string) $result['error'], 0, 1000);
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO activity_logs
                (user_id, action, module, record_id, description,
                 old_values, new_values, ip_address, created_at)
             VALUES (NULL, ?, "Attendance", ?, ?, NULL, ?, NULL, NOW())'
        );
        $statement->execute([
            $action,
            $date,
            $description,
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ]);
        return;
    } catch (PDOException $structuredError) {
        // An installation with the legacy activity_logs layout lacks the
        // structured columns. Retain a NULL-user audit using its original
        // user_id/action/created_at contract instead of losing the event.
    }

    $legacyAction = substr(
        $action . ': ' . $description
        . ' [date=' . $date
        . ', force=' . ($force ? '1' : '0')
        . ', outcome=' . $payload['outcome'] . ']',
        0,
        255
    );
    $statement = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, action, created_at)
         VALUES (NULL, ?, NOW())'
    );
    $statement->execute([$legacyAction]);
}

function ucchr_process_daily_attendance_main(array $arguments): int
{
    $timezoneName = getenv('UCCHR_TIMEZONE') ?: 'Asia/Manila';
    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable $error) {
        fwrite(STDERR, "ERROR: UCCHR_TIMEZONE is invalid.\n");
        return 2;
    }
    date_default_timezone_set($timezone->getName());

    try {
        $options = ucchr_daily_attendance_options(
            $arguments,
            new DateTimeImmutable('now', $timezone)
        );
    } catch (InvalidArgumentException $error) {
        fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL . PHP_EOL);
        fwrite(STDERR, ucchr_daily_attendance_usage());
        return 2;
    }

    if ($options['help']) {
        fwrite(STDOUT, ucchr_daily_attendance_usage());
        return 0;
    }

    $pdo = null;
    try {
        $pdo = ucchr_daily_attendance_connection();
        require_once dirname(__DIR__) . '/includes/attendance_processing.php';

        if (!attendance_processing_schema_ready($pdo)) {
            throw new RuntimeException(
                'The integrated attendance/payroll migration has not been applied.'
            );
        }

        $result = attendance_process_date(
            $pdo,
            $options['date'],
            new DateTimeImmutable('now', $timezone),
            $options['force']
        );
        ucchr_daily_attendance_audit(
            $pdo,
            $options['date'],
            $options['force'],
            'Processed daily attendance from CLI',
            sprintf(
                'Daily attendance processing completed: %d processed, %d deferred.',
                (int) $result['processed'],
                (int) $result['deferred']
            ),
            [
                'processed' => (int) $result['processed'],
                'deferred' => (int) $result['deferred'],
                'outcome' => 'success',
            ]
        );

        fwrite(
            STDOUT,
            sprintf(
                "OK: date=%s processed=%d deferred=%d force=%d\n",
                $options['date'],
                (int) $result['processed'],
                (int) $result['deferred'],
                $options['force'] ? 1 : 0
            )
        );
        return 0;
    } catch (Throwable $error) {
        $auditError = null;
        if ($pdo instanceof PDO) {
            try {
                ucchr_daily_attendance_audit(
                    $pdo,
                    $options['date'],
                    $options['force'],
                    'Daily attendance CLI processing failed',
                    'Daily attendance processing failed before successful completion.',
                    ['outcome' => 'failed', 'error' => $error->getMessage()]
                );
            } catch (Throwable $auditFailure) {
                $auditError = $auditFailure->getMessage();
            }
        }

        fwrite(STDERR, 'ERROR: attendance processing failed: ' . $error->getMessage() . PHP_EOL);
        if ($auditError !== null) {
            fwrite(STDERR, 'ERROR: failure audit could not be written: ' . $auditError . PHP_EOL);
        }
        return 1;
    }
}

exit(ucchr_process_daily_attendance_main($argv));
