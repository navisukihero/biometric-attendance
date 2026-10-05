-- UCC HR administrator authentication hardening (MariaDB 10.4 compatible)
-- Additive only: no account, password, attendance, or payroll data is changed.

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_auth_throttles (
    scope_type ENUM('Client','Account') NOT NULL,
    scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (scope_type, scope_hash),
    KEY idx_admin_auth_throttle_expiry (locked_until),
    KEY idx_admin_auth_throttle_updated (updated_at)
) ENGINE=InnoDB;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-14-admin-auth-security-v1',
    'Fail-closed CSRF regression coverage and persistent administrator authentication throttling'
)
ON DUPLICATE KEY UPDATE migration_key=VALUES(migration_key);

SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admin_auth_throttles') AS throttle_table,
    (SELECT COUNT(*) FROM schema_migrations
     WHERE migration_key='2026-09-14-admin-auth-security-v1') AS migration_marker;
