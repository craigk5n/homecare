<?php
require_once 'includes/init.php';
require_role('caregiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list_medications.php');
    exit;
}

$id = (int) (getPostValue('id') ?? 0);
if ($id < 1) {
    die_miserable_death('Missing medication id.');
}

// Refuse to delete a product that is still in use. The FKs cascade
// (ON DELETE CASCADE), so a raw DELETE would silently drop a patient's
// schedule and inventory history -- block instead and steer to merge.
$scheduleRows = dbi_get_cached_rows(
    'SELECT COUNT(*) FROM hc_medicine_schedules WHERE medicine_id = ?', [$id]);
$inventoryRows = dbi_get_cached_rows(
    'SELECT COUNT(*) FROM hc_medicine_inventory WHERE medicine_id = ?', [$id]);
$scheduleCount = (int) ($scheduleRows[0][0] ?? 0);
$inventoryCount = (int) ($inventoryRows[0][0] ?? 0);

if ($scheduleCount > 0 || $inventoryCount > 0) {
    die_miserable_death(
        'This medication is still in use (' . $scheduleCount . ' schedule(s), '
        . $inventoryCount . ' inventory record(s)) and cannot be deleted. '
        . 'Use Merge Medicines to consolidate duplicates instead.');
}

$nameRows = dbi_get_cached_rows(
    'SELECT name, dosage FROM hc_medicines WHERE id = ?', [$id]);
if (empty($nameRows)) {
    die_miserable_death('Medication not found.');
}

if (dbi_execute('DELETE FROM hc_medicines WHERE id = ?', [$id])) {
    audit_log('medicine.deleted', 'medicine', $id, [
        'name' => $nameRows[0][0],
        'dosage' => $nameRows[0][1],
    ]);
    do_redirect('list_medications.php');
} else {
    echo 'Error deleting medication. <br>' . dbi_error();
}
