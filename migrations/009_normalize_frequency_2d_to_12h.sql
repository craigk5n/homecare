-- Migration 009: Normalize any legacy frequency='2d' rows to '12h'.
-- BACKUP FIRST: ./dump.sh
--
-- Background: the Add-to-Schedule and Adjust-Dosage dropdowns previously
-- labeled '2d' as "twice daily", which is linguistically plausible but
-- parsed by ScheduleCalculator::frequencyToSeconds() as "every 2 days"
-- (2 * 86400s). The UI label mismatched the parser, which produced the
-- bogus "200% Tobramycin adherence" investigation in STATUS.md (HC-075).
--
-- Any existing schedule row saved with frequency='2d' was almost
-- certainly intended to mean every 12 hours. Rewrite them to '12h' so
-- dosing math and adherence reports become accurate.
--
-- Portable across MySQL 8+ and SQLite 3.35+.

UPDATE hc_medicine_schedules
   SET frequency = '12h'
 WHERE frequency = '2d';
