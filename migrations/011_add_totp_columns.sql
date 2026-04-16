-- Migration 011: Add TOTP 2FA columns to hc_user (HC-090)
-- BACKUP FIRST: ./dump.sh
--
-- `totp_secret`          — Base32 TOTP seed (RFC 6238 / RFC 4648). Null when
--                          the user has not enrolled.
-- `totp_enabled`         — 'Y' only after the user verified their first code
--                          during enrollment. Login flow reads this to decide
--                          whether to demand a second factor.
-- `totp_recovery_codes`  — JSON array of SHA-256 hashes of single-use recovery
--                          codes. On use, the hash is popped from the list.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_user ADD COLUMN totp_secret VARCHAR(64) NULL;
ALTER TABLE hc_user ADD COLUMN totp_enabled CHAR(1) NOT NULL DEFAULT 'N';
ALTER TABLE hc_user ADD COLUMN totp_recovery_codes TEXT NULL;
