<?php
require_once 'includes/init.php';
require_role('caregiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inventory_dashboard.php');
    exit();
}

$medicine_id = intval(getPostValue('medicine_id'));
$refill_quantity = getPostValue('refill_quantity');
$new_stock = getPostValue('new_stock');
$note = getPostValue('note');
$refill_source = getPostValue('refill_source') ?: 'manual';

if (empty($medicine_id) || $refill_quantity === '' || $new_stock === '') {
    die_miserable_death('Missing required fields.');
}

$sql = "INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock, note)
        VALUES (?, ?, ?, ?)";
if (!dbi_execute($sql, [$medicine_id, $refill_quantity, $new_stock, $note])) {
    die_miserable_death('Error recording refill: ' . dbi_error());
}

$newId = (int) mysqli_insert_id($GLOBALS['c']);
audit_log('inventory.refilled', 'inventory', $newId ?: null, [
    'kind' => 'refill',
    'source' => $refill_source,
    'medicine_id' => $medicine_id,
    'refill_quantity' => (float) $refill_quantity,
    'new_stock' => (float) $new_stock,
    'note' => $note,
]);

do_redirect('inventory_dashboard.php');
?>
