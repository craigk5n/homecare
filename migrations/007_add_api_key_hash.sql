-- Migration 007: Add api_key_hash column to hc_user (HC-030)
-- BACKUP FIRST: ./dump.sh
--
-- Stores the SHA-256 hash of a user's API bearer token. Generating a
-- key returns the raw value to the browser once; only the hash lives
-- in the DB so a read-only breach can't hand out live tokens.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_user ADD COLUMN api_key_hash VARCHAR(255) NULL;
