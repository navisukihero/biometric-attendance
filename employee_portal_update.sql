-- Employee self-service portal accounts and one-time setup/reset tokens.
-- MariaDB 10.4 compatible. This migration is non-destructive and safe to
-- run more than once against the database selected by the MySQL client.
-- It intentionally does not hard-code a database name.

CREATE TABLE IF NOT EXISTS employee_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NULL,
    account_status ENUM('Pending','Active','Disabled') NOT NULL DEFAULT 'Pending',
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login DATETIME NULL,
    password_changed_at DATETIME NULL,
    reset_requested_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_account_employee (employee_id),
    UNIQUE KEY uq_employee_account_username (username),
    KEY idx_employee_account_status (account_status),
    KEY idx_employee_account_created_by (created_by),
    CONSTRAINT fk_employee_account_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_account_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_account_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_account_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('Activation','Reset') NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employee_account_token_hash (token_hash),
    KEY idx_employee_account_token_lookup (employee_account_id, purpose, used_at, expires_at),
    KEY idx_employee_account_token_creator (created_by),
    CONSTRAINT fk_employee_account_token_account
        FOREIGN KEY (employee_account_id) REFERENCES employee_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_employee_account_token_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every existing employee receives a pending portal account. The employee
-- number is the initial unique username; no password is generated or stored.
-- A password must be created through a one-time activation/reset token.
INSERT INTO employee_accounts
    (employee_id, username, password_hash, account_status, must_change_password)
SELECT
    e.id,
    e.employee_no,
    NULL,
    'Pending',
    1
FROM employees e
LEFT JOIN employee_accounts ea ON ea.employee_id = e.id
WHERE ea.id IS NULL
ON DUPLICATE KEY UPDATE
    employee_id = employee_accounts.employee_id;
