#!/usr/bin/env php
<?php
/**
 * Seed E2E test fixtures for Playwright (HC-142).
 *
 * Creates 2 patients, 5 medicines, 3 active schedules, 30 days of
 * intakes with ~85% adherence, one cadence-mismatch case, and one
 * low-supply case.
 *
 * Idempotent: safe to re-run. Uses INSERT IGNORE so duplicate rows
 * are silently skipped.
 *
 * Usage:
 *   php bin/seed_e2e_fixtures.php
 *   docker compose exec web php bin/seed_e2e_fixtures.php
 */

declare(strict_types=1);

if (!defined('_ISVALID')) {
    define('_ISVALID', true);
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/dbi4php.php';

do_config();

global $db_host, $db_login, $db_password, $db_database;

$c = @new \mysqli($db_host, $db_login, $db_password, $db_database);
if ($c->connect_error) {
    echo "Error: Cannot connect to database: {$c->connect_error}\n";
    exit(1);
}

// All IDs start at 100 to avoid colliding with manual test data.
$statements = [];

// ── Patients ────────────────────────────────────────────────────────
// 2 active patients
$statements[] = "INSERT IGNORE INTO hc_patients (id, name, species, is_active, created_at, updated_at)
VALUES
    (100, 'Bella',  'dog', 1, NOW(), NOW()),
    (101, 'Mochi',  'cat', 1, NOW(), NOW())";

// ── Medicines ───────────────────────────────────────────────────────
// 5 medicines — IDs 100-104
$statements[] = "INSERT IGNORE INTO hc_medicines (id, name, dosage, created_at, updated_at)
VALUES
    (100, 'Gabapentin',   '100mg capsule', NOW(), NOW()),
    (101, 'Carprofen',    '75mg tablet',   NOW(), NOW()),
    (102, 'Omeprazole',   '20mg capsule',  NOW(), NOW()),
    (103, 'Furosemide',   '40mg tablet',   NOW(), NOW()),
    (104, 'Prednisolone', '5mg tablet',    NOW(), NOW())";

// ── Schedules ───────────────────────────────────────────────────────
// 3 active schedules:
//   #100: Bella / Gabapentin  8h  (normal cadence)
//   #101: Bella / Carprofen  12h  (normal cadence)
//   #102: Mochi / Omeprazole  1d  (normal cadence)
//
// 1 cadence-mismatch case:
//   #103: Bella / Furosemide  8h schedule but we will record intakes at
//         ~12h intervals so the schedule page shows a frequency warning.
//
// 1 additional schedule for low-supply medicine:
//   #104: Mochi / Prednisolone 12h (low supply — only 2 units in stock)
$statements[] = "INSERT IGNORE INTO hc_medicine_schedules
    (id, patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose, is_prn, dose_basis, created_at)
VALUES
    (100, 100, 100, DATE_SUB(CURDATE(), INTERVAL 30 DAY), NULL, '8h',  1.0, 'N', 'fixed', NOW()),
    (101, 100, 101, DATE_SUB(CURDATE(), INTERVAL 30 DAY), NULL, '12h', 1.0, 'N', 'fixed', NOW()),
    (102, 101, 102, DATE_SUB(CURDATE(), INTERVAL 30 DAY), NULL, '1d',  1.0, 'N', 'fixed', NOW()),
    (103, 100, 103, DATE_SUB(CURDATE(), INTERVAL 30 DAY), NULL, '8h',  1.0, 'N', 'fixed', NOW()),
    (104, 101, 104, DATE_SUB(CURDATE(), INTERVAL 30 DAY), NULL, '12h', 1.0, 'N', 'fixed', NOW())";

// ── Inventory ───────────────────────────────────────────────────────
// Normal supply for most medicines; very low for Prednisolone (#104).
$statements[] = "INSERT IGNORE INTO hc_medicine_inventory
    (id, medicine_id, quantity, current_stock, recorded_at, note)
VALUES
    (100, 100, 90.0, 90.0, DATE_SUB(NOW(), INTERVAL 30 DAY), 'E2E fixture refill'),
    (101, 101, 60.0, 60.0, DATE_SUB(NOW(), INTERVAL 30 DAY), 'E2E fixture refill'),
    (102, 102, 30.0, 30.0, DATE_SUB(NOW(), INTERVAL 30 DAY), 'E2E fixture refill'),
    (103, 103, 90.0, 90.0, DATE_SUB(NOW(), INTERVAL 30 DAY), 'E2E fixture refill'),
    (104, 104,  2.0,  2.0, DATE_SUB(NOW(), INTERVAL 30 DAY), 'E2E low-supply fixture')";

echo "[e2e-seed] Seeding patients, medicines, schedules, inventory...\n";

foreach ($statements as $sql) {
    if (!$c->query($sql)) {
        echo "Error: {$c->error}\nSQL: {$sql}\n";
        exit(1);
    }
}

// ── Intakes (30 days, ~85% adherence) ──────────────────────────────
// Generate intake records for the last 30 days. We use a deterministic
// seed so re-runs produce the same skip pattern.
echo "[e2e-seed] Generating 30 days of intake history...\n";

// Check if we already seeded intakes (idempotent guard).
$result = $c->query(
    "SELECT COUNT(*) AS cnt FROM hc_medicine_intake WHERE schedule_id IN (100,101,102,103,104)"
);
$row = $result->fetch_assoc();
if ((int)$row['cnt'] > 0) {
    echo "[e2e-seed] Intakes already seeded ({$row['cnt']} rows) — skipping.\n";
} else {
    // Schedule definitions: [schedule_id, doses_per_day, hours_between]
    $schedules = [
        [100, 3, 8],   // Gabapentin 8h  → 3 doses/day
        [101, 2, 12],  // Carprofen 12h  → 2 doses/day
        [102, 1, 24],  // Omeprazole 1d  → 1 dose/day
        // Cadence-mismatch: schedule says 8h (3/day) but we only record
        // 2 doses/day at 12h intervals → will trigger frequency warning.
        [103, 2, 12],
        // Low-supply: normal 12h cadence (2/day)
        [104, 2, 12],
    ];

    // Deterministic skip pattern: ~15% of doses skipped for 85% adherence.
    // Use a simple PRNG seeded so re-runs are identical.
    mt_srand(42);

    $insertBatch = [];
    $batchSize = 0;

    foreach ($schedules as [$scheduleId, $dosesPerDay, $hoursBetween]) {
        for ($day = 30; $day >= 1; $day--) {
            for ($dose = 0; $dose < $dosesPerDay; $dose++) {
                // Skip ~15% of doses (miss rate)
                if (mt_rand(1, 100) <= 15) {
                    continue;
                }

                $hour = $dose * $hoursBetween + 8; // first dose at 08:00
                // Add a little jitter: +/- 20 minutes
                $jitterMin = mt_rand(-20, 20);

                $takenTime = date(
                    'Y-m-d H:i:s',
                    strtotime("-{$day} days +{$hour} hours +{$jitterMin} minutes")
                );

                $escapedTime = $c->real_escape_string($takenTime);
                $insertBatch[] = "({$scheduleId}, '{$escapedTime}', NULL)";
                $batchSize++;

                // Flush in batches of 200
                if ($batchSize >= 200) {
                    $sql = "INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES "
                         . implode(",\n", $insertBatch);
                    if (!$c->query($sql)) {
                        echo "Error inserting intakes: {$c->error}\n";
                        exit(1);
                    }
                    $insertBatch = [];
                    $batchSize = 0;
                }
            }
        }
    }

    // Flush remaining
    if ($batchSize > 0) {
        $sql = "INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES "
             . implode(",\n", $insertBatch);
        if (!$c->query($sql)) {
            echo "Error inserting intakes: {$c->error}\n";
            exit(1);
        }
    }

    // Count what we inserted
    $result = $c->query(
        "SELECT COUNT(*) AS cnt FROM hc_medicine_intake WHERE schedule_id IN (100,101,102,103,104)"
    );
    $row = $result->fetch_assoc();
    echo "[e2e-seed] Inserted {$row['cnt']} intake records.\n";
}

echo "[e2e-seed] Done.\n";
$c->close();
exit(0);
