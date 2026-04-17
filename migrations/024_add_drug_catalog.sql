-- Migration 024: Drug catalog for RxNorm autocomplete (HC-110)
-- BACKUP FIRST: ./dump.sh
--
-- A standardised drug lookup table populated from RxNorm RRF dumps.
-- Caregivers pick from this catalog when adding medications; the
-- selected entry pre-fills name, strength, and dosage form.
-- Free-text entries remain supported (drug_catalog_id is nullable).
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_drug_catalog (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rxnorm_id INT NULL,
  name VARCHAR(255) NOT NULL,
  strength VARCHAR(128) NULL,
  dosage_form VARCHAR(128) NULL,
  ingredient_names TEXT NULL,
  generic CHAR(1) NOT NULL DEFAULT 'N',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX idx_drug_catalog_rxnorm ON hc_drug_catalog (rxnorm_id);
CREATE INDEX idx_drug_catalog_name ON hc_drug_catalog (name);

ALTER TABLE hc_medicines ADD COLUMN drug_catalog_id INT NULL;
ALTER TABLE hc_medicines ADD CONSTRAINT fk_medicines_drug_catalog
  FOREIGN KEY (drug_catalog_id) REFERENCES hc_drug_catalog(id) ON DELETE SET NULL;
