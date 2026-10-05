<?php
declare(strict_types=1);

/**
 * Narrow repair utility for MariaDB error 1932 on fingerprint_template_slots.
 *
 * This is intentionally a PHP CLI utility rather than an unconditional SQL
 * migration. PDO exposes MariaDB's native driver error code, allowing the
 * repair to distinguish the known "exists in metadata, missing in engine"
 * failure from a healthy table, an absent table, a permission error, or any
 * other unexpected database condition.
 *
 * Safe preflight (never changes the database):
 *   C:\xampp\php\php.exe database\repair_fingerprint_template_slots.php \
 *       --database=ucchr_system_recovered
 *
 * Execute after reviewing the preflight result and taking a backup:
 *   C:\xampp\php\php.exe database\repair_fingerprint_template_slots.php \
 *       --database=ucchr_system_recovered --execute
 *
 * Connection settings use UCCHR_DB_HOST, UCCHR_DB_PORT, UCCHR_DB_USER, and
 * UCCHR_DB_PASS when present, with the same local XAMPP defaults as the app.
 * The database name is mandatory and is never taken from an implicit default.
 *
 * Exit codes:
 *   0 = check completed or repair completed
 *   2 = healthy table; execution deliberately refused
 *   3 = unexpected table/database condition; no repair attempted
 *   4 = validated repair failed after it began
 */

const UCCHR_TEMPLATE_TABLE = 'fingerprint_template_slots';
const UCCHR_ENGINE_MISSING_ERROR = 1932;

/** @return array{type: ?string, engine: ?string} */
function ucchr_template_table_metadata(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT TABLE_TYPE, ENGINE
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
         LIMIT 1'
    );
    $statement->execute([UCCHR_TEMPLATE_TABLE]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return [
        'type' => $row === false ? null : (string) $row['TABLE_TYPE'],
        'engine' => $row === false ? null : (string) ($row['ENGINE'] ?? ''),
    ];
}

function ucchr_mariadb_error_code(PDOException $exception): int
{
    if (isset($exception->errorInfo[1]) && is_numeric($exception->errorInfo[1])) {
        return (int) $exception->errorInfo[1];
    }
    return 0;
}

/**
 * @return array{state: 'healthy'|'engine_missing_1932'|'unexpected', code: int, message: string}
 */
function ucchr_probe_template_table(PDO $pdo): array
{
    $metadata = ucchr_template_table_metadata($pdo);
    try {
        $pdo->query('SELECT 1 FROM fingerprint_template_slots LIMIT 1');
        return [
            'state' => 'healthy',
            'code' => 0,
            'message' => 'fingerprint_template_slots is readable; no repair is permitted.',
        ];
    } catch (PDOException $exception) {
        $driverCode = ucchr_mariadb_error_code($exception);
        if ($driverCode === UCCHR_ENGINE_MISSING_ERROR
            && $metadata['type'] === 'BASE TABLE'
            && strcasecmp((string) $metadata['engine'], 'InnoDB') === 0
        ) {
            return [
                'state' => 'engine_missing_1932',
                'code' => $driverCode,
                'message' => 'MariaDB error 1932 confirmed for the InnoDB table metadata.',
            ];
        }

        return [
            'state' => 'unexpected',
            'code' => $driverCode,
            'message' => 'Refusing repair: table probe failed with an unexpected condition: '
                . $exception->getMessage(),
        ];
    }
}

