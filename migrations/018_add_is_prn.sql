-- Migration 018: PRN / as-needed schedules (HC-120)
-- BACKUP FIRST: ./dump.sh
--
-- PRN ("pro re nata" -- "as needed") medications are taken on demand rather
-- than on a fixed cadence: pain relief, seizure rescue, anxiety meds, etc.
-- A PRN schedule still records intakes, but has no expected cadence and
-- must therefore not drive "next-due" timers, late-dose alerts, supply-day
-- projections, or adherence percentages.
--
-- Two columns change:
--   * is_prn  -- boolean flag on the schedule row, default 'N' so every
--              existing schedule stays non-PRN without migration churn.
--   * frequency -- relaxed to NULL-able. PRN rows store NULL; fixed-cadence
--              rows keep their existing 'Nd' / 'Nh' / 'Nm' string.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_medicine_schedules
    ADD COLUMN is_prn CHAR(1) NOT NULL DEFAULT 'N';

ALTER TABLE hc_medicine_schedules
    MODIFY COLUMN frequency VARCHAR(255) NULL;
