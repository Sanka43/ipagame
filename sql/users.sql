-- Store user accounts (login / register). Import once into the shared ipa_store database,
-- together with rate_limits.sql (sign-in / email limits).
-- Already imported an earlier version? Import only the files you are missing instead:
--   users_email_verify.sql (email verification), users_password_reset.sql (forgot password),
--   users_disable.sql (admin can disable accounts), then users_avatar_google.sql (photos, Google).
CREATE TABLE IF NOT EXISTS users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username          VARCHAR(30)  NOT NULL,
  email             VARCHAR(190) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  created_at        DATETIME     NOT NULL,
  last_login_at     DATETIME     NULL,
  -- Email verification: a 6-digit code (stored hashed) that expires, with a wrong-guess counter.
  email_verified_at DATETIME     NULL,
  verify_code_hash  VARCHAR(255) NULL,
  verify_expires_at DATETIME     NULL,
  verify_sent_at    DATETIME     NULL,
  verify_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  -- Forgot password: same idea, separate code.
  reset_code_hash   VARCHAR(255) NULL,
  reset_expires_at  DATETIME     NULL,
  reset_sent_at     DATETIME     NULL,
  reset_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  -- Set by an admin to block sign-in (and sign out any open session).
  disabled_at       DATETIME     NULL,
  -- Own profile photo: an uploads/avatars/ path or a Google photo URL (NULL = Gravatar).
  avatar_url        VARCHAR(500) NULL,
  -- Google account ID for "Continue with Google".
  google_sub        VARCHAR(64)  NULL,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_google (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
