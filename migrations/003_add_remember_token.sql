-- Migration 003: Add remember-me token columns to hc_user
-- BACKUP FIRST: ./dump.sh
--
-- Adds `remember_token` (opaque random value stored hashed) and
-- `remember_token_expires` (absolute expiry DATETIME). Login's
-- "remember me" flow generates a random token, stores the SHA-256 hash
-- here, and drops a cookie with the raw token. Subsequent requests
-- look up the user by hashed token, so a DB leak doesn't directly
-- hand out sessions.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_user ADD COLUMN remember_token VARCHAR(64) NULL;
ALTER TABLE hc_user ADD COLUMN remember_token_expires DATETIME NULL;
