<?php
require_once 'includes/init.php';
require_role('caregiver');

$id = getPostValue('id');
$name = getPostValue('name');
$dosage = getPostValue('dosage');
$drugCatalogId = getPostValue('drug_catalog_id');
$drugCatalogId = ($drugCatalogId !== '' && $drugCatalogId !== null) ? (int) $drugCatalogId : null;

if (empty($id) || empty($name) || empty($dosage)) {
  die_miserable_death('All fields are required.');
}

// Block an edit that would collide with a different existing medicine
// (uq_medicines_name_dosage). Friendly message instead of a SQL error.
$clash = dbi_get_cached_rows(
    "SELECT id FROM hc_medicines WHERE name = ? AND dosage = ? AND id <> ?",
    [$name, $dosage, $id]);
if (!empty($clash)) {
    die_miserable_death('Another medication named "' . htmlspecialchars($name)
        . '" with dosage "' . htmlspecialchars($dosage)
        . '" already exists. Use Merge Medicines to combine them.');
}

$sql = "UPDATE hc_medicines SET name = ?, dosage = ?, drug_catalog_id = ? WHERE id = ?";
if (dbi_execute($sql, [$name, $dosage, $drugCatalogId, $id], false, false)) {
    audit_log('medicine.updated', 'medicine', (int) $id, [
        'name' => $name,
        'dosage' => $dosage,
        'drug_catalog_id' => $drugCatalogId,
    ]);
    do_redirect("list_medications.php");
} else {
    die_miserable_death('Could not update medication. A medication with this '
        . 'name and dosage may already exist.');
}
?>
