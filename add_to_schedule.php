<?php
require_once 'includes/init.php';

print_header();

// TODO: Move this to a shared file
$frequencies = [
    '1d' => '1d - once daily',
    '12h' => '12h - twice daily (every 12 hours)',
    '8h' => '8h - three times daily (every 8 hours)',
    '6h' => '6h - four times daily (every 6 hours)',
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
  $sql = 'SELECT id, start_date, end_date, frequency, unit_per_dose, is_prn, dose_basis, cycle_on_days, cycle_off_days FROM hc_medicine_schedules ' .
    'WHERE patient_id = ? and medicine_id = ? AND id = ?';
  $rows = dbi_get_cached_rows($sql, [$patient_id, $medicine_id, $schedule_id]);
  $start_date = $rows[0][1];
  $end_date = $rows[0][2];
  $frequency = $rows[0][3];
  $unit_per_dose = $rows[0][4];
  $is_prn = ($rows[0][5] ?? 'N') === 'Y';
  $dose_basis = $rows[0][6] ?? 'fixed';
  $cycle_on_days = $rows[0][7] ?? '';
  $cycle_off_days = $rows[0][8] ?? '';
} else {
  echo "<h2>Add Medication to Patient Schedule</h2>\n";
  $start_date = $end_date = '';
  $frequency = '1d'; // default
  $unit_per_dose = '1.00';
  $is_prn = false;
  $dose_basis = 'fixed';
  $cycle_on_days = '';
  $cycle_off_days = '';
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

// HC-120: PRN (as-needed) schedules have no fixed cadence. When the
// checkbox is on, the frequency dropdown is hidden (the browser won't
// submit its value, so the handler sees it as empty and stores NULL).
echo "<div class='form-group form-check'>\n";
echo "<input type='checkbox' class='form-check-input' name='is_prn' id='is_prn' value='1'"
  . ($is_prn ? ' checked' : '') . ">\n";
echo "<label class='form-check-label' for='is_prn'>Take as needed (PRN) "
  . "&mdash; no schedule, no reminders</label>\n";
echo "</div>\n";

echo "<div class='form-group' id='frequency-group'" . ($is_prn ? " style='display:none'" : '') . ">\n";
echo "<label for='frequency'>Frequency:</label>\n";
echo "<select name='frequency' id='frequency' class='form-control'>\n";
foreach ($frequencies as $value => $description) {
  $selected = ($value == $frequency) ? ' selected' : '';
  echo "<option value='$value'$selected>$description</option>\n";
}
echo "</select>\n";
echo "</div>\n";

// Show/hide the frequency row in response to the PRN checkbox. No
// network call; pure DOM toggle. When PRN is on, we also clear the
// `name` attribute on the <select> so the browser omits it from the
// submitted form and the handler treats frequency as absent.
echo <<<HTML
<script>
(function () {
  var box = document.getElementById('is_prn');
  var wrap = document.getElementById('frequency-group');
  var sel = document.getElementById('frequency');
  if (!box || !wrap || !sel) return;
  function sync() {
    if (box.checked) {
      wrap.style.display = 'none';
      sel.removeAttribute('name');
    } else {
      wrap.style.display = '';
      sel.setAttribute('name', 'frequency');
    }
  }
  box.addEventListener('change', sync);
  sync();
})();
</script>
HTML;


echo "<div class='form-group'>\n";
echo "<label for='unit_per_dose'>Unit Per Dose:</label>\n";
echo "<input type='number' step='0.01' min='0.01' name='unit_per_dose' id='unit_per_dose' class='form-control' required";
echo " value='" . htmlspecialchars($unit_per_dose) . "'>\n";
echo "<small class='form-text text-muted' id='unit_per_dose_help'>Number of tablets/units consumed per dose</small>\n";
echo "</div>\n";

// HC-113: dose basis (fixed amount vs weight-based)
echo "<div class='form-group'>\n";
echo "<label for='dose_basis'>Dose Basis:</label>\n";
echo "<select name='dose_basis' id='dose_basis' class='form-control'>\n";
$fixedSelected = $dose_basis === 'fixed' ? ' selected' : '';
$perKgSelected = $dose_basis === 'per_kg' ? ' selected' : '';
echo "<option value='fixed'{$fixedSelected}>Fixed amount</option>\n";
echo "<option value='per_kg'{$perKgSelected}>Per kg body weight (mg/kg)</option>\n";
echo "</select>\n";
echo "<small class='form-text text-muted'>Per-kg multiplies unit_per_dose by the patient's weight.</small>\n";
echo "</div>\n";

// HC-121: optional cycle dosing ("3 weeks on, 1 week off")
echo "<fieldset class='border p-2 mb-3'>\n";
echo "<legend class='w-auto px-2' style='font-size:1rem'>Cycle (optional)</legend>\n";
echo "<div class='form-row'>\n";
echo "<div class='form-group col-md-6'>\n";
echo "<label for='cycle_on_days'>Days on:</label>\n";
echo "<input type='number' min='1' name='cycle_on_days' id='cycle_on_days' class='form-control' placeholder='e.g. 21'"
    . (!empty($cycle_on_days) ? " value='" . htmlspecialchars((string) $cycle_on_days, ENT_QUOTES, 'UTF-8') . "'" : '')
    . ">\n";
echo "</div>\n";
echo "<div class='form-group col-md-6'>\n";
echo "<label for='cycle_off_days'>Days off:</label>\n";
echo "<input type='number' min='1' name='cycle_off_days' id='cycle_off_days' class='form-control' placeholder='e.g. 7'"
    . (!empty($cycle_off_days) ? " value='" . htmlspecialchars((string) $cycle_off_days, ENT_QUOTES, 'UTF-8') . "'" : '')
    . ">\n";
echo "</div>\n";
echo "</div>\n";
echo "<small class='form-text text-muted'>Leave blank for continuous dosing. When set, the schedule alternates between on-days (doses expected) and off-days (no doses).</small>\n";
echo "</fieldset>\n";

echo <<<'HTML'
<script>
(function () {
  var sel = document.getElementById('dose_basis');
  var help = document.getElementById('unit_per_dose_help');
  if (!sel || !help) return;
  function sync() {
    help.textContent = sel.value === 'per_kg'
      ? 'Dose in mg/kg — will be multiplied by patient weight'
      : 'Number of tablets/units consumed per dose';
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
HTML;

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

