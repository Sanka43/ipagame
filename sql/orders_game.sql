-- Remembers which game a member was trying to download when they became VIP (shown in the admin
-- Payments tab). Run once on ipa_store if sql/payments.sql was imported before this file existed.
ALTER TABLE orders
  ADD COLUMN game_id   INT UNSIGNED NULL AFTER kind,
  ADD COLUMN game_name VARCHAR(255) NULL AFTER game_id;
