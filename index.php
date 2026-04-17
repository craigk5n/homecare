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

// ── Branch 3: multiple patients → redirect to dashboard ────────────
header('Location: dashboard.php');
exit;
