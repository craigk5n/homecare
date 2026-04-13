CREATE TABLE hc_user (
  /* the unique user login */
  login VARCHAR(25) NOT NULL,
  /* the user's password. (not used for http) */
  passwd VARCHAR(255),
  /* user's last name */
  lastname VARCHAR(25),
  /* user's first name */
  firstname VARCHAR(25),
  /* is the user a HomeCare administrator ('Y' = yes, 'N' = no) */
  is_admin CHAR(1) DEFAULT 'N',
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
  PRIMARY KEY (login)
);

CREATE TABLE `hc_patients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE `hc_medicines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `dosage` VARCHAR(255) NOT NULL,
  `frequency` VARCHAR(255) NOT NULL,
  `unit_per_dose` DECIMAL(10, 2) NOT NULL,
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


CREATE TABLE `hc_medicine_schedules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patient_id` INT NOT NULL,
  `medicine_id` INT NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE,
  `frequency` VARCHAR(255) NOT NULL,
  `unit_per_dose` DECIMAL(10, 2) DEFAULT NULL,
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

