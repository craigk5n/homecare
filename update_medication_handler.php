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

$sql = "UPDATE hc_medicines SET name = ?, dosage = ?, drug_catalog_id = ? WHERE id = ?";
if (dbi_execute($sql, [$name, $dosage, $drugCatalogId, $id])) {
    audit_log('medicine.updated', 'medicine', (int) $id, [
        'name' => $name,
        'dosage' => $dosage,
        'drug_catalog_id' => $drugCatalogId,
    ]);
    do_redirect("list_medications.php");
} else {
    echo "Error updating medication. <br>" . dbi_error();
}
?>
