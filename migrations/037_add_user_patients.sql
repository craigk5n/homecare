-- Migration 037: Per-patient access control (HC-237).
--
-- Optional allow-list of the patients a non-admin user may see.
-- Semantics (enforced in HomeCare\Auth\PatientAccess):
--   * admins always see every patient; rows for an admin are ignored
--   * a caregiver/viewer with ZERO rows sees every patient (the
--     pre-037 behaviour, so existing installs are unchanged)
--   * a caregiver/viewer with one or more rows sees only those patients
--
-- user_login has no FK, matching hc_remember_tokens /
-- hc_password_reset_tokens (hc_user.login predates InnoDB FKs here).
-- Deleting a patient cascades its assignments away.

CREATE TABLE IF NOT EXISTS hc_user_patients (
  user_login VARCHAR(25) NOT NULL,
  patient_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_login, patient_id),
  FOREIGN KEY (patient_id) REFERENCES hc_patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_hc_user_patients_patient ON hc_user_patients (patient_id);
