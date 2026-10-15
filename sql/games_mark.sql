-- Colour marks on the admin panel's games list (red / yellow / green / blue). Import once on ipa_store.
ALTER TABLE games ADD COLUMN admin_mark VARCHAR(10) NULL;