/** Verify all authoritative sources before destructive DDL is allowed. */
function ucchr_validate_template_repair_sources(PDO $pdo): int
{
    $requiredColumns = [
        'fingerprint_registrations' => [
            'employee_id', 'fingerprint_slot', 'mapping_status',
            'enrollment_version', 'device_id', 'enrolled_at',
        ],
        'employees' => ['id'],
        'device_commands' => [
            'id', 'employee_id', 'fingerprint_slot', 'command_type',
            'status', 'device_id', 'completed_at',
        ],
    ];

    $columnQuery = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            $columnQuery->execute([$table, $column]);
            if ((int) $columnQuery->fetchColumn() !== 1) {
                throw new RuntimeException("Required source {$table}.{$column} is missing.");
            }
        }
    }

    $invalid = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM fingerprint_registrations fr
         LEFT JOIN employees e ON e.id=fr.employee_id
         WHERE e.id IS NULL OR fr.fingerprint_slot NOT BETWEEN 1 AND 127'
    )->fetchColumn();
    if ($invalid !== 0) {
        throw new RuntimeException(
            "Canonical fingerprint registrations contain {$invalid} orphaned or out-of-range row(s)."
        );
    }

    $duplicates = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
             SELECT fingerprint_slot
             FROM fingerprint_registrations
             GROUP BY fingerprint_slot
             HAVING COUNT(*) > 1
         ) duplicate_slots'
    )->fetchColumn();
    if ($duplicates !== 0) {
        throw new RuntimeException(
            "Canonical fingerprint registrations contain {$duplicates} duplicate sensor slot(s)."
        );
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM fingerprint_registrations')->fetchColumn();
}

/**
 * Recreate the exact project table and restore only its authoritative CENTER
 * mappings. LEFT/RIGHT/UPPER/LOWER are deliberately not guessed; the existing
 * enrollment workflow will allocate those positions on the next re-enrollment.
 *
 * This function assumes the caller has already confirmed driver error 1932.
 * It is public only so its DDL/backfill can be exercised on an isolated clone.
 *
 * @return array{canonical: int, restored: int}
 */
function ucchr_recreate_template_table(PDO $pdo): array
{
    $canonicalCount = ucchr_validate_template_repair_sources($pdo);

    $pdo->exec('DROP TABLE fingerprint_template_slots');
    $pdo->exec(
        "CREATE TABLE fingerprint_template_slots (
            employee_id INT UNSIGNED NOT NULL,
            position ENUM('CENTER','LEFT','RIGHT','UPPER','LOWER') NOT NULL,
            sensor_slot SMALLINT UNSIGNED NOT NULL,
            mapping_status ENUM('Reserved','Pending','Enrolled','Failed') NOT NULL DEFAULT 'Reserved',
            enrollment_version INT UNSIGNED NOT NULL DEFAULT 0,
            device_id VARCHAR(80) NULL,
            enrolled_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (employee_id, position),
            UNIQUE KEY uq_fingerprint_template_sensor_slot (sensor_slot),
            KEY idx_fingerprint_template_employee_status (employee_id, mapping_status),
            CONSTRAINT fk_fingerprint_template_employee
                FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );

    $restored = $pdo->exec(
        "INSERT INTO fingerprint_template_slots
            (employee_id, position, sensor_slot, mapping_status,
             enrollment_version, device_id, enrolled_at)
         SELECT fr.employee_id,
                'CENTER',
                fr.fingerprint_slot,
                fr.mapping_status,
                fr.enrollment_version,
                CASE
                    WHEN fr.device_id IS NOT NULL AND fr.device_id<>'' THEN fr.device_id
                    WHEN fr.mapping_status='Enrolled' THEN completed.device_id
                    ELSE NULL
                END,
                CASE
                    WHEN fr.enrolled_at IS NOT NULL THEN fr.enrolled_at
                    WHEN fr.mapping_status='Enrolled' THEN completed.completed_at
                    ELSE NULL
                END
         FROM fingerprint_registrations fr
         LEFT JOIN (
             SELECT dc.employee_id, dc.fingerprint_slot,
                    dc.device_id, dc.completed_at
             FROM device_commands dc
             JOIN (
                 SELECT employee_id, fingerprint_slot, MAX(id) AS latest_id
                 FROM device_commands
                 WHERE command_type='ENROLL' AND status='Done'
                 GROUP BY employee_id, fingerprint_slot
             ) latest ON latest.latest_id=dc.id
         ) completed
           ON completed.employee_id=fr.employee_id
          AND completed.fingerprint_slot=fr.fingerprint_slot"
    );
    $restored = $restored === false ? 0 : $restored;

    $verified = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM fingerprint_template_slots
         WHERE position='CENTER'"
    )->fetchColumn();
    $unexpectedPositions = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM fingerprint_template_slots
         WHERE position<>'CENTER'"
    )->fetchColumn();
    if ($verified !== $canonicalCount || $unexpectedPositions !== 0) {
        throw new RuntimeException(
            "Repair verification failed: expected {$canonicalCount} canonical CENTER row(s), found {$verified}."
        );
    }

    $pdo->query('SELECT 1 FROM fingerprint_template_slots LIMIT 1');
    return ['canonical' => $canonicalCount, 'restored' => $restored];
}

