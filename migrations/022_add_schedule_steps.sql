-- Migration 022: Step / taper dosing (HC-122)
-- BACKUP FIRST: ./dump.sh
--
-- Models dose-level changes within a single schedule. A steroid taper
-- ("Week 1: 5mg, Week 2: 10mg, Week 3+: 20mg") no longer requires
-- ending the schedule and starting a new one — a step row records each
-- change while adherence continuity is preserved.
--
-- Zero rows = use the schedule's own unit_per_dose (backwards compatible).
-- One or more rows = latest step whose start_date <= reference date wins.
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_schedule_steps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL,
  start_date DATE NOT NULL,
  unit_per_dose DECIMAL(10,3) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (schedule_id) REFERENCES hc_medicine_schedules(id) ON DELETE CASCADE
);

CREATE INDEX idx_schedule_steps_sched ON hc_schedule_steps (schedule_id, start_date);
