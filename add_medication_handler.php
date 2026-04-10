<?php
require_once 'includes/init.php';

// Collect the form data
$name = getPostValue('name');
$dosage = getPostValue('dosage');
$frequency = getPostValue('frequency');
$unit_per_dose = getPostValue('unit_per_dose');

if (empty($name) || empty($dosage) || empty($unit_per_dose) || empty($frequency)) {
  die_miserable_death('All fields are required.');
}

// Insert query
$sql = "INSERT INTO hc_medicines (name, dosage, frequency, unit_per_dose) VALUES (?, ?, ?, ?)";
if (dbi_execute($sql, [$name, $dosage, $frequency, $unit_per_dose])) {
    do_redirect("list_medications.php");
} else {
    echo "Error adding medication. <br>" . dbi_error();
}
?>

