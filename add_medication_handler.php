<?php
require_once 'includes/init.php';
require_role('caregiver');

// Collect the form data
$name = getPostValue('name');
$dosage = getPostValue('dosage');

if (empty($name) || empty($dosage)) {
  die_miserable_death('All fields are required.');
}

// Insert query
$sql = "INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)";
if (dbi_execute($sql, [$name, $dosage])) {
    $newId = (int) ($GLOBALS['phpdbiConnection']->insert_id ?? 0);
    audit_log('medicine.created', 'medicine', $newId ?: null, [
        'name' => $name,
        'dosage' => $dosage,
    ]);
    do_redirect("list_medications.php");
} else {
    echo "Error adding medication. <br>" . dbi_error();
}
?>

