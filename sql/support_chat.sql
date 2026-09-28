-- Live support chat: one conversation per member with the admin. Import once (after users.sql).
-- A user's chat is removed with their account (ON DELETE CASCADE).
CREATE TABLE IF NOT EXISTS support_threads (
  user_id         INT UNSIGNED NOT NULL PRIMARY KEY,
  status          ENUM('open', 'closed') NOT NULL DEFAULT 'open',
  last_message_at DATETIME     NOT NULL,
  -- Highest message id each side has seen (unread = messages from the other side above it).
  user_read_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  admin_read_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_threads_last (last_message_at),
  CONSTRAINT fk_threads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  from_admin TINYINT(1)   NOT NULL DEFAULT 0,
  body       TEXT         NOT NULL,
  created_at DATETIME     NOT NULL,
  KEY idx_messages_user (user_id, id),
  CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