/** @param list<string> $arguments */
function ucchr_fingerprint_repair_main(array $arguments): int
{
    $options = getopt('', ['database:', 'host::', 'port::', 'user::', 'execute']);
    $database = trim((string) ($options['database'] ?? ''));
    if ($database === '' || !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        fwrite(STDERR, "A valid explicit --database=name argument is required.\n");
        return 3;
    }

    $host = (string) ($options['host'] ?? getenv('UCCHR_DB_HOST') ?: '127.0.0.1');
    $port = (string) ($options['port'] ?? getenv('UCCHR_DB_PORT') ?: '3307');
    $user = (string) ($options['user'] ?? getenv('UCCHR_DB_USER') ?: 'root');
    $password = (string) (getenv('UCCHR_DB_PASS') ?: '');
    $execute = array_key_exists('execute', $options);

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $exception) {
        fwrite(STDERR, 'Database connection failed; no changes made: ' . $exception->getMessage() . "\n");
        return 3;
    }

    $probe = ucchr_probe_template_table($pdo);
    fwrite(STDOUT, "Preflight: {$probe['message']}\n");
    if ($probe['state'] === 'healthy') {
        if ($execute) {
            fwrite(STDERR, "ABORTED: a healthy table is never dropped or rebuilt.\n");
            return 2;
        }
        return 0;
    }
    if ($probe['state'] !== 'engine_missing_1932') {
        fwrite(STDERR, "ABORTED: expected MariaDB error 1932; no changes made.\n");
        return 3;
    }
    if (!$execute) {
        fwrite(STDOUT, "Eligible for repair, but --execute was not supplied; no changes made.\n");
        return 0;
    }

    $lockName = substr($database . ':repair:fingerprint_template_slots', 0, 64);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        fwrite(STDERR, "ABORTED: could not acquire the repair lock; no changes made.\n");
        return 3;
    }

    try {
        // Repeat the probe after locking to avoid repairing a table whose state
        // changed between the initial check and execution confirmation.
        $lockedProbe = ucchr_probe_template_table($pdo);
        if ($lockedProbe['state'] !== 'engine_missing_1932') {
            fwrite(STDERR, "ABORTED: table state changed after preflight; no repair attempted.\n");
            return 3;
        }

        $result = ucchr_recreate_template_table($pdo);
        fwrite(
            STDOUT,
            "Repair complete: restored {$result['restored']} of {$result['canonical']} canonical CENTER mapping(s).\n"
        );
        fwrite(
            STDOUT,
            "Auxiliary LEFT/RIGHT/UPPER/LOWER mappings were not guessed; use controlled re-enrollment to recreate them.\n"
        );
        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, 'REPAIR FAILED: ' . $exception->getMessage() . "\n");
        return 4;
    } finally {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        } catch (Throwable) {
        }
    }
}

if (!defined('UCCHR_FINGERPRINT_REPAIR_LIBRARY_ONLY')) {
    exit(ucchr_fingerprint_repair_main($argv));
}
