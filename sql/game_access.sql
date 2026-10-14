-- Free / premium flag for every game and app. Premium games need a VIP membership to download.
-- A game with no row counts as free. Import once on ipa_store (after games exist);
-- every existing game starts out free.
CREATE TABLE IF NOT EXISTS game_access (
  game_id    INT UNSIGNED NOT NULL PRIMARY KEY,
  status     ENUM('free','premium') NOT NULL DEFAULT 'free',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_access_status (status),
  CONSTRAINT fk_access_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO game_access (game_id, status) SELECT id, 'free' FROM games;
