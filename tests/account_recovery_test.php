<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/account_recovery.php';

function recovery_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
        );
    }
}

function recovery_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo->beginTransaction();

try {
    $suffix = bin2hex(random_bytes(5));
    $helperPassword = 'Helper-Password-' . $suffix;
    $helperHash = ucchr_password_hash($helperPassword);
    recovery_assert(ucchr_password_hash_is_valid($helperHash), 'Generated password hash must be recognized');
    recovery_assert(ucchr_password_verify($helperPassword, $helperHash), 'Generated password hash must verify its password');
    recovery_assert($helperHash !== $helperPassword, 'Plain-text password must never be stored as its hash');
    recovery_assert(!ucchr_password_hash_is_valid(''), 'An empty password hash must be rejected');
    recovery_assert(!ucchr_password_hash_is_valid($helperPassword), 'A plain-text password must not be accepted as a hash');

    $username = 'verified-recovery-' . $suffix;
    $email = $username . '@example.test';
    $oldPassword = 'Verified-Old-' . $suffix;
    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, username, email, password_hash, role)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        'Verified Recovery Test',
        $username,
        $email,
        password_hash($oldPassword, PASSWORD_DEFAULT),
        'Administrator',
    ]);
    $userId = (int) $pdo->lastInsertId();

    recovery_assert_same($userId, (int) (account_recovery_find_user($pdo, $email)['id'] ?? 0), 'Email must resolve its account');
    recovery_assert_same($userId, (int) (account_recovery_find_user($pdo, $username)['id'] ?? 0), 'Username must resolve its account');
    recovery_assert_same(null, account_recovery_find_user($pdo, 'missing-' . $suffix), 'Unknown identifier must not resolve');
    recovery_assert_same(null, account_recovery_find_user($pdo, "' OR 1=1 --"), 'SQL-like input must not resolve an account');

    $credentials = account_recovery_credentials($pdo, $userId);
    recovery_assert($credentials !== null, 'Verified-flow account must exist');
    recovery_assert(!ucchr_password_verify('Wrong-Password', (string) $credentials['password_hash']), 'Wrong current password must fail');
    recovery_assert(ucchr_password_verify($oldPassword, (string) $credentials['password_hash']), 'Correct current password must verify');
    $fingerprint = account_recovery_password_fingerprint((string) $credentials['password_hash']);

    $samePasswordRejected = false;
    try {
        account_recovery_update_verified_password($pdo, $userId, $fingerprint, $oldPassword);
    } catch (DomainException $exception) {
        $samePasswordRejected = $exception->getMessage() === 'NEW_PASSWORD_MATCHES_CURRENT';
    }
    recovery_assert($samePasswordRejected, 'New password must differ from the verified current password');

    $newPassword = 'Verified-New-' . $suffix;
    recovery_assert(
        account_recovery_update_verified_password($pdo, $userId, $fingerprint, $newPassword),
        'Verified current hash must allow one atomic password update'
    );
    $newHash = $pdo->query('SELECT password_hash FROM users WHERE id=' . $userId)->fetchColumn();
    recovery_assert(ucchr_password_verify($newPassword, (string) $newHash), 'New password must work');
    recovery_assert(!ucchr_password_verify($oldPassword, (string) $newHash), 'Old password must stop working');
    recovery_assert_same(
        false,
        account_recovery_update_verified_password($pdo, $userId, $fingerprint, 'Stale-Request-' . $suffix),
        'A stale verified fingerprint must not overwrite a newer password'
    );
    $activity = $pdo->prepare('SELECT COUNT(*) FROM activity_logs WHERE user_id=? AND action="Changed password after verifying the current password"');
    $activity->execute([$userId]);
    recovery_assert_same(1, (int) $activity->fetchColumn(), 'Password update must create one activity log');

    echo "account_recovery_test: PASS\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
