<?php
/**
 * Home landing page — "smart default" post-login router.
 *
 *   0 active patients  → friendly empty state (admins see a link to
 *                        create the first patient; non-admins see a
 *                        "please contact an admin" message).
 *   1 active patient   → silent redirect to that patient's schedule
 *                        (the common household case: one person, one
 *                        daily medication routine).
 *   2+ active patients → card grid showing each patient with their
 *                        live overdue / due-soon counts so the
 *                        caregiver can see at a glance which one
 *                        needs attention first.
 *
 * Also the fallback target for pages like list_schedule.php when they
 * receive a missing or invalid patient_id — routing back here lands
 * the user somewhere useful instead of dying with "No such patient".
 */

require_once 'includes/init.php';

$patients = getPatients();       // active patients only, sorted by name
$patientCount = count($patients);

// ── Branch 1: no patients yet ───────────────────────────────────────
if ($patientCount === 0) {
    print_header();
    echo '<div class="container mt-5" style="max-width:640px;">';
    echo '<div class="card shadow-sm">';
    echo '<div class="card-body text-center p-5">';
    echo '<h1 class="h4 mb-3">Welcome to HomeCare</h1>';
    if (!empty($is_admin) && $is_admin) {
        echo '<p class="text-muted mb-4">No patients yet. Add the first one to start tracking medications.</p>';
        echo '<a href="edit_patient.php" class="btn btn-primary">Add a patient</a>';
    } else {
        echo '<p class="text-muted">No patients have been added yet. Ask an admin to add one.</p>';
    }
    echo '</div></div></div>';
    echo print_trailer();
    exit;
}

// ── Branch 2: exactly one patient → go straight to their schedule ──
if ($patientCount === 1) {
    $only = (int) $patients[0]['id'];
    header('Location: list_schedule.php?patient_id=' . $only);
    exit;
}

// ── Branch 3: multiple patients → card grid with status badges ─────
//
// One query pulls every active schedule's last_taken across every
// active patient; we then aggregate in PHP. Cheap for household-scale
// data (dozens of rows, not thousands) and avoids N+1 queries.
$dueSoonSeconds = 1800; // 30 minutes — matches list_schedule.php

// HC-120: PRN schedules have no cadence, so exclude them from the
// overdue/due-soon badge aggregation. They can't produce a meaningful
// "seconds until next due" value. The `empty($frequency)` guard below
// catches them as a safety net, but filtering at the SQL level avoids
// the unnecessary rows entirely.
$sql = "SELECT p.id, p.name,
               ms.frequency,
               (SELECT MAX(mi.taken_time)
                  FROM hc_medicine_intake mi
                 WHERE mi.schedule_id = ms.id) AS last_taken
          FROM hc_patients p
     LEFT JOIN hc_medicine_schedules ms
            ON ms.patient_id = p.id
           AND ms.is_prn = 'N'
           AND ms.frequency IS NOT NULL
           AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())
         WHERE p.is_active = 1
         ORDER BY p.name, ms.id";

$rows = dbi_get_cached_rows($sql, []);

// Seed an entry per patient so patients with zero schedules still render.
$stats = [];
foreach ($patients as $p) {
    $stats[(int) $p['id']] = [
        'name'     => $p['name'],
        'overdue'  => 0,
        'due_soon' => 0,
        'ok'       => 0,
    ];
}
foreach ($rows as $row) {
    $patientId = (int) $row[0];
    $frequency = $row[2];
    $lastTaken = $row[3] ?? null;
    if (empty($frequency)) {
        // LEFT JOIN produced a no-schedule row for this patient; skip.
        continue;
    }
    if ($lastTaken) {
        $seconds = calculateSecondsUntilDue($lastTaken, $frequency, true);
        if ($seconds <= 0) {
            $stats[$patientId]['overdue']++;
        } elseif ($seconds <= $dueSoonSeconds) {
            $stats[$patientId]['due_soon']++;
        } else {
            $stats[$patientId]['ok']++;
        }
    } else {
        // Never taken → treat as overdue so it surfaces in the badge.
        $stats[$patientId]['overdue']++;
    }
}

print_header();

echo '<div class="container mt-4" style="max-width:960px;">';
echo '<h1 class="h4 mb-4">Who would you like to view?</h1>';
echo '<div class="row">';

foreach ($patients as $p) {
    $pid  = (int) $p['id'];
    $name = htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8');
    $s    = $stats[$pid];

    $badges = '';
    if ($s['overdue'] > 0) {
        $badges .= '<span class="badge badge-danger mr-1" title="Overdue doses">'
                 . (int) $s['overdue'] . ' overdue</span>';
    }
    if ($s['due_soon'] > 0) {
        $badges .= '<span class="badge badge-warning mr-1" title="Due within 30 minutes">'
                 . (int) $s['due_soon'] . ' due soon</span>';
    }
    if ($badges === '' && $s['ok'] > 0) {
        $badges = '<span class="badge badge-success" title="No overdue or due-soon doses">all caught up</span>';
    }
    if ($badges === '' && $s['ok'] === 0) {
        $badges = '<span class="badge badge-secondary">no active schedules</span>';
    }

    echo '<div class="col-md-6 mb-3">';
    echo '  <a href="list_schedule.php?patient_id=' . $pid
       . '" class="card h-100 text-decoration-none text-dark shadow-sm patient-card">';
    echo '    <div class="card-body">';
    echo '      <div class="d-flex justify-content-between align-items-center">';
    echo '        <h2 class="h5 mb-0">' . $name . '</h2>';
    // Inline chevron so we don't depend on an icon file that may not
    // be present in every install.
    echo '        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="opacity:.4;" aria-hidden="true">';
    echo '<path d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708z"/>';
    echo '</svg>';
    echo '      </div>';
    echo '      <div class="mt-2">' . $badges . '</div>';
    echo '    </div>';
    echo '  </a>';
    echo '</div>';
}

echo '</div>'; // row
echo '</div>'; // container

// Small interactive polish: the card should feel clickable.
?>
<style>
  .patient-card { transition: box-shadow .15s ease, transform .05s ease; }
  .patient-card:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.12) !important; }
  .patient-card:active { transform: translateY(1px); }
</style>
<?php
echo print_trailer();
