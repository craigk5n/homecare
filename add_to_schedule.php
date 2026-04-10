<?php
require_once 'includes/init.php';

print_header();

// TODO: Move this to a shared file
$frequencies = [
    '1d' => '1d - once daily',
    '2d' => '2d - twice daily',
    '3d' => '3d - three times daily',
    '12h' => '12h - every 12 hours',
    '8h' => '8h - every 8 hours',
    '6h' => '6h - every 6 hours',
    '4h' => '4h - every 4 hours'
];


$patient_id = getIntValue('patient_id');
$medicine_id = getIntValue('medicine_id');
$schedule_id = getIntValue('schedule_id');

// Fetch patients
$patientsSql = "SELECT id, name FROM hc_patients WHERE is_active = TRUE ORDER BY name ASC";
$patientsResult = dbi_query($patientsSql);

// Fetch medications
$medicationsSql = "SELECT id, name FROM hc_medicines ORDER BY name ASC";
$medicationsResult = dbi_query($medicationsSql);

// If medicine id specified, load that
if (!empty($medicine_id) && !empty($schedule_id)) {
  echo "<h2>Edit Medication to Patient Schedule</h2>\n";
  $sql = 'SELECT id, start_date, end_date, frequency FROM hc_medicine_schedules ' .
    'WHERE patient_id = ? and medicine_id = ? AND id = ?';
  $rows = dbi_get_cached_rows($sql, [$patient_id, $medicine_id, $schedule_id]);
  //echo "<pre>"; print_r($rows); echo "</pre>";
  $start_date = $rows[0][1];
  $end_date = $rows[0][2];
  $frequency = $rows[0][3];
} else {
  echo "<h2>Add Medication to Patient Schedule</h2>\n";
  $start_date = $end_date = '';
  $frequency = '1d'; // default
}

echo "<div class='container mt-3'>\n";
echo "<form action='add_to_schedule_handler.php' method='POST'>\n";
print_form_key();

// Patient selection
echo "<div class='form-group'>\n";
echo "<label for='patient_id'>Select Patient:</label>\n";
echo "<select name='patient_id' id='patient_id' class='form-control'>\n";
while ($patient = dbi_fetch_row($patientsResult)) {
    echo "<option " . ($patient[0] == $patient_id ? " selected ": "") .
      " value='" . htmlspecialchars($patient[0]) . "'>" . htmlspecialchars($patient[1]) . "</option>\n";
}
echo "</select>\n";
echo "</div>\n";

if (!empty($medicine_id) && !empty($schedule_id)) {
  echo "<input type=\"hidden\" name=\"schedule_id\" value=\"$schedule_id\">\n";
}

// Medication selection
echo "<div class='form-group'>\n";
echo "<label for='medicine_id'>Select Medication:</label>\n";
echo "<select name='medicine_id' id='medicine_id' class='form-control' required>\n";
while ($medicine = dbi_fetch_row($medicationsResult)) {
    echo '<option ' . ($medicine[0] == $medicine_id ? " selected " : "" ) . 'value="' . htmlspecialchars($medicine[0]) . '">' .
        htmlspecialchars($medicine[1]) . "</option>\n";
}
echo "</select>\n";
echo "</div>\n";

// Schedule dates and frequency
echo "<div class='form-group'>\n";
echo "<label for='start_date'>Start Date:</label>\n";
echo "Start date: $start_date <br>";
echo "<input type='date' name='start_date' id='start_date' class='form-control' required" .
  (!empty($start_date) ? " value=\"$start_date\" " : '') .
  ">\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='end_date'>End Date:</label>\n";
echo "<input type='date' name='end_date' id='end_date' class='form-control'" .
  (!empty($end_date) ? " value=\"$end_date\" " : '') .
  ">\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='frequency'>Frequency:</label>\n";
echo "<select name='frequency' id='frequency' class='form-control'>\n";
foreach ($frequencies as $value => $description) {
  $selected = ($value == $frequency) ? ' selected' : '';
  echo "<option value='$value'$selected>$description</option>\n";
}
echo "</select>\n";
echo "</div>\n";

// Submit button
if (!empty($medicine_id) && !empty($schedule_id)) {
  echo "<button type='submit' class='btn btn-primary'>Update Schedule</button>\n";
} else {
  echo "<button type='submit' class='btn btn-primary'>Add Schedule</button>\n";
}
echo "</form>\n";
echo "</div>\n";

echo print_trailer();
?>

