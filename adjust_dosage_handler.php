<?php
require_once 'includes/init.php';
require_role('caregiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list_medications.php');
    exit();
}

$patient_id = getPostValue('patient_id');
$old_schedule_id = getPostValue('old_schedule_id');
$medicine_id = getPostValue('medicine_id');
$frequency = getPostValue('frequency');
$unit_per_dose = getPostValue('unit_per_dose');
$effective_date = getPostValue('effective_date');
$confirmed = getPostValue('confirmed');
$reassign_intakes = getPostValue('reassign_intakes');

// Validate required fields
if (empty($patient_id) || empty($old_schedule_id) || empty($medicine_id) ||
    empty($frequency) || empty($unit_per_dose) || empty($effective_date)) {
    die_miserable_death('Missing required fields. Please go back and try again.');
}

// Verify the old schedule exists and belongs to this patient
$checkSql = "SELECT id FROM hc_medicine_schedules WHERE id = ? AND patient_id = ?";
$checkRows = dbi_get_cached_rows($checkSql, [$old_schedule_id, $patient_id]);
if (empty($checkRows)) {
    die_miserable_death('Schedule not found.');
}

// Check for overlapping intakes when backdating (effective_date is in the past)
$today = date('Y-m-d');
if ($effective_date < $today && empty($confirmed)) {
    $overlapSql = "SELECT COUNT(*) FROM hc_medicine_intake
        WHERE schedule_id = ? AND taken_time >= ?";
    $overlapRows = dbi_get_cached_rows($overlapSql, [$old_schedule_id, $effective_date . ' 00:00:00']);
    $overlapCount = !empty($overlapRows) ? intval($overlapRows[0][0]) : 0;

    if ($overlapCount > 0) {
        // Redirect to confirmation page with all form data
        // We use a POST-forward by rendering a hidden auto-submit form
        print_header();
        echo '<form id="confirmForm" action="adjust_dosage_confirm.php" method="POST">';
        print_form_key();
        echo '<input type="hidden" name="patient_id" value="' . htmlspecialchars($patient_id) . '">';
        echo '<input type="hidden" name="old_schedule_id" value="' . htmlspecialchars($old_schedule_id) . '">';
        echo '<input type="hidden" name="medicine_id" value="' . htmlspecialchars($medicine_id) . '">';
        echo '<input type="hidden" name="frequency" value="' . htmlspecialchars($frequency) . '">';
        echo '<input type="hidden" name="unit_per_dose" value="' . htmlspecialchars($unit_per_dose) . '">';
        echo '<input type="hidden" name="effective_date" value="' . htmlspecialchars($effective_date) . '">';
        echo '<input type="hidden" name="overlap_count" value="' . $overlapCount . '">';
        echo '</form>';
        echo '<script nonce="' . htmlspecialchars($GLOBALS['NONCE'] ?? '') . '">document.getElementById("confirmForm").submit();</script>';
        echo print_trailer();
        exit();
    }
}

// ── Execute the adjustment ──

// Step 1: End the old schedule
$endSql = "UPDATE hc_medicine_schedules SET end_date = ? WHERE id = ?";
if (!dbi_execute($endSql, [$effective_date, $old_schedule_id])) {
    die_miserable_death('Error ending current schedule: ' . dbi_error());
}

// Step 2: Create the new schedule
$insertSql = "INSERT INTO hc_medicine_schedules
    (patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose)
    VALUES (?, ?, ?, NULL, ?, ?)";
$insertValues = [$patient_id, $medicine_id, $effective_date, $frequency, $unit_per_dose];

if (!dbi_execute($insertSql, $insertValues)) {
    die_miserable_death('Error creating new schedule: ' . dbi_error());
}
$newScheduleId = (int) mysqli_insert_id($GLOBALS['c']);

// Step 3: Reassign overlapping intakes if requested
$reassigned = 0;
if ($reassign_intakes === 'yes') {
    $reassignSql = "UPDATE hc_medicine_intake SET schedule_id = ?
        WHERE schedule_id = ? AND taken_time >= ?";
    if (!dbi_execute($reassignSql, [$newScheduleId, $old_schedule_id, $effective_date . ' 00:00:00'])) {
        die_miserable_death('Error reassigning intake records: ' . dbi_error());
    }
    $reassigned = (int) mysqli_affected_rows($GLOBALS['c']);
}

audit_log('dosage.adjusted', 'schedule', $newScheduleId, [
    'patient_id' => (int) $patient_id,
    'medicine_id' => (int) $medicine_id,
    'old_schedule_id' => (int) $old_schedule_id,
    'new_frequency' => $frequency,
    'new_unit_per_dose' => (float) $unit_per_dose,
    'effective_date' => $effective_date,
    'intakes_reassigned' => $reassigned,
]);

do_redirect('list_schedule.php?patient_id=' . urlencode($patient_id));
?>
