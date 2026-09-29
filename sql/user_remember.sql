-- Keeps store members signed in across app restarts (home-screen web clip, closed Safari).
-- One row per signed-in device; the cookie holds "selector:validator", only the validator's hash is stored.
-- Rows past expires_at are removed automatically. Import once, after users.sql.
CREATE TABLE IF NOT EXISTS user_remember (
  selector       CHAR(24)     NOT NULL PRIMARY KEY,
  validator_hash CHAR(64)     NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  expires_at     DATETIME     NOT NULL,
  KEY idx_remember_user (user_id),
  KEY idx_remember_exp (expires_at),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
