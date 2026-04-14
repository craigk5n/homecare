<?php
require_once 'includes/init.php';
require_role('caregiver');

// Check if the form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Capture form data
    $patient_id = getPostValue('patient_id');
    $medicine_id = getPostValue('medicine_id');
    $schedule_id = getPostValue('schedule_id'); // Update instead of add
    $start_date = getPostValue('start_date');
    $end_date = getPostValue('end_date');
    if (empty($end_date)) {
        $end_date = NULL;
    }
    $frequency = getPostValue('frequency');
    $unit_per_dose = getPostValue('unit_per_dose');
    if (empty($unit_per_dose)) {
        $unit_per_dose = 1.00;
    }

    // Validate the input
    if (!empty($patient_id) && !empty($medicine_id) && !empty($start_date) && !empty($frequency)) {
        if (!empty($schedule_id)) {
            // Updating schedule
            $sql = "UPDATE hc_medicine_schedules SET " .
                "patient_id = ?, medicine_id = ?, start_date = ?, end_date = ?, frequency = ?, unit_per_dose = ? " .
                "WHERE id = ?";
            $values = [$patient_id, $medicine_id, $start_date, $end_date, $frequency, $unit_per_dose, $schedule_id];
            if (!dbi_execute($sql, $values)) {
                echo "<p>Error updating schedule: " . dbi_error() . "</p>";
                exit;
            }
            audit_log('schedule.updated', 'schedule', (int) $schedule_id, [
                'patient_id' => (int) $patient_id,
                'medicine_id' => (int) $medicine_id,
                'frequency' => $frequency,
                'unit_per_dose' => (float) $unit_per_dose,
                'start_date' => $start_date,
                'end_date' => $end_date,
            ]);
        } else {
            // Adding schedule
            $sql = "INSERT INTO hc_medicine_schedules " .
                "(patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose) " .
                "VALUES (?, ?, ?, ?, ?, ?)";
            $values = [$patient_id, $medicine_id, $start_date, $end_date, $frequency, $unit_per_dose];
            if (!dbi_execute($sql, $values)) {
                echo "<p>Error adding schedule: " . dbi_error() . "</p>";
                exit;
            }
            $newId = (int) ($GLOBALS['phpdbiConnection']->insert_id ?? 0);
            audit_log('schedule.created', 'schedule', $newId ?: null, [
                'patient_id' => (int) $patient_id,
                'medicine_id' => (int) $medicine_id,
                'frequency' => $frequency,
                'unit_per_dose' => (float) $unit_per_dose,
                'start_date' => $start_date,
                'end_date' => $end_date,
            ]);
        }
        do_redirect("list_schedule.php?patient_id=" . urlencode($patient_id));
    } else {
        // Handle validation error, e.g., missing fields
        echo "<p>Missing required fields. Please go back and try again.</p>";
    }
} else {
    // Not a POST request
    header("Location: edit_schedule.php");
    exit();
}
?>

