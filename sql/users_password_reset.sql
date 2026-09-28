-- Adds forgot-password codes to a users table created before this feature. Run once
-- (after users_email_verify.sql if that one was needed too).
ALTER TABLE users
  ADD COLUMN reset_code_hash  VARCHAR(255) NULL AFTER verify_attempts,
  ADD COLUMN reset_expires_at DATETIME     NULL AFTER reset_code_hash,
  ADD COLUMN reset_sent_at    DATETIME     NULL AFTER reset_expires_at,
  ADD COLUMN reset_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reset_sent_at;
