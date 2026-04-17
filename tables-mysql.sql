CREATE TABLE hc_user (
  /* the unique user login */
  login VARCHAR(25) NOT NULL,
  /* the user's password. (not used for http) */
  passwd VARCHAR(255),
  /* user's last name */
  lastname VARCHAR(25),
  /* user's first name */
  firstname VARCHAR(25),
  /* is the user a HomeCare administrator ('Y' = yes, 'N' = no) -- legacy flag, see role */
  is_admin CHAR(1) DEFAULT 'N',
  /* role-based access: 'admin', 'caregiver', or 'viewer' */
  role VARCHAR(20) NOT NULL DEFAULT 'caregiver',
  /* user's email address */
  email VARCHAR(75) NULL,
  /* allow admin to disable account ('Y' = yes, 'N' = no) */
  enabled CHAR(1) DEFAULT 'Y',
  /* user's telephone */
  telephone VARCHAR(50) NULL,
  /* user's address */
  address VARCHAR(75) NULL,
  /* user's title */
  title VARCHAR(75) NULL,
  /* user's birthday */
  birthday INT NULL,
  /* user's last log in date */
  last_login INT NULL,
  /* remember-me: SHA-256 hash of the random token in the cookie (HC-auth) */
  remember_token VARCHAR(64) NULL,
  /* remember-me expiry (absolute) */
  remember_token_expires DATETIME NULL,
  /* consecutive failed logins; reset on success (HC-014) */
  failed_attempts INT NOT NULL DEFAULT 0,
  /* lockout expiry; NULL when not locked (HC-014) */
  locked_until DATETIME NULL,
  /* SHA-256 hash of the user's API bearer token; NULL if none (HC-030) */
  api_key_hash VARCHAR(255) NULL,
  /* Base32 TOTP seed, null when not enrolled (HC-090) */
  totp_secret VARCHAR(64) NULL,
  /* Y only after first-code verification during enrollment (HC-090) */
  totp_enabled CHAR(1) NOT NULL DEFAULT 'N',
  /* JSON array of SHA-256 hashes of single-use recovery codes (HC-090) */
  totp_recovery_codes TEXT NULL,
  /* Per-user opt-in for reminder emails (HC-101); 'N' default */
  email_notifications CHAR(1) NOT NULL DEFAULT 'N',
  /* Per-user channel preference: JSON array of channel names, e.g.
   * ["ntfy","email"]. Empty array ⇒ fall back to system default (HC-103). */
  notification_channels TEXT NOT NULL DEFAULT ('[]'),
  /* Last IP we saw on a successful login (HC-106 new-IP alerts).
   * VARCHAR(45) is wide enough for IPv4-mapped IPv6. */
  last_login_ip VARCHAR(45) NULL,
  /* Weekly adherence-digest opt-in (HC-107); 'N' default */
  digest_enabled CHAR(1) NOT NULL DEFAULT 'N',
  /* Per-user language preference (HC-141); NULL = system default (English-US) */
  language VARCHAR(32) NULL,
  PRIMARY KEY (login)
);

/* HC-091: password reset tokens (hashed; raw token only in the email) */
CREATE TABLE hc_password_reset_tokens (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_login VARCHAR(25) NOT NULL,
  created_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_prt_user_created (user_login, created_at)
);

/* Multi-device remember-me tokens (HC-141 follow-up).
   Each row = one device's remember-me cookie. */
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

CREATE TABLE `hc_patients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `species` VARCHAR(32) NULL,
  `weight_kg` DECIMAL(6,2) NULL,
  `weight_as_of` DATE NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE
);

/* Standardised drug lookup populated from RxNorm RRF dumps (HC-110).
   Caregivers pick from this catalog when adding medications; the selected
   entry pre-fills name, strength, and dosage form. Free-text entries
   remain supported (hc_medicines.drug_catalog_id is nullable). */
CREATE TABLE hc_drug_catalog (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rxnorm_id INT NULL,
  ndc VARCHAR(13) NULL,
  name VARCHAR(255) NOT NULL,
  strength VARCHAR(128) NULL,
  dosage_form VARCHAR(128) NULL,
  ingredient_names TEXT NULL,
  generic CHAR(1) NOT NULL DEFAULT 'N',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY idx_drug_catalog_rxnorm (rxnorm_id),
  KEY idx_drug_catalog_name (name),
  KEY idx_drug_catalog_ndc (ndc)
);

/* Ingredient-level drug interaction pairs (HC-112). Each row is one
   known interaction. Ingredients are alphabetically ordered so each
   pair is stored once. */
CREATE TABLE hc_drug_interactions (
  ingredient_a VARCHAR(64) NOT NULL,
  ingredient_b VARCHAR(64) NOT NULL,
  severity VARCHAR(10) NOT NULL DEFAULT 'minor',
  description TEXT NULL,
  PRIMARY KEY (ingredient_a, ingredient_b),
  KEY idx_drug_interactions_b (ingredient_b)
);

/* Photo / document attachments (HC-130). Files live on disk under
   data/attachments/<sha256[:2]>/<sha256>; this table stores metadata. */
CREATE TABLE hc_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_type VARCHAR(16) NOT NULL,
  owner_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(64) NOT NULL,
  size_bytes INT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  uploaded_by VARCHAR(25) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_attachments_owner (owner_type, owner_id)
);

/* Product catalog: the physical item you buy (name + strength/form).
   Prescription details (frequency, unit_per_dose) live on hc_medicine_schedules. */
CREATE TABLE `hc_medicines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `dosage` VARCHAR(255) NOT NULL,
  `drug_catalog_id` INT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`drug_catalog_id`) REFERENCES `hc_drug_catalog`(`id`) ON DELETE SET NULL
);

