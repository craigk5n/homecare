-- Migration 019: Pause / skip-today for active schedules (HC-124)
-- BACKUP FIRST: ./dump.sh
--
-- A pause suspends a schedule's cadence for a date range. While paused,
-- no doses are expected, reminders don't fire, and adherence counts the
-- paused days as excused rather than missed.
--
-- end_date NULL means "paused indefinitely until explicitly resumed."
-- A 1-day "skip today" is a pause where start_date = end_date = today.
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_schedule_pauses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (schedule_id) REFERENCES hc_medicine_schedules(id) ON DELETE CASCADE
);

CREATE INDEX idx_schedule_pauses_sched ON hc_schedule_pauses (schedule_id, start_date);
