-- Migration 026: Drug interaction checking (HC-112)
-- BACKUP FIRST: ./dump.sh
--
-- Stores ingredient-level drug interaction pairs. Each row represents
-- a known interaction between two ingredients (alphabetically ordered
-- so we only store each pair once). Severity is minor/moderate/major.
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_drug_interactions (
  ingredient_a VARCHAR(64) NOT NULL,
  ingredient_b VARCHAR(64) NOT NULL,
  severity VARCHAR(10) NOT NULL DEFAULT 'minor',
  description TEXT NULL,
  PRIMARY KEY (ingredient_a, ingredient_b)
);

CREATE INDEX idx_drug_interactions_b ON hc_drug_interactions (ingredient_b);
