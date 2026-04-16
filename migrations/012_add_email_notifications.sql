-- Migration 012: Add email_notifications opt-in flag to hc_user (HC-101)
-- BACKUP FIRST: ./dump.sh
--
-- Per-user opt-in for reminder emails. Default 'N' so no caregiver gets
-- email blasted at them after the SMTP transport goes live until they
-- explicitly toggle it on. Password-reset emails (HC-091) bypass this
-- flag because the user needs the mail to log back in.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_user ADD COLUMN email_notifications CHAR(1) NOT NULL DEFAULT 'N';
