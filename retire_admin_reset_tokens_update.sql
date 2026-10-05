-- Retire the unused administrator email/reset-token store.
-- Administrator recovery now requires username/email plus the current password.
-- Employee one-time setup/reset links use employee_account_tokens and are not
-- changed by this migration.

SET NAMES utf8mb4;

DROP TABLE IF EXISTS password_reset_tokens;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-06-retire-admin-reset-tokens-v1',
    'Remove unused administrator reset-token storage; retain verified current-password recovery'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);
