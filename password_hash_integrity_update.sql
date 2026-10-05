-- Prevent blank or plain-text values from replacing secure password hashes.
-- Run this against the database selected by the MySQL client.

SET @password_hash_check_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND CONSTRAINT_NAME = 'chk_users_password_hash'
      AND CONSTRAINT_TYPE = 'CHECK'
);

SET @password_hash_check_sql = IF(
    @password_hash_check_exists = 0,
    'ALTER TABLE users ADD CONSTRAINT chk_users_password_hash CHECK (CHAR_LENGTH(password_hash) BETWEEN 20 AND 255 AND LEFT(password_hash, 1) = ''$'')',
    'SELECT 1'
);

PREPARE password_hash_check_statement FROM @password_hash_check_sql;
EXECUTE password_hash_check_statement;
DEALLOCATE PREPARE password_hash_check_statement;
