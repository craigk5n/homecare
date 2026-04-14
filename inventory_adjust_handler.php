<?php
require_once 'includes/init.php';
require_role('caregiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inventory_dashboard.php');
    exit();
}

$medicine_id = intval(getPostValue('medicine_id'));
$actual_count = getPostValue('actual_count');
$note = getPostValue('note');

if (empty($medicine_id) || $actual_count === '') {
    die_miserable_death('Missing required fields.');
}

// Insert a new checkpoint with quantity=0 (no new product added)
$sql = "INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock, note)
        VALUES (?, 0, ?, ?)";
if (!dbi_execute($sql, [$medicine_id, $actual_count, $note])) {
    die_miserable_death('Error adjusting stock: ' . dbi_error());
}

$newId = (int) mysqli_insert_id($GLOBALS['c']);
audit_log('inventory.updated', 'inventory', $newId ?: null, [
    'kind' => 'adjust',
    'medicine_id' => $medicine_id,
    'actual_count' => (float) $actual_count,
    'note' => $note,
]);

do_redirect('inventory_dashboard.php');
?>
