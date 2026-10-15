-- Push notifications to phones (Home Screen web app). Import once on ipa_store (cPanel -> phpMyAdmin -> Import).
-- A new release flagged "Email members when this goes live" is then also pushed to every subscribed phone.

-- One row per phone/browser that allowed notifications.
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64)     NOT NULL,
  endpoint      TEXT         NOT NULL,
  p256dh        VARCHAR(255) NOT NULL,
  auth          VARCHAR(100) NOT NULL,
  created_at    DATETIME     NOT NULL,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The site's VAPID key pair (created automatically on first use).
CREATE TABLE IF NOT EXISTS push_keys (
  k VARCHAR(20) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
