<?php

declare(strict_types=1);

date_default_timezone_set(getenv('UCCHR_TIMEZONE') ?: 'Asia/Manila');

$dbHost = getenv('UCCHR_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('UCCHR_DB_PORT') ?: '3307';
$dbName = getenv('UCCHR_DB_NAME') ?: 'ucchr_system_recovered';
$dbUser = getenv('UCCHR_DB_USER') ?: 'root';
$dbPass = getenv('UCCHR_DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    // Keep hostnames, credentials, schema names, driver details, and local
    // paths out of browser responses. Administrators can inspect the PHP
    // server log for the exact PDO failure.
    error_log('[UCCHR database] Connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit("<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Database unavailable</title><style>body{font:16px system-ui;background:#f6f1f2;color:#171214;padding:4rem 1.25rem}.box{max-width:720px;margin:auto;background:#fff;border-radius:18px;padding:2rem;box-shadow:0 18px 60px #51182722}code{background:#f3e9eb;padding:.15rem .35rem;border-radius:5px;color:#a7192f}</style></head><body><div class='box'><h1>Database temporarily unavailable</h1><p>Start MySQL, then verify the configured database connection. If this follows an upgrade, use the ordered steps in <code>DEPLOYMENT.md</code>; never import the fresh-install schema over existing records.</p><p><small>The technical error was written to the PHP server log.</small></p></div></body></html>");
}
