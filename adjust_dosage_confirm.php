<?php
require_once 'includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list_medications.php');
    exit();
}

print_header();

$patient_id = getPostValue('patient_id');
$old_schedule_id = getPostValue('old_schedule_id');
$medicine_id = getPostValue('medicine_id');
$frequency = getPostValue('frequency');
$unit_per_dose = getPostValue('unit_per_dose');
$effective_date = getPostValue('effective_date');
$overlap_count = getPostValue('overlap_count');

// Fetch context for display
$patient = getPatient($patient_id);

$sql = "SELECT m.name, COALESCE(ms.unit_per_dose, m.unit_per_dose) AS current_upd, ms.frequency AS current_freq
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.id = ? AND ms.patient_id = ?";
$rows = dbi_get_cached_rows($sql, [$old_schedule_id, $patient_id]);

if (empty($rows)) {
    die_miserable_death('Schedule not found.');
}

$medicineName = $rows[0][0];
$currentUpd = $rows[0][1];
$currentFreq = $rows[0][2];

$effectiveDateNice = date('M j, Y', strtotime($effective_date));
$updChanged = (floatval($unit_per_dose) != floatval($currentUpd));

echo "<h2>Confirm Dosage Adjustment</h2>\n";
echo "<div class='container mt-3'>\n";

// Warning card
echo "<div class='card border-warning mb-4'>\n";
echo "<div class='card-header bg-warning text-dark'>\n";
echo "<img src='images/bootstrap-icons/exclamation-triangle-fill.svg' alt='Warning' class='mr-2'>";
echo "<strong>Intake Records Found in Overlap Period</strong></div>\n";
echo "<div class='card-body'>\n";
echo "<p><strong>" . htmlspecialchars($medicineName) . "</strong> for <strong>" . htmlspecialchars($patient['name']) . "</strong></p>\n";
echo "<p>There are <strong>" . intval($overlap_count) . " intake record(s)</strong> between ";
echo "<strong>" . htmlspecialchars($effectiveDateNice) . "</strong> and today ";
echo "on the current schedule.</p>\n";

if ($updChanged) {
    echo "<p class='mb-0'>If reassigned, these doses will be counted at <strong>" . htmlspecialchars($unit_per_dose) . "</strong>/dose ";
    echo "instead of <strong>" . htmlspecialchars($currentUpd) . "</strong>/dose, ";
    echo "which will change the calculated inventory consumption for this period.</p>\n";
} else {
    echo "<p class='mb-0'>The unit per dose is unchanged, so inventory calculations will not be affected. ";
    echo "Reassigning will link these records to the new schedule for accurate tracking.</p>\n";
}

echo "</div>\n";
echo "</div>\n";

// Change summary
echo "<div class='card mb-4'>\n";
echo "<div class='card-header'>Change Summary</div>\n";
echo "<div class='card-body'>\n";
echo "<table class='table table-sm table-borderless mb-0'>\n";
echo "<tr><th style='width:40%'></th><th>Current</th><th>New</th></tr>\n";
echo "<tr><td><strong>Frequency</strong></td>";
echo "<td>" . htmlspecialchars($currentFreq) . "</td>";
echo "<td>" . ($frequency !== $currentFreq ? '<strong>' : '') . htmlspecialchars($frequency) . ($frequency !== $currentFreq ? '</strong>' : '') . "</td></tr>\n";
echo "<tr><td><strong>Unit per dose</strong></td>";
echo "<td>" . htmlspecialchars($currentUpd) . "</td>";
echo "<td>" . ($updChanged ? '<strong>' : '') . htmlspecialchars($unit_per_dose) . ($updChanged ? '</strong>' : '') . "</td></tr>\n";
echo "<tr><td><strong>Effective date</strong></td>";
echo "<td colspan='2'>" . htmlspecialchars($effectiveDateNice) . "</td></tr>\n";
echo "</table>\n";
echo "</div>\n";
echo "</div>\n";

// Choice form
echo "<form action='adjust_dosage_handler.php' method='POST'>\n";
print_form_key();
echo "<input type='hidden' name='patient_id' value='" . htmlspecialchars($patient_id) . "'>\n";
echo "<input type='hidden' name='old_schedule_id' value='" . htmlspecialchars($old_schedule_id) . "'>\n";
echo "<input type='hidden' name='medicine_id' value='" . htmlspecialchars($medicine_id) . "'>\n";
echo "<input type='hidden' name='frequency' value='" . htmlspecialchars($frequency) . "'>\n";
echo "<input type='hidden' name='unit_per_dose' value='" . htmlspecialchars($unit_per_dose) . "'>\n";
echo "<input type='hidden' name='effective_date' value='" . htmlspecialchars($effective_date) . "'>\n";
echo "<input type='hidden' name='confirmed' value='1'>\n";

echo "<div class='form-group'>\n";

echo "<div class='custom-control custom-radio mb-2'>\n";
echo "<input type='radio' id='reassignYes' name='reassign_intakes' value='yes' class='custom-control-input' checked>\n";
echo "<label class='custom-control-label' for='reassignYes'><strong>Reassign to new dosage</strong> (recommended)</label>\n";
echo "<small class='form-text text-muted ml-4'>The " . intval($overlap_count) . " intake record(s) will be moved to the new schedule";
if ($updChanged) {
    echo " and counted at " . htmlspecialchars($unit_per_dose) . "/dose";
}
echo ".</small>\n";
echo "</div>\n";

echo "<div class='custom-control custom-radio mb-2'>\n";
echo "<input type='radio' id='reassignNo' name='reassign_intakes' value='no' class='custom-control-input'>\n";
echo "<label class='custom-control-label' for='reassignNo'><strong>Keep on old schedule</strong></label>\n";
echo "<small class='form-text text-muted ml-4'>These records stay on the old schedule";
if ($updChanged) {
    echo " counted at " . htmlspecialchars($currentUpd) . "/dose";
}
echo ". The new schedule starts with no history.</small>\n";
echo "</div>\n";

echo "</div>\n";

echo "<div class='mt-4'>\n";
echo "<a href='list_schedule.php?patient_id=" . htmlspecialchars($patient_id) . "' class='btn btn-secondary mr-2'>Cancel</a>\n";
echo "<button type='submit' class='btn btn-warning'>Confirm Adjustment</button>\n";
echo "</div>\n";

echo "</form>\n";
echo "</div>\n";

echo print_trailer();
?>
