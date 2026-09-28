-- Adds email verification to a users table created from the first version of users.sql.
-- Run once. Accounts that existed before this count as verified.
ALTER TABLE users
  ADD COLUMN email_verified_at DATETIME     NULL AFTER last_login_at,
  ADD COLUMN verify_code_hash  VARCHAR(255) NULL AFTER email_verified_at,
  ADD COLUMN verify_expires_at DATETIME     NULL AFTER verify_code_hash,
  ADD COLUMN verify_sent_at    DATETIME     NULL AFTER verify_expires_at,
  ADD COLUMN verify_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER verify_sent_at;

UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;
