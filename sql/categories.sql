-- Store categories managed from the admin panel's Categories tab (name, emoji, colours). Built-in
-- categories still work without a row. Run once on ipa_store.
CREATE TABLE IF NOT EXISTS store_categories (
  slug       VARCHAR(60)  NOT NULL PRIMARY KEY,
  type       VARCHAR(10)  NOT NULL DEFAULT 'game',
  label      VARCHAR(60)  NOT NULL,
  emoji      VARCHAR(16)  NOT NULL DEFAULT '',
  color1     VARCHAR(7)   NOT NULL DEFAULT '',
  color2     VARCHAR(7)   NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
