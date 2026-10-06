-- One row each time a signed-in member taps Download on a game page (shown in the admin panel's
-- Downloads tab). The game name and version are copied so history survives later edits/deletes.
-- Removed with the member's account. Import once (after users.sql) on ipa_store.
CREATE TABLE IF NOT EXISTS downloads (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  game_id    INT UNSIGNED NULL,
  game_name  VARCHAR(255) NOT NULL,
  version    VARCHAR(40)  NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL,
  KEY idx_dl_user (user_id, id),
  KEY idx_dl_created (created_at),
  CONSTRAINT fk_dl_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
