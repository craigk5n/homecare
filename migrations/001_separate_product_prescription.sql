-- Migration 001: Separate product from prescription
-- BACKUP FIRST: ./dump.sh
--
-- hc_medicines becomes a product catalog (name + dosage only).
-- hc_medicine_schedules becomes the sole authority for unit_per_dose.
-- Frequency was already authoritative on schedules.

-- Step 1: Backfill schedule-level unit_per_dose from medicine defaults where NULL
UPDATE hc_medicine_schedules ms
  JOIN hc_medicines m ON ms.medicine_id = m.id
  SET ms.unit_per_dose = m.unit_per_dose
  WHERE ms.unit_per_dose IS NULL;

-- Step 2: Make unit_per_dose non-nullable now that all rows have values
ALTER TABLE hc_medicine_schedules
  MODIFY COLUMN unit_per_dose DECIMAL(10,2) NOT NULL DEFAULT 1.00;

-- Step 3: Drop prescription fields from the product table
ALTER TABLE hc_medicines DROP COLUMN frequency;
ALTER TABLE hc_medicines DROP COLUMN unit_per_dose;
