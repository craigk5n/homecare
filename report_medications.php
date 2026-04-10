<?php
require_once 'includes/init.php';

print_header();

$patient_id = getGetValue('patient_id');
$patient = getPatient($patient_id);
$patientName = $patient['name'];
$showCompletedParam = getIntValue('show_completed');
$showCompleted = !empty($showCompletedParam);
$assumePastIntake = getIntValue('assume_past_intake'); // Checkbox state
$includeAction = false;

echo "<h2>Medication Supply Report for " . htmlentities($patientName) . "</h2>\n";

// Checkbox form
echo "<form action='report_medications.php' method='GET'>\n";
echo "<input type='hidden' name='patient_id' value='" . htmlspecialchars($patient_id) . "'>\n";
if ($showCompleted) {
    echo "<input type='hidden' name='show_completed' value='1'>\n";
}
echo "<div class='form-check mb-3'>\n";
echo "<input class='form-check-input' type='checkbox' name='assume_past_intake' value='1' id='assumePastIntake'" . ($assumePastIntake ? " checked" : "") . ">\n";
echo "<label class='form-check-label' for='assumePastIntake'>Assume past scheduled doses were taken on time</label>\n";
echo "</div>\n";
echo "<button type='submit' class='btn btn-primary mb-3'>Update</button>\n";
echo "</form>\n";

echo "<div class='table-responsive'>\n";
echo "<table class='table table-bordered table-striped'>\n";
echo "<thead class='thead-dark'>";
if ($includeAction) {
    echo "<tr><th>Medication Name</th><th width=\"10%\">Frequency</th><th>Last Taken</th><th width=\"30%\">Remaining</th><th width=\"10%\">Action</th></tr>";
} else {
    echo "<tr><th>Medication Name</th><th width=\"10%\">Frequency</th><th>Last Taken</th><th width=\"35%\">Remaining</th></tr>";
}
echo "</thead>\n";
echo "<tbody>\n";

$sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date, ms.end_date, ms.medicine_id,
        (SELECT MAX(mi.taken_time) FROM hc_medicine_intake mi WHERE mi.schedule_id = ms.id) AS last_taken
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.patient_id = ?" . ($showCompleted ? "" : " AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())") . "
        ORDER BY last_taken ASC";

$rows = dbi_get_cached_rows($sql, [$patient_id]);

$medicationRows = [];

foreach ($rows as $row) {
    $schedule_id = $row[0];
    $medicationName = $row[1];
    $frequency = $row[2];
    $start_date = $row[3];
    $end_date = $row[4];
    $medicine_id = $row[5];
    $lastTaken = $row[6] ? formatDateNicely($row[6]) : "Not Yet Taken";

    // Calculate remaining doses, considering assumed past intake if enabled
    $remainingDoses = dosesRemaining($medicine_id, $schedule_id, $assumePastIntake, $start_date, $frequency);
    $days = $remainingDoses['remainingDays'];

    if (!empty($end_date) && $end_date < date('Y-m-d')) {
        // Medication is no longer on the patient's schedule, so put it at the bottom of the table.
        $sortKey = sprintf("999999-%06d %s", $days, $medicationName);
        $remain = sprintf("%s doses, %d days", $remainingDoses['remainingDoses'], $days);
    } else {
        $futureDate = date('M j, Y', strtotime("+$days days"));
        $sortKey = sprintf("%06d %s", $days, $medicationName);
        $remain = sprintf("Until %s, %s doses, %d days", $futureDate, $remainingDoses['remainingDoses'], $days);
    }

    if ($assumePastIntake && $remainingDoses['remainingDoses'] > 0) {
        $remain .= "<br>(Assuming past doses taken)";
    }

    $editIcon = '<img src="images/bootstrap-icons/boxes.svg" alt="' . translate('Edit Inventory') . '">';

    $medicationRows[$sortKey] = "<tr>" .
                                   "<td>" . htmlspecialchars($medicationName) . "</td>" .
                                   "<td>" . htmlspecialchars($frequency) . "</td>" .
                                   "<td>" . $lastTaken . "</td>" .
                                   "<td>" . $remain . "</td>" .
                                   ($includeAction ?
                                        "<td><a href='edit_medication_schedule.php?schedule_id=$schedule_id'>$editIcon</a></td>" : '') .
                                   "</tr>\n";
}

// Sort the array by the date medications will run out
ksort($medicationRows);

foreach ($medicationRows as $medicationRow) {
    echo $medicationRow;
}

echo "</tbody>";
echo "</table>\n";
echo "</div>\n";
echo "<p>";
if ($showCompleted) {
    echo "<a href=\"report_medications.php?patient_id=" . htmlspecialchars($patient_id) . "\" class='btn btn-secondary'>Hide Completed Medications</a>\n";
} else {
    echo "<a href=\"report_medications.php?patient_id=" . htmlspecialchars($patient_id) . "&show_completed=1\" class='btn btn-secondary'>Show Completed Medications</a>\n";
}
echo print_trailer();
?>