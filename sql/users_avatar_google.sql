-- Profile photos (uploaded or from Google) and "Continue with Google". Run once, after users_disable.sql.
ALTER TABLE users
  ADD COLUMN avatar_url VARCHAR(500) NULL AFTER disabled_at,
  ADD COLUMN google_sub VARCHAR(64)  NULL AFTER avatar_url,
  ADD UNIQUE KEY uq_users_google (google_sub);
