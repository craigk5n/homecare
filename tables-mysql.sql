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
  PRIMARY KEY (login)
);

CREATE TABLE `hc_patients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE
);

/* Product catalog: the physical item you buy (name + strength/form).
   Prescription details (frequency, unit_per_dose) live on hc_medicine_schedules. */
CREATE TABLE `hc_medicines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `dosage` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
   unit_per_dose and frequency are authoritative here (not on hc_medicines). */
CREATE TABLE `hc_medicine_schedules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `medicine_id` INT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE,
  `frequency` VARCHAR(255) NOT NULL,
  `unit_per_dose` DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
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

