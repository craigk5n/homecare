-- Migration 005: Audit log for write operations (HC-013)
-- BACKUP FIRST: ./dump.sh
--
-- Records who performed each significant action, when, and against what
-- entity. `details` is a JSON blob so handlers can attach arbitrary
-- per-action context without schema churn. Indexed on (user_login,
-- created_at) and (entity_type, entity_id) because the two dominant
-- queries are "what did X do?" and "what happened to this row?".
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_login VARCHAR(25) NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NULL,
  entity_id INT NULL,
  details TEXT NULL,
  ip_address VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_hc_audit_log_user ON hc_audit_log (user_login, created_at);
CREATE INDEX idx_hc_audit_log_entity ON hc_audit_log (entity_type, entity_id);
