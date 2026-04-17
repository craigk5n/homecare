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
    // HC-120: PRN ("as-needed") schedules store frequency as NULL so
    // downstream math can short-circuit. Any caller-submitted frequency
    // is discarded when the box is checked.
    $is_prn = !empty(getPostValue('is_prn'));
    if ($is_prn) {
        $frequency = null;
    }
    // HC-113: dose_basis — 'fixed' (default) or 'per_kg' (weight-based).
    $dose_basis = getPostValue('dose_basis');
    if ($dose_basis !== 'per_kg') {
        $dose_basis = 'fixed';
    }
    // HC-121: cycle dosing. Both must be set or both null.
    $cycle_on_raw = getPostValue('cycle_on_days');
    $cycle_off_raw = getPostValue('cycle_off_days');
    $cycle_on_days = (!empty($cycle_on_raw) && (int) $cycle_on_raw > 0) ? (int) $cycle_on_raw : null;
    $cycle_off_days = (!empty($cycle_off_raw) && (int) $cycle_off_raw > 0) ? (int) $cycle_off_raw : null;
    if ($cycle_on_days === null || $cycle_off_days === null) {
        $cycle_on_days = null;
        $cycle_off_days = null;
    }

    // Validate the input -- frequency is required for fixed-cadence rows
    // but must be absent for PRN rows (the form suppresses it via JS).
    $frequencyValid = $is_prn ? true : !empty($frequency);
    if (!empty($patient_id) && !empty($medicine_id) && !empty($start_date) && $frequencyValid) {
        $prnFlag = $is_prn ? 'Y' : 'N';
        if (!empty($schedule_id)) {
            // Updating schedule
            $sql = "UPDATE hc_medicine_schedules SET " .
                "patient_id = ?, medicine_id = ?, start_date = ?, end_date = ?, frequency = ?, unit_per_dose = ?, is_prn = ?, dose_basis = ?, cycle_on_days = ?, cycle_off_days = ? " .
                "WHERE id = ?";
            $values = [$patient_id, $medicine_id, $start_date, $end_date, $frequency, $unit_per_dose, $prnFlag, $dose_basis, $cycle_on_days, $cycle_off_days, $schedule_id];
            if (!dbi_execute($sql, $values)) {
                echo "<p>Error updating schedule: " . dbi_error() . "</p>";
                exit;
            }
            audit_log('schedule.updated', 'schedule', (int) $schedule_id, [
                'patient_id' => (int) $patient_id,
                'medicine_id' => (int) $medicine_id,
                'frequency' => $frequency,
                'unit_per_dose' => (float) $unit_per_dose,
                'is_prn' => $is_prn,
                'dose_basis' => $dose_basis,
                'cycle_on_days' => $cycle_on_days,
                'cycle_off_days' => $cycle_off_days,
                'start_date' => $start_date,
                'end_date' => $end_date,
            ]);
        } else {
            // Adding schedule
            $sql = "INSERT INTO hc_medicine_schedules " .
                "(patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose, is_prn, dose_basis, cycle_on_days, cycle_off_days) " .
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $values = [$patient_id, $medicine_id, $start_date, $end_date, $frequency, $unit_per_dose, $prnFlag, $dose_basis, $cycle_on_days, $cycle_off_days];
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
                'is_prn' => $is_prn,
                'dose_basis' => $dose_basis,
                'cycle_on_days' => $cycle_on_days,
                'cycle_off_days' => $cycle_off_days,
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

