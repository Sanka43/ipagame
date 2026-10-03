-- Lets the admin panel schedule a release: a draft with publish_at goes live (published) once that
-- UTC time has passed. Run once on ipa_store.
ALTER TABLE games ADD COLUMN publish_at DATETIME NULL;
