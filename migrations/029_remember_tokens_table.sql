-- Migration 029: Multi-device remember-me tokens
-- BACKUP FIRST: ./dump.sh
--
-- Moves remember-me tokens from a single column on hc_user into their
-- own table so multiple devices can hold concurrent sessions. Each row
-- is one device's token. Logout clears that device's row; "log out
-- everywhere" clears all rows for the user.
--
-- The old hc_user.remember_token / remember_token_expires columns are
-- left in place but ignored — removing them can happen in a later
-- cleanup migration once all code paths are verified.
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_remember_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_login VARCHAR(25) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  device_name VARCHAR(128) NULL,
  last_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_remember_token_hash (token_hash),
  KEY idx_remember_tokens_user (user_login)
);
