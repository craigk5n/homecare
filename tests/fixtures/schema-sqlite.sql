-- SQLite port of tables-mysql.sql for in-memory test databases.
-- Changes from the MySQL original:
--   * INT AUTO_INCREMENT PRIMARY KEY  -> INTEGER PRIMARY KEY AUTOINCREMENT
--   * BOOLEAN NOT NULL DEFAULT TRUE   -> INTEGER NOT NULL DEFAULT 1
--   * DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP -> SQLite has no
--     ON UPDATE clause; callers update updated_at explicitly. DEFAULT CURRENT_TIMESTAMP
--     is preserved so inserts without the column still populate it.
--   * Backticks are retained -- SQLite accepts them as identifier quotes.
-- Keep this file structurally aligned with tables-mysql.sql; when adding a
-- column to production, mirror it here so integration tests see the same shape.

CREATE TABLE hc_user (
  login VARCHAR(25) NOT NULL,
  passwd VARCHAR(255),
  lastname VARCHAR(25),
  firstname VARCHAR(25),
  is_admin CHAR(1) DEFAULT 'N',
  role VARCHAR(20) NOT NULL DEFAULT 'caregiver',
  email VARCHAR(75) NULL,
  enabled CHAR(1) DEFAULT 'Y',
  telephone VARCHAR(50) NULL,
  address VARCHAR(75) NULL,
  title VARCHAR(75) NULL,
  birthday INTEGER NULL,
  last_login INTEGER NULL,
  remember_token VARCHAR(64) NULL,
  remember_token_expires DATETIME NULL,
  failed_attempts INTEGER NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  api_key_hash VARCHAR(255) NULL,
  totp_secret VARCHAR(64) NULL,
  totp_enabled CHAR(1) NOT NULL DEFAULT 'N',
  totp_recovery_codes TEXT NULL,
  email_notifications CHAR(1) NOT NULL DEFAULT 'N',
  notification_channels TEXT NOT NULL DEFAULT ('[]'),
  last_login_ip VARCHAR(45) NULL,
  digest_enabled CHAR(1) NOT NULL DEFAULT 'N',
  language VARCHAR(32) NULL,
  PRIMARY KEY (login)
);

CREATE TABLE hc_password_reset_tokens (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_login VARCHAR(25) NOT NULL,
  created_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  expires_at DATETIME NOT NULL
);
CREATE INDEX idx_prt_user_created
  ON hc_password_reset_tokens (user_login, created_at);

CREATE TABLE `hc_patients` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `species` VARCHAR(32) NULL,
  `weight_kg` DECIMAL(6,2) NULL,
  `weight_as_of` DATE NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `is_active` INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE hc_drug_catalog (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  rxnorm_id INT NULL,
  ndc VARCHAR(13) NULL,
  name VARCHAR(255) NOT NULL,
  strength VARCHAR(128) NULL,
  dosage_form VARCHAR(128) NULL,
  ingredient_names TEXT NULL,
  generic CHAR(1) NOT NULL DEFAULT 'N',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX idx_drug_catalog_rxnorm ON hc_drug_catalog (rxnorm_id);
CREATE INDEX idx_drug_catalog_name ON hc_drug_catalog (name);
CREATE INDEX idx_drug_catalog_ndc ON hc_drug_catalog (ndc);

CREATE TABLE hc_drug_interactions (
  ingredient_a VARCHAR(64) NOT NULL,
  ingredient_b VARCHAR(64) NOT NULL,
  severity VARCHAR(10) NOT NULL DEFAULT 'minor',
  description TEXT NULL,
  PRIMARY KEY (ingredient_a, ingredient_b)
);
CREATE INDEX idx_drug_interactions_b ON hc_drug_interactions (ingredient_b);

CREATE TABLE hc_attachments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  owner_type VARCHAR(16) NOT NULL,
  owner_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(64) NOT NULL,
  size_bytes INT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  uploaded_by VARCHAR(25) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_attachments_owner ON hc_attachments (owner_type, owner_id);

CREATE TABLE `hc_medicines` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `dosage` VARCHAR(255) NOT NULL,
  `drug_catalog_id` INTEGER NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`drug_catalog_id`) REFERENCES `hc_drug_catalog`(`id`) ON DELETE SET NULL
);

CREATE TABLE `hc_medicine_inventory` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `medicine_id` INTEGER NOT NULL,
  `quantity` DECIMAL(10, 2) NOT NULL,
  `current_stock` DECIMAL(10, 2) NOT NULL,
  `recorded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `note` TEXT,
  FOREIGN KEY (`medicine_id`) REFERENCES `hc_medicines`(`id`) ON DELETE CASCADE
);

CREATE TABLE `hc_medicine_schedules` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `patient_id` INTEGER NOT NULL,
  `medicine_id` INTEGER NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE,
  `frequency` VARCHAR(255) NULL,
  `unit_per_dose` DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
  `is_prn` CHAR(1) NOT NULL DEFAULT 'N',
  `dose_basis` VARCHAR(10) NOT NULL DEFAULT 'fixed',
  `cycle_on_days` INTEGER NULL,
  `cycle_off_days` INTEGER NULL,
  `wall_clock_times` VARCHAR(128) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `hc_patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`medicine_id`) REFERENCES `hc_medicines`(`id`) ON DELETE CASCADE
);

CREATE TABLE `hc_medicine_intake` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `schedule_id` INTEGER NOT NULL,
  `taken_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `note` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`schedule_id`) REFERENCES `hc_medicine_schedules`(`id`) ON DELETE CASCADE
);

CREATE TABLE `hc_caregiver_notes` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `patient_id` INTEGER NOT NULL,
  `note` TEXT NOT NULL,
  `note_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `hc_patients`(`id`) ON DELETE CASCADE
);

CREATE TABLE hc_config (
  setting VARCHAR(50) NOT NULL,
  value VARCHAR(128) NULL,
  PRIMARY KEY (setting)
);

CREATE TABLE hc_supply_alert_log (
  medicine_id INTEGER NOT NULL PRIMARY KEY,
  last_sent_at DATETIME NOT NULL
);

CREATE TABLE hc_late_dose_alert_log (
  schedule_id INTEGER NOT NULL PRIMARY KEY,
  last_due_at DATETIME NOT NULL,
  sent_at DATETIME NOT NULL
);

CREATE TABLE hc_schedule_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  schedule_id INTEGER NOT NULL,
  start_date DATE NOT NULL,
  unit_per_dose DECIMAL(10,3) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (schedule_id) REFERENCES hc_medicine_schedules(id) ON DELETE CASCADE
);
CREATE INDEX idx_schedule_steps_sched ON hc_schedule_steps (schedule_id, start_date);

CREATE TABLE hc_schedule_pauses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  schedule_id INTEGER NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (schedule_id) REFERENCES hc_medicine_schedules(id) ON DELETE CASCADE
);
CREATE INDEX idx_schedule_pauses_sched ON hc_schedule_pauses (schedule_id, start_date);

CREATE TABLE hc_audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_login VARCHAR(25) NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NULL,
  entity_id INTEGER NULL,
  details TEXT NULL,
  ip_address VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_hc_audit_log_user ON hc_audit_log (user_login, created_at);
CREATE INDEX idx_hc_audit_log_entity ON hc_audit_log (entity_type, entity_id);
