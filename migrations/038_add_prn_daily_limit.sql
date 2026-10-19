-- Migration 038: PRN daily-limit warning (HC-238, proposed contribution)
-- BACKUP FIRST: ./dump.sh
--
-- PRN ("as needed") schedules (HC-120) have no cadence, but most do
-- carry a ceiling on the label: "no more than 4 doses in 24 hours",
-- "at least 4 hours apart". Two optional columns capture that:
--
--   * max_doses_per_24h -- rolling 24-hour dose ceiling. NULL = no
--                          limit (every existing row), so behaviour is
--                          unchanged until a caregiver sets one.
--   * min_hours_between -- optional minimum spacing between doses.
--                          NULL = no minimum.
--
-- Limits are advisory: recording past them asks for confirmation and
-- writes an audit flag, but is never blocked (a vet / doctor may have
-- ordered an extra dose).
--
-- hc_prn_limit_alert_log throttles the "limit reached / exceeded"
-- notification, same one-row-per-schedule shape as
-- hc_late_dose_alert_log: `last_dose_at` is the dose that triggered
-- the last alert and `last_level` is 'reached' or 'exceeded'.
--
-- Portable MySQL 8+ / SQLite 3.35+.

ALTER TABLE hc_medicine_schedules
    ADD COLUMN max_doses_per_24h INT NULL;

ALTER TABLE hc_medicine_schedules
    ADD COLUMN min_hours_between DECIMAL(5, 2) NULL;

CREATE TABLE hc_prn_limit_alert_log (
  schedule_id  INT NOT NULL PRIMARY KEY,
  last_dose_at DATETIME NOT NULL,
  last_level   VARCHAR(10) NOT NULL,
  sent_at      DATETIME NOT NULL
);
