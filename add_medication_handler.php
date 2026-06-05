<?php
require_once 'includes/init.php';
require_role('caregiver');

// Collect the form data
$name = getPostValue('name');
$dosage = getPostValue('dosage');
$drugCatalogId = getPostValue('drug_catalog_id');
$drugCatalogId = ($drugCatalogId !== '' && $drugCatalogId !== null) ? (int) $drugCatalogId : null;

if (empty($name) || empty($dosage)) {
  die_miserable_death('All fields are required.');
}

// Reject duplicates up front (uq_medicines_name_dosage also enforces this at
// the DB level, but this gives a friendly message instead of a SQL error).
$existing = dbi_get_cached_rows(
    "SELECT id FROM hc_medicines WHERE name = ? AND dosage = ?", [$name, $dosage]);
if (!empty($existing)) {
    die_miserable_death('A medication named "' . htmlspecialchars($name)
        . '" with dosage "' . htmlspecialchars($dosage)
        . '" already exists. Edit the existing entry instead of adding a duplicate.');
}

// Insert query. fatalOnError=false so a concurrent insert that trips the
// unique constraint is reported gracefully rather than dumping a SQL error.
$sql = "INSERT INTO hc_medicines (name, dosage, drug_catalog_id) VALUES (?, ?, ?)";
if (dbi_execute($sql, [$name, $dosage, $drugCatalogId], false, false)) {
    $newId = (int) ($GLOBALS['phpdbiConnection']->insert_id ?? 0);
    audit_log('medicine.created', 'medicine', $newId ?: null, [
        'name' => $name,
        'dosage' => $dosage,
        'drug_catalog_id' => $drugCatalogId,
    ]);
    do_redirect("list_medications.php");
} else {
    // Most likely cause: the unique constraint rejected a duplicate that
    // slipped past the pre-check (concurrent submit).
    die_miserable_death('Could not add medication. A medication with this '
        . 'name and dosage may already exist.');
}
?>

