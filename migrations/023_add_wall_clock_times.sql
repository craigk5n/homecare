-- Migration 023: Multiple wall-clock times per day (HC-123)
-- BACKUP FIRST: ./dump.sh
--
-- "8am + 2pm + 8pm" is a common human schedule. A wall-clock schedule
-- keeps doses anchored to specific times of day rather than drifting
-- via a pure interval (e.g. "every 8 hours").
--
-- Stored as a comma-separated list of HH:MM times, e.g. "08:00,14:00,20:00".
-- NULL means the schedule uses the existing frequency-based interval.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_medicine_schedules
    ADD COLUMN wall_clock_times VARCHAR(128) NULL;
