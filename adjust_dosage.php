<?php
require_once 'includes/init.php';

print_header();

$frequencies = [
    '1d' => '1d - once daily',
    '12h' => '12h - twice daily (every 12 hours)',
    '8h' => '8h - three times daily (every 8 hours)',
    '6h' => '6h - four times daily (every 6 hours)',
    '4h' => '4h - every 4 hours'
];

$patient_id = getIntValue('patient_id');
$schedule_id = getIntValue('schedule_id');

if (empty($patient_id) || empty($schedule_id)) {
    die_miserable_death('Missing required patient_id or schedule_id');
}

$patient = getPatient($patient_id);

// Fetch the current schedule with medicine details
$sql = "SELECT ms.id, ms.medicine_id, ms.frequency, ms.start_date, ms.end_date,
        ms.unit_per_dose, m.name, m.dosage
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.id = ? AND ms.patient_id = ?";
$rows = dbi_get_cached_rows($sql, [$schedule_id, $patient_id]);

if (empty($rows)) {
    die_miserable_death('Schedule not found');
}

$currentFrequency = $rows[0][2];
$startDate = $rows[0][3];
$currentUnitPerDose = $rows[0][5];
$medicineName = $rows[0][6];
$dosageText = $rows[0][7];
$medicine_id = $rows[0][1];

$today = date('Y-m-d');

echo "<h2>Adjust Dosage</h2>\n";
echo "<div class='container mt-3'>\n";

// Current settings card (read-only reference)
echo "<div class='card mb-4'>\n";
echo "<div class='card-header'><strong>" . htmlspecialchars($medicineName) . "</strong>";
echo " &mdash; " . htmlspecialchars($patient['name']) . "</div>\n";
echo "<div class='card-body'>\n";
echo "<p class='mb-1'><strong>Current dosage:</strong> " . htmlspecialchars($dosageText) . "</p>\n";
echo "<p class='mb-1'><strong>Current frequency:</strong> " . htmlspecialchars($currentFrequency) . "</p>\n";
echo "<p class='mb-1'><strong>Unit per dose:</strong> " . htmlspecialchars($currentUnitPerDose) . "</p>\n";
echo "<p class='mb-0'><strong>Active since:</strong> " . htmlspecialchars($startDate) . "</p>\n";
echo "</div>\n";
echo "</div>\n";

// New settings form
echo "<form action='adjust_dosage_handler.php' method='POST'>\n";
print_form_key();
echo "<input type='hidden' name='patient_id' value='" . htmlspecialchars($patient_id) . "'>\n";
echo "<input type='hidden' name='old_schedule_id' value='" . htmlspecialchars($schedule_id) . "'>\n";
echo "<input type='hidden' name='medicine_id' value='" . htmlspecialchars($medicine_id) . "'>\n";

echo "<h5>New Settings</h5>\n";

echo "<div class='form-group'>\n";
echo "<label for='frequency'>Frequency:</label>\n";
echo "<select name='frequency' id='frequency' class='form-control'>\n";
foreach ($frequencies as $value => $description) {
    $selected = ($value == $currentFrequency) ? ' selected' : '';
    echo "<option value='" . htmlspecialchars($value) . "'$selected>" . htmlspecialchars($description) . "</option>\n";
}
echo "</select>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='unit_per_dose'>Unit per dose:</label>\n";
echo "<input type='number' name='unit_per_dose' id='unit_per_dose' class='form-control' step='0.01' min='0.01' required";
echo " value='" . htmlspecialchars($currentUnitPerDose) . "'>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='effective_date'>Effective date:</label>\n";
echo "<input type='date' name='effective_date' id='effective_date' class='form-control' required";
echo " value='" . htmlspecialchars($today) . "'>\n";
echo "<small class='form-text text-muted'>The current schedule will end on this date and the new one will begin.</small>\n";
echo "</div>\n";

echo "<div class='mt-4'>\n";
echo "<a href='list_schedule.php?patient_id=" . htmlspecialchars($patient_id) . "' class='btn btn-secondary mr-2'>Cancel</a>\n";
echo "<button type='submit' class='btn btn-warning'>Adjust Dosage</button>\n";
echo "</div>\n";

echo "</form>\n";
echo "</div>\n";

echo print_trailer();
?>
