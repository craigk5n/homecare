-- Migration 017: Per-user weekly adherence digest opt-in (HC-107)
-- BACKUP FIRST: ./dump.sh
--
-- Separate from `email_notifications` because a caregiver might want
-- the low-frequency Monday digest without the real-time reminder
-- pings, or vice versa. Defaults OFF: the digest cron won't mail
-- anyone until they opt in explicitly.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_user
    ADD COLUMN digest_enabled CHAR(1) NOT NULL DEFAULT 'N';
