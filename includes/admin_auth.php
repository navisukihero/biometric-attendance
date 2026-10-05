<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Persistent administrator-authentication throttling.
 *
 * Two independent scopes are used for a known account: the connecting client
 * address and the immutable user id. Unknown usernames consume only the
 * client scope, which prevents an attacker from filling the table with one row
 * per invented username. No username, password, or IP address is stored.
 */
const ADMIN_AUTH_MAX_FAILED_ATTEMPTS = 5;
const ADMIN_AUTH_FAILURE_WINDOW_MINUTES = 15;
const ADMIN_AUTH_LOCK_MINUTES = 5;

function admin_auth_client_address(): string
{
    $address = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    if ($address !== 'unknown' && filter_var($address, FILTER_VALIDATE_IP) === false) {
        return 'unknown';
    }
    return strtolower($address);
}

/** @return array<int, array{type:string,hash:string}> */
function admin_auth_scopes(?int $userId = null): array
{
    $scopes = [[
        'type' => 'Client',
        'hash' => hash('sha256', 'client:' . admin_auth_client_address()),
    ]];
    if ($userId !== null && $userId > 0) {
        $scopes[] = [
            'type' => 'Account',
            'hash' => hash('sha256', 'account:' . $userId),
        ];
    }
    return $scopes;
}

/** @param array<int, array{type:string,hash:string}> $scopes */
function admin_auth_is_throttled(PDO $pdo, array $scopes): bool
{
    if (!$scopes) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM admin_auth_throttles
         WHERE scope_type=? AND scope_hash=?
           AND locked_until IS NOT NULL AND locked_until>NOW()'
    );
    foreach ($scopes as $scope) {
        $stmt->execute([$scope['type'], $scope['hash']]);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }
    }
    return false;
}

/**
 * Record one failed secret-verification attempt. Returns true when this
 * request reaches (or was already inside) the temporary lockout.
 *
 * @param array<int, array{type:string,hash:string}> $scopes
 */
function admin_auth_record_failure(PDO $pdo, array $scopes): bool
{
    if (!$scopes) {
        return false;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO admin_auth_throttles
                (scope_type, scope_hash, failed_attempts, window_started_at, locked_until, updated_at)
             VALUES (?, ?, 0, NOW(), NULL, NOW())'
        );
        $select = $pdo->prepare(
            'SELECT failed_attempts,
                    CASE WHEN locked_until IS NOT NULL AND locked_until>NOW() THEN 1 ELSE 0 END AS is_locked,
                    CASE WHEN window_started_at<=DATE_SUB(NOW(), INTERVAL '
                . ADMIN_AUTH_FAILURE_WINDOW_MINUTES . ' MINUTE) THEN 1 ELSE 0 END AS window_expired
             FROM admin_auth_throttles
             WHERE scope_type=? AND scope_hash=?
             FOR UPDATE'
        );
        $increment = $pdo->prepare(
            'UPDATE admin_auth_throttles
             SET failed_attempts=?,
                 window_started_at=IF(?, NOW(), window_started_at),
                 locked_until=NULL,
                 updated_at=NOW()
             WHERE scope_type=? AND scope_hash=?'
        );
        $lock = $pdo->prepare(
            'UPDATE admin_auth_throttles
             SET failed_attempts=0, window_started_at=NOW(),
                 locked_until=DATE_ADD(NOW(), INTERVAL ' . ADMIN_AUTH_LOCK_MINUTES . ' MINUTE),
                 updated_at=NOW()
             WHERE scope_type=? AND scope_hash=?'
        );

        $throttled = false;
        foreach ($scopes as $scope) {
            $insert->execute([$scope['type'], $scope['hash']]);
            $select->execute([$scope['type'], $scope['hash']]);
            $row = $select->fetch();
            if (!$row) {
                throw new RuntimeException('Administrator login throttle state could not be created.');
            }
            if ((int) $row['is_locked'] === 1) {
                $throttled = true;
                continue;
            }

            $windowExpired = (int) $row['window_expired'] === 1;
            $attempts = $windowExpired ? 1 : ((int) $row['failed_attempts'] + 1);
            if ($attempts >= ADMIN_AUTH_MAX_FAILED_ATTEMPTS) {
                $lock->execute([$scope['type'], $scope['hash']]);
                $throttled = true;
                continue;
            }
            $increment->execute([
                $attempts,
                $windowExpired ? 1 : 0,
                $scope['type'],
                $scope['hash'],
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $throttled;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @param array<int, array{type:string,hash:string}> $scopes */
function admin_auth_clear_failures(PDO $pdo, array $scopes): void
{
    if (!$scopes) {
        return;
    }
    $stmt = $pdo->prepare(
        'DELETE FROM admin_auth_throttles WHERE scope_type=? AND scope_hash=?'
    );
    foreach ($scopes as $scope) {
        $stmt->execute([$scope['type'], $scope['hash']]);
    }
}
