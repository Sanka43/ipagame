-- Counters behind the per-IP / per-email limits in api.php (sign-in attempts, sign-ups, code emails).
-- Rows older than a day are removed automatically. Import once.
CREATE TABLE IF NOT EXISTS rate_hits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  k  VARCHAR(220) NOT NULL,
  t  DATETIME     NOT NULL,
  KEY idx_rate_k_t (k, t),
  KEY idx_rate_t (t)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
