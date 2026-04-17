-- Migration 021: Pulse / cycle dosing (HC-121)
-- BACKUP FIRST: ./dump.sh
--
-- Supports "3 weeks on, 1 week off" patterns common in veterinary
-- antibiotics, hormonal therapy, and chemotherapy. Both columns NULL
-- means no cycle (continuous dosing, the default). When set, the
-- schedule alternates: cycle_on_days of normal dosing, then
-- cycle_off_days with no doses expected.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_medicine_schedules
    ADD COLUMN cycle_on_days INT NULL;

ALTER TABLE hc_medicine_schedules
    ADD COLUMN cycle_off_days INT NULL;
