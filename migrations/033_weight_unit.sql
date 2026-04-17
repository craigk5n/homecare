-- Migration 033: Weight display unit preference.
-- Default 'kg'; admin can toggle to 'lb' in Settings.
-- Internal storage remains kg; conversion is display-only.

INSERT IGNORE INTO hc_config (setting, value) VALUES ('weight_unit', 'kg');
