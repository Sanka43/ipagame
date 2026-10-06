-- "New app" emails to members. Run once on ipa_store (cPanel -> phpMyAdmin -> Import).
-- Then add the cron:  * * * * * php /home/USER/public_html/send_notifications.php   (set 'site_url' in config.php)

-- Members can opt out in their account page (and from the link in every email).
ALTER TABLE users ADD COLUMN notify_new_apps TINYINT(1) NOT NULL DEFAULT 1;

-- notify_email: the admin ticked "Email members when this goes live" (default 0 so games added from the
-- other site never trigger mail). notified_at: set when the emails for it were queued, so it is sent once.
ALTER TABLE games ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE games ADD COLUMN notified_at DATETIME NULL;
-- Everything already published must not be announced now.
UPDATE games SET notified_at = UTC_TIMESTAMP() WHERE status = 'published';

-- One row per email blast (a game announcement or a custom message).
CREATE TABLE IF NOT EXISTS mail_campaigns (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind       ENUM('game','custom') NOT NULL,
  game_id    INT UNSIGNED NULL,
  subject    VARCHAR(200) NOT NULL,
  body       TEXT NULL,
  total      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per recipient; the cron sends a few every minute.
CREATE TABLE IF NOT EXISTS mail_queue (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  status      ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sent_at     DATETIME NULL,
  UNIQUE KEY uq_queue (campaign_id, user_id),
  KEY idx_queue_status (status, id),
  CONSTRAINT fk_queue_campaign FOREIGN KEY (campaign_id) REFERENCES mail_campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
