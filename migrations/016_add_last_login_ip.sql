-- Migration 016: Last-login IP for HC-106 security notifications
-- BACKUP FIRST: ./dump.sh
--
-- Stores the IP the account last successfully logged in from. The
-- security-event notifier compares the current request IP against
-- this column on every login success and emails the account owner
-- when it changes (so a login from an unfamiliar host is visible
-- out-of-band).
--
-- VARCHAR(45) is wide enough for an IPv4-mapped IPv6 address
-- (`::ffff:192.0.2.1`) which is the longest textual form in common
-- use.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_user ADD COLUMN last_login_ip VARCHAR(45) NULL;
