<?php
require_once 'includes/init.php';

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

    // Validate the input
    if (!empty($patient_id) && !empty($medicine_id) && !empty($start_date) && !empty($frequency)) {
        if (!empty($schedule_id)) {
            // Updating schedule
            $sql = "UPDATE hc_medicine_schedules SET " .
                "patient_id = ?, medicine_id = ?, start_date = ?, end_date = ?, frequency = ? " .
                "WHERE id = ?";
            $values = [$patient_id, $medicine_id, $start_date, $end_date, $frequency, $schedule_id];
            if (!dbi_execute($sql, $values)) {
                echo "<p>Error updating schedule: " . dbi_error() . "</p>";
                exit;
            }
        } else {
            // Adding schedule
            $sql = "INSERT INTO hc_medicine_schedules " .
                "(patient_id, medicine_id, start_date, end_date, frequency) " .
                "VALUES (?, ?, ?, ?, ?)";
            $values = [$patient_id, $medicine_id, $start_date, $end_date, $frequency];
            if (!dbi_execute($sql, $values)) {
                echo "<p>Error adding schedule: " . dbi_error() . "</p>";
                exit;
            }
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

