-- Migration 028: Per-user language preference (HC-141)
-- BACKUP FIRST: ./dump.sh
--
-- Stores the user's preferred language. Value matches the translation
-- filename stem (e.g. 'Spanish', 'Portuguese-BR'). NULL means use the
-- system default (English-US).
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_user ADD COLUMN language VARCHAR(32) NULL;
