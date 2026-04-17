-- Migration 020: Veterinary profile (HC-113)
-- BACKUP FIRST: ./dump.sh
--
-- Makes veterinary patients a first-class concept. Species, weight, and
-- per-kg dosing let the system handle weight-based prescriptions common
-- in vet medicine (e.g. "2 mg/kg twice daily").
--
-- Portable MySQL 8+ / SQLite 3.35+.

-- Patient-level fields
ALTER TABLE hc_patients
    ADD COLUMN species VARCHAR(32) NULL;

ALTER TABLE hc_patients
    ADD COLUMN weight_kg DECIMAL(6,2) NULL;

ALTER TABLE hc_patients
    ADD COLUMN weight_as_of DATE NULL;

-- Schedule-level: interpret unit_per_dose as mg/kg when dose_basis='per_kg'.
-- MySQL doesn't support ENUM in ALTER ADD for all versions cleanly, so we
-- use VARCHAR with a CHECK constraint for portability.
ALTER TABLE hc_medicine_schedules
    ADD COLUMN dose_basis VARCHAR(10) NOT NULL DEFAULT 'fixed';
