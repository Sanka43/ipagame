-- "New release" push notifications no longer depend on the email tick. Import once on ipa_store,
-- after sql/push.sql. Each game is pushed once, when it is published (or when its scheduled time arrives).
ALTER TABLE games ADD COLUMN pushed_at DATETIME NULL;
-- Everything already published must not be announced now.
UPDATE games SET pushed_at = UTC_TIMESTAMP() WHERE status = 'published';
