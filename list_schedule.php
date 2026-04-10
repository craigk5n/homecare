<?php
require_once 'includes/init.php';

print_header();

$patient_id = getGetValue('patient_id');
$patient = getPatient($patient_id);
$patientName = $patient['name'];
$showCompletedParam = getIntValue('show_completed');
$showCompleted = !empty($showCompletedParam);
$assumePastIntake = getIntValue('assume_past_intake'); // Checkbox state

$dueInNextHour = 1800; // 1/2 hour in seconds

echo "<h2>Medication Schedule: " . htmlentities($patientName) . "</h2>\n";

// Checkbox form
echo "<form action='list_schedule.php' method='GET'>\n";
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
echo "<tr><th>Medication Name</th><th>Frequency</th><th>Last Taken</th><th>Next Due</th><th>Remaining</th><th>Action</th></tr>";
echo "</thead>\n";
echo "<tbody>\n";

// Fetch patient's medication schedule
if (!$showCompleted) {
    $includeCompletedSql = ' AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())';
} else {
    $includeCompletedSql = '';
}
$sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date, ms.end_date, ms.medicine_id,
        (SELECT MAX(mi.taken_time) FROM hc_medicine_intake mi WHERE mi.schedule_id = ms.id) AS last_taken
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.patient_id = ? $includeCompletedSql
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
    $lastTaken = $row[6] ? $row[6] : null;
    $lastTakenNicely = formatDateNicely($lastTaken);
    $nextDue = "Not Yet Taken ";
    $nextDueTime = "0000-" . $medicationName;
    $nextDueTimeString = '';
    $nextDueClass = '';
    $warningIcon = '';

    // Calculate remaining doses, considering assumed past intake if enabled
    $remainingDoses = dosesRemaining($medicine_id, $schedule_id, $assumePastIntake, $start_date, $frequency);

    if ($lastTaken) {
        $secondsUntilDue = calculateSecondsUntilDue($lastTaken, $frequency);
        $hours = floor($secondsUntilDue / 3600);
        $minutes = floor(($secondsUntilDue % 3600) / 60);

        $nextDue = $hours > 0 ? "$hours hours " : "";
        $nextDue .= "$minutes minutes";
        $nextDueTime = sprintf("%06d-%06d", (60 * $hours) + $minutes, $schedule_id);

        $nextDueTimeString = calculateNextDueDate($lastTaken, $frequency);
        if ($secondsUntilDue <= 0) {
            $nextDue = "Overdue";
            $nextDueClass = 'bg-danger';
            $warningIcon = '<img src="images/bootstrap-icons/exclamation-triangle-fill.svg" alt="Warning">';
        } elseif ($secondsUntilDue <= $dueInNextHour) {
            $nextDueClass = 'bg-warning';
        }
        $nextDueNicely = formatDateNicely($nextDueTimeString);
        $nextDueTimeString = "<br>" . $nextDueNicely;

        if (!empty($end_date) && $end_date < date('Y-m-d')) {
            $nextDueTime = sprintf("999999-%06d", (60 * $hours) + $minutes, $schedule_id);
            $nextDue = "Completed";
            $nextDueTimeString = '';
            $warningIcon = '';
            $nextDueClass = '';
        } else if (!empty($end_date) && $end_date == date('Y-m-d')) {
            if ($secondsUntilDue > secondsUntilMidnight()) {
                if (!$showCompleted) {
                    continue;
                }
                $nextDueTime = sprintf("999999-%06d", (60 * $hours) + $minutes, $schedule_id);
                $nextDue = "Completed";
                $nextDueTimeString = '';
                $warningIcon = '';
                $nextDueClass = '';
            }
        }
    }

    $icon = '<img src="images/bootstrap-icons/journal-medical.svg" alt="Medicine Intake">';
    $editLink = '<a href="add_to_schedule.php?patient_id=' . htmlentities($patient_id) .
        '&schedule_id=' . htmlentities($schedule_id) .
        '&medicine_id=' . htmlentities($medicine_id) .
        '"><img src="images/bootstrap-icons/pencil.svg" alt="Edit"></a>';
    $recordIntakeLink = "<a href='record_intake.php?schedule_id=" . $row[0] . "&patient_id=" . $patient_id . "' title='Record Intake'>$icon</i></a>";

    $days = $remainingDoses['remainingDays'];
    $futureDate = date('M j, Y', strtotime("+$days days"));
    if (empty($remainingDoses['remainingDoses'])) {
        $remain = translate('None');
    } else {
        $remain = sprintf("%s doses, %d days,<br>Until %s", $remainingDoses['remainingDoses'], $remainingDoses['remainingDays'], $futureDate);
        if ($assumePastIntake) {
            $remain .= "<br>(Assuming past doses taken)";
        }
    }

    $medicationRows[$nextDueTime] .= "<tr class='" . $nextDueClass . "'>" .
        "<td>" . htmlspecialchars($row[1]) . "</td>" .
        "<td>" . htmlspecialchars($row[2]) . "</td>" .
        "<td>" . htmlspecialchars($lastTakenNicely) . "</td>" .
        "<td>" . $warningIcon . htmlspecialchars($nextDue) . $nextDueTimeString . "</td>" .
        "<td>$remain</td>" .
        "<td>$recordIntakeLink $editLink </td>" .
        "</tr>\n";
}

// Sort the array by key (the "Next Due" time)
ksort($medicationRows);

// Print the sorted rows
foreach ($medicationRows as $medicationRow) {
    echo $medicationRow;
}

echo "</tbody>";
echo "</table>\n";
echo "</div>\n";

echo "<p>";
if ($showCompleted) {
    echo "<a href=\"list_schedule.php?patient_id=" . htmlspecialchars($patient_id) . "\" class='btn btn-secondary'>Hide Completed Medications</a>\n";
} else {
    echo "<a href=\"list_schedule.php?patient_id=" . htmlspecialchars($patient_id) . "&show_completed=1\" class='btn btn-secondary'>Show Completed Medications</a>\n";
}
echo "<a href=\"add_to_schedule.php?patient_id=" . htmlspecialchars($patient_id) . "\" class='btn btn-primary'>Add Medication to Schedule</a></p>\n";

echo print_trailer();
?>