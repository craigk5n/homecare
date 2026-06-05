-- Migration 035: Prevent duplicate medicines.
--
-- hc_medicines had no uniqueness constraint, so a double-submit of the
-- "Add Medication" form (or two caregivers adding the same product)
-- created identical rows -- e.g. two "Balance IT / 1 teaspoon" entries.
-- Enforce uniqueness on (name, dosage) so the database rejects the
-- duplicate; add_medication_handler.php also pre-checks for a friendly
-- message. Run after de-duplicating any existing rows (merge_medicines).

ALTER TABLE hc_medicines
  ADD CONSTRAINT uq_medicines_name_dosage UNIQUE (name, dosage);
