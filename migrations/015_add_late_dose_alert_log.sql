-- Migration 015: Late-dose alert throttle (HC-105)
-- BACKUP FIRST: ./dump.sh
--
-- Same shape as hc_supply_alert_log: one row per schedule, upserted
-- every time a fresh lateness window alert fires. `last_due_at`
-- stores the exact due instant we alerted about so the service
-- can suppress replays for the SAME window while still re-arming
-- when the next dose rolls around.
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_late_dose_alert_log (
  schedule_id INT NOT NULL PRIMARY KEY,
  last_due_at DATETIME NOT NULL,
  sent_at     DATETIME NOT NULL
);
