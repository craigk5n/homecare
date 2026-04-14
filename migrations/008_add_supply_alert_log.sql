-- Migration 008: hc_supply_alert_log (HC-040)
-- BACKUP FIRST: ./dump.sh
--
-- Tracks when we last sent a low-supply alert for each medicine, so
-- send_reminders.php won't spam the ntfy channel every time it runs.
-- One row per medicine_id; UPDATE in place on subsequent alerts.
--
-- Portable MySQL 8+ / SQLite 3.35+.

CREATE TABLE hc_supply_alert_log (
  medicine_id INT NOT NULL PRIMARY KEY,
  last_sent_at DATETIME NOT NULL
);
