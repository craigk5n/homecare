-- Migration 006: Login rate limiting (HC-014)
-- BACKUP FIRST: ./dump.sh
--
-- Tracks consecutive failed login attempts per user. Five failures in
-- a row lock the account for 15 minutes; a successful login clears
-- both fields.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_user ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0;
ALTER TABLE hc_user ADD COLUMN locked_until DATETIME NULL;
