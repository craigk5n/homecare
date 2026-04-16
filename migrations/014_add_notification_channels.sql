-- Migration 014: Per-user notification channel preference (HC-103)
-- BACKUP FIRST: ./dump.sh
--
-- Stores a JSON array of channel names the user has opted into
-- (e.g. `["ntfy","email"]`). Empty array (the default) means
-- "use the system default" — `ChannelResolver` reads this and
-- falls back to the registry's default list when the per-user
-- list is empty.
--
-- Portable MySQL 8+ (native JSON type) / SQLite 3.35+ (TEXT with
-- JSON encoded in application code).

-- MySQL 8 requires DEFAULT for TEXT to be an expression (parenthesised);
-- SQLite accepts the same syntax. Bare `DEFAULT '[]'` is rejected with
-- "BLOB, TEXT, GEOMETRY or JSON column can't have a default value".
ALTER TABLE hc_user
    ADD COLUMN notification_channels TEXT NOT NULL DEFAULT ('[]');
