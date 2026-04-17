-- Migration 025: Add NDC column for barcode scanning (HC-111)
-- BACKUP FIRST: ./dump.sh
--
-- NDC (National Drug Code) is the 11-digit identifier on US prescription
-- labels. Multiple NDCs can map to a single RxNorm concept, but we store
-- the primary one here for barcode → catalog lookup. The column is nullable
-- because not every catalog entry has an NDC (e.g. vet-only drugs).
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_drug_catalog ADD COLUMN ndc VARCHAR(13) NULL;

CREATE INDEX idx_drug_catalog_ndc ON hc_drug_catalog (ndc);
