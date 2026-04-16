-- Migration 013: Password reset tokens (HC-091)
-- BACKUP FIRST: ./dump.sh
--
-- Stores the SHA-256 hash of a single-use password-reset token.
-- The raw token lives only in the emailed link; lose the inbox and
-- you lose the token -- a DB read-only breach can't mint live
-- reset links.
--
--   token_hash   SHA-256 hex of the raw 32-byte token
--   user_login   the target account
--   created_at   for rate-limit counting (3/hour/login)
--   used_at      non-null once consumed; we mark before the
--                password write so a failed complete() can't
--                replay the same token
--   expires_at   hard TTL (60 min on the PasswordResetService
--                constant)
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_password_reset_tokens (
  token_hash  CHAR(64) NOT NULL PRIMARY KEY,
  user_login  VARCHAR(25) NOT NULL,
  created_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  expires_at  DATETIME NOT NULL
);

CREATE INDEX idx_prt_user_created
  ON hc_password_reset_tokens (user_login, created_at);
