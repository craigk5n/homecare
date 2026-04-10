<?php
require_once 'includes/init.php';

// Initialize variables
$medicationId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$medication = ['name' => '', 'dosage' => '', 'frequency' => '', 'unit_per_dose' => ''];

if ($medicationId > 0) {
    // Fetch medication details for editing
    $sql = "SELECT id, name, dosage, frequency, unit_per_dose FROM hc_medicines WHERE id = ?";
    $medication = dbi_fetch_row(dbi_query($sql, [$medicationId]));
}

print_header();

$actionUrl = $medicationId > 0 ? "update_medication_handler.php" : "add_medication_handler.php";
?>
<h2><?= $medicationId > 0 ? 'Edit Medication' : 'Add Medication' ?></h2>
<div class='container mt-3'>
<form action='<?= htmlspecialchars($actionUrl) ?>' method='POST'>
<?php print_form_key(); ?>
    <input type='hidden' name='id' value='<?= $medicationId ?>'>
    <div class='form-group'>
        <label for='name'>Medication Name:</label>
        <input type='text' name='name' id='name' class='form-control' required value='<?= htmlspecialchars($medication['name']) ?>'>
    </div>
    <div class='form-group'>
        <label for='dosage'>Dosage:</label>
        <input type='text' name='dosage' id='dosage' class='form-control' required value='<?= htmlspecialchars($medication['dosage']) ?>'>
    </div>
    <div class='form-group'>
        <label for='frequency'>Frequency:</label>
        <input type='text' name='frequency' id='frequency' class='form-control' required value='<?= htmlspecialchars($medication['frequency']) ?>'>
    </div>
    <div class='form-group'>
        <label for='unit_per_dose'>Unit Per Dose:</label>
        <input type='number' step='0.01' name='unit_per_dose' id='unit_per_dose' class='form-control' required value='<?= htmlspecialchars($medication['unit_per_dose']) ?>'>
    </div>
    <button type='submit' class='btn btn-primary'><?= $medicationId > 0 ? 'Update' : 'Add' ?> Medication</button>
</form>
</div>
<?php
echo print_trailer();
?>
