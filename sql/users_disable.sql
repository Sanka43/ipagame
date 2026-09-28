-- Lets the admin panel disable accounts. Run once, after users_password_reset.sql.
ALTER TABLE users ADD COLUMN disabled_at DATETIME NULL AFTER reset_attempts;
