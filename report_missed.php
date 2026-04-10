<?php
require_once 'includes/init.php';  // Assuming this file initializes your database and functions

// Configurable percentage for deviation
$lowerDeviationThreshold = 0.20;  // 20% deviation for "Late"
$upperDeviationThreshold = 0.50;  // 50% deviation for "Missed"

print_header();

$patient_id = getGetValue('patient_id'); 
$patient = getPatient($patient_id);

echo "<h2>Medication Timing Report: " . htmlentities($patient['name']) . "</h2>\n";
echo "<div class='table-responsive'>\n";
echo "<table class='table table-bordered'>\n";
echo "<thead>";
echo "<tr><th width=\"35%\">Medication Name</th><th>Frequency</th><th>Previously Taken</th><th>Expected</th><th>Actual</th><th>Status</th></tr>";
echo "</thead>\n";
echo "<tbody>\n";

// Fetch the medication schedules for a patient
$schedulesSql = "SELECT ms.id, m.name, ms.frequency
                 FROM hc_medicine_schedules ms
                 JOIN hc_medicines m ON ms.medicine_id = m.id
                 WHERE ms.patient_id = ?";
$schedules = dbi_get_cached_rows($schedulesSql, [$patient_id]);

foreach ($schedules as $schedule) {
    $schedule_id = $schedule[0];
    $medicationName = $schedule[1];
    $frequency = $schedule[2];

    // Fetch intake records for each schedule
    $intakesSql = "SELECT taken_time FROM hc_medicine_intake 
                   WHERE schedule_id = ? 
                   ORDER BY taken_time ASC";
    $intakes = dbi_get_cached_rows($intakesSql, [$schedule_id]);

    // Process intakes to find missed, early or late doses
    $previousTakenTime = null;
    foreach ($intakes as $intake) {
        $takenTime = $intake[0];
        if ($previousTakenTime) {
            $expectedNextTime = calculateNextDueDate($previousTakenTime, $frequency);
            $secondsBetweenDosages = frequencyToSeconds($frequency);
            $actualSecondsBetweenDoses = strtotime($takenTime) - strtotime($previousTakenTime);

            // Calculate allowed deviation
            $lowerAllowedSeconds = $secondsBetweenDosages * (1 + $lowerDeviationThreshold);
            $upperAllowedSeconds = $secondsBetweenDosages * (1 + $upperDeviationThreshold);
            $status = "On Time";
            if ($actualSecondsBetweenDoses > $upperAllowedSeconds) {
                $status = "Missed";
            } elseif ($actualSecondsBetweenDoses > $lowerAllowedSeconds) {
                $status = "Late";
            }

            $debug = '';
            //$debug = "previousTakenTime: $previousTakenTime <br>secondsBetweenDosages: $secondsBetweenDosages <br> actualSecondsBetweenDoses: $actualSecondsBetweenDoses <br>";

            if ($status != 'On Time') {
                echo "<tr>";
                echo "<td>$medicationName</td>";
                echo "<td>$frequency</td>";
                echo "<td>" . formatDateNicely($previousTakenTime) . "</td>";
                echo "<td>" . formatDateNicely($expectedNextTime) . "</td>";
                echo "<td>" . formatDateNicely($takenTime) . "</td>";
                echo "<td>$debug $status</td>";
                echo "</tr>\n";
            }
        }
        $previousTakenTime = $takenTime;
    }
}

echo "</tbody></table>\n";
echo "</div>\n";

echo "<p><a href='schedule_overview.php?patient_id=" . htmlspecialchars($patient_id) . "'>Back to Schedule Overview</a></p>\n";

echo print_trailer();
?>