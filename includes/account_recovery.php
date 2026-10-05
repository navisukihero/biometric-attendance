<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Staged administrator password recovery.
 *
 * The account must be found by its exact username/email and its current
 * password must be verified before a different password can be stored.
 */

const ACCOUNT_RECOVERY_FLOW_TTL_SECONDS = 600;
const ACCOUNT_RECOVERY_MAX_OLD_PASSWORD_ATTEMPTS = 5;

function account_recovery_find_user(PDO $pdo, string $identifier): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, full_name, username, email
         FROM users
         WHERE username = ? OR email = ?
         ORDER BY id
         LIMIT 2'
    );
    $stmt->execute([$identifier, $identifier]);
    $users = $stmt->fetchAll();

    // A value matching one account's username and another account's email is
    // ambiguous and must never select an arbitrary user.
    return count($users) === 1 ? $users[0] : null;
}

function account_recovery_credentials(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, username, email, password_hash
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return is_array($user) ? $user : null;
}

function account_recovery_password_fingerprint(string $passwordHash): string
{
    return hash('sha256', $passwordHash);
}

/**
 * Replace a password only while the credential hash is still the exact value
 * approved during the current-password step. This prevents a stale browser
 * flow from overwriting a password changed by another request.
 */
function account_recovery_update_verified_password(
    PDO $pdo,
    int $userId,
    string $expectedFingerprint,
    string $newPassword
): bool {
    if ($userId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint)) {
        return false;
    }
    $passwordBytes = strlen($newPassword);
    if ($passwordBytes < 8) {
        throw new InvalidArgumentException('NEW_PASSWORD_TOO_SHORT');
    }
    if ($passwordBytes > 72) {
        throw new InvalidArgumentException('NEW_PASSWORD_TOO_LONG');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, password_hash
             FROM users
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || !hash_equals(
            $expectedFingerprint,
            account_recovery_password_fingerprint((string) $user['password_hash'])
        )) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        if (ucchr_password_verify($newPassword, (string) $user['password_hash'])) {
            throw new DomainException('NEW_PASSWORD_MATCHES_CURRENT');
        }

        $newHash = ucchr_password_hash($newPassword);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, $userId]);
        $pdo->prepare(
            'INSERT INTO activity_logs (user_id, action, created_at)
             VALUES (?, ?, NOW())'
        )->execute([$userId, 'Changed password after verifying the current password']);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
