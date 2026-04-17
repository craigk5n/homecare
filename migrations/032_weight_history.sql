-- Migration 032: Weight history tracking.
--
-- Stores historical weight readings per patient so caregivers can
-- track trends over time. The existing hc_patients.weight_kg and
-- weight_as_of fields become the "latest" snapshot; this table holds
-- the full history.

CREATE TABLE IF NOT EXISTS hc_weight_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  weight_kg DECIMAL(6,2) NOT NULL,
  recorded_at DATE NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES hc_patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE INDEX idx_hc_weight_history_patient ON hc_weight_history (patient_id, recorded_at DESC);

-- Seed from existing patient weights (if any).
INSERT INTO hc_weight_history (patient_id, weight_kg, recorded_at, note)
SELECT id, weight_kg, COALESCE(weight_as_of, CURDATE()), 'Imported from patient record'
  FROM hc_patients
 WHERE weight_kg IS NOT NULL;
