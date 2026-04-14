-- Standard seed fixture for HomeCare integration tests.
--
-- Loaded on top of tests/fixtures/schema-sqlite.sql when a test wants a
-- realistic multi-patient, multi-medicine dataset without re-deriving it
-- row by row. IDs are fixed so tests can reference them directly.
--
-- Shape:
--   patients:    1 Daisy (active), 2 Fozzie (active), 3 Kermit (disabled)
--   medicines:   1 Sildenafil 20mg, 2 Carprofen 75mg, 3 Gabapentin 100mg
--   schedules:   1 Daisy/Sildenafil 8h, 2 Daisy/Carprofen 12h (ended),
--                3 Fozzie/Gabapentin 24h
--   inventory:   one checkpoint per medicine, recorded 2026-04-01
--   intakes:     a handful of recent doses for each active schedule

INSERT INTO hc_patients (id, name, is_active) VALUES
    (1, 'Daisy', 1),
    (2, 'Fozzie', 1),
    (3, 'Kermit', 0);

INSERT INTO hc_medicines (id, name, dosage) VALUES
    (1, 'Sildenafil', '20mg'),
    (2, 'Carprofen', '75mg'),
    (3, 'Gabapentin', '100mg');

INSERT INTO hc_medicine_schedules
    (id, patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose)
VALUES
    (1, 1, 1, '2026-01-01', NULL,         '8h',  1.0),
    (2, 1, 2, '2026-01-15', '2026-03-31', '12h', 0.5),
    (3, 2, 3, '2026-02-01', NULL,         '1d',  2.0);

INSERT INTO hc_medicine_inventory
    (id, medicine_id, quantity, current_stock, recorded_at, note)
VALUES
    (1, 1, 60.0, 60.0, '2026-04-01 10:00:00', 'Refill'),
    (2, 2, 30.0, 30.0, '2026-04-01 10:00:00', NULL),
    (3, 3, 45.0, 45.0, '2026-04-01 10:00:00', NULL);

INSERT INTO hc_medicine_intake (id, schedule_id, taken_time, note) VALUES
    (1, 1, '2026-04-05 08:00:00', NULL),
    (2, 1, '2026-04-05 16:00:00', NULL),
    (3, 1, '2026-04-06 00:00:00', 'with food'),
    (4, 3, '2026-04-05 09:00:00', NULL),
    (5, 3, '2026-04-06 09:00:00', NULL);