CREATE TABLE `hc_medicine_inventory` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `medicine_id` INT NOT NULL,
  `quantity` DECIMAL(10, 2) NOT NULL,
  `current_stock` DECIMAL(10, 2) NOT NULL,
  `recorded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `note` TEXT,
  FOREIGN KEY (`medicine_id`) REFERENCES `hc_medicines`(`id`) ON DELETE CASCADE
);


/* Prescription: links a product to a patient with dosing instructions.
   unit_per_dose is authoritative here (not on hc_medicines).
   frequency is NULL for PRN ("as-needed") schedules (HC-120). */
CREATE TABLE `hc_medicine_schedules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `medicine_id` INT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE,
  `frequency` VARCHAR(255) NULL,
  `unit_per_dose` DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
  `is_prn` CHAR(1) NOT NULL DEFAULT 'N',
  `dose_basis` VARCHAR(10) NOT NULL DEFAULT 'fixed',
  `cycle_on_days` INT NULL,
  `cycle_off_days` INT NULL,
  `wall_clock_times` VARCHAR(128) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `hc_patients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`medicine_id`) REFERENCES `hc_medicines`(`id`) ON DELETE CASCADE
);

CREATE TABLE `hc_medicine_intake` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `schedule_id` INT NOT NULL,
  `taken_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `note` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`schedule_id`) REFERENCES `hc_medicine_schedules`(`id`) ON DELETE CASCADE
);

CREATE TABLE `hc_caregiver_notes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `note` TEXT NOT NULL,
  `note_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`patient_id`) REFERENCES `hc_patients`(`id`) ON DELETE CASCADE
);

CREATE TABLE hc_config (
  /* setting name */
  setting VARCHAR(50) NOT NULL,
  /* setting value */
  value VARCHAR(128) NULL,
  PRIMARY KEY (setting)
);

/* Low-supply alert throttle (HC-040). One row per medicine; updated in place. */
CREATE TABLE hc_supply_alert_log (
  medicine_id INT NOT NULL PRIMARY KEY,
  last_sent_at DATETIME NOT NULL
);

/* Late-dose alert throttle (HC-105). One row per schedule; `last_due_at`
 * is the due instant we last alerted about so we don't re-fire for the
 * same miss while still re-arming on the next dose. */
CREATE TABLE hc_late_dose_alert_log (
  schedule_id INT NOT NULL PRIMARY KEY,
  last_due_at DATETIME NOT NULL,
  sent_at     DATETIME NOT NULL
);

/* Step / taper dosing (HC-122). Models dose-level changes within a
   single schedule. Zero rows = use schedule.unit_per_dose. One or more
   rows = latest step whose start_date <= reference date wins. */
CREATE TABLE hc_schedule_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL,
  start_date DATE NOT NULL,
  unit_per_dose DECIMAL(10,3) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (schedule_id) REFERENCES hc_medicine_schedules(id) ON DELETE CASCADE
);
CREATE INDEX idx_schedule_steps_sched ON hc_schedule_steps (schedule_id, start_date);

/* Schedule pause / skip-today (HC-124). A pause suspends a schedule's
   cadence for a date range: no doses expected, no reminders, adherence
   counts the paused days as excused. end_date NULL = open-ended. */
CREATE TABLE hc_schedule_pauses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (schedule_id) REFERENCES hc_medicine_schedules(id) ON DELETE CASCADE
);
CREATE INDEX idx_schedule_pauses_sched ON hc_schedule_pauses (schedule_id, start_date);

/* Audit log of write operations (HC-013). `details` is a JSON blob. */
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

CREATE TABLE hc_webhook_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id VARCHAR(64) NOT NULL,
  url VARCHAR(512) NOT NULL,
  request_body TEXT NOT NULL,
  http_status INT NULL,
  response_body TEXT NULL,
  error_message VARCHAR(512) NULL,
  attempt INT NOT NULL DEFAULT 1,
  max_attempts INT NOT NULL DEFAULT 4,
  elapsed_ms INT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE INDEX idx_hc_webhook_log_message ON hc_webhook_log (message_id);
CREATE INDEX idx_hc_webhook_log_created ON hc_webhook_log (created_at DESC);
CREATE INDEX idx_hc_webhook_log_success ON hc_webhook_log (success, created_at DESC);

