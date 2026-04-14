<?php
require_once 'includes/init.php';
require_role('caregiver');

$medicine_id = getPostValue('medicine_id');
$quantity = getPostValue('quantity');
$current_stock = getPostValue('current_stock');
$note = getPostValue('note');
$inventory_id = getPostValue('id');
$delete = getPostValue('delete');

if (!empty($delete) && $delete === 'yes' && !empty($inventory_id)) {
    // Delete the inventory record
    $deleteSql = "DELETE FROM hc_medicine_inventory WHERE id = ?";
    if (!dbi_execute($deleteSql, [$inventory_id])) {
        echo "Error deleting inventory: " . dbi_error();
    } else {
        audit_log('inventory.updated', 'inventory', (int) $inventory_id, [
            'kind' => 'delete',
        ]);
    }
} elseif (empty($inventory_id)) {
    // Insert a new inventory record into the database
    $insertSql = "INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock, note) VALUES (?, ?, ?, ?)";
    $params = [$medicine_id, $quantity, $current_stock, $note];
    if (!dbi_execute($insertSql, $params)) {
        echo "Error adding new inventory: " . dbi_error();
    } else {
        $newId = (int) mysqli_insert_id($GLOBALS['c']);
        audit_log('inventory.updated', 'inventory', $newId ?: null, [
            'kind' => 'insert',
            'medicine_id' => (int) $medicine_id,
            'quantity' => (float) $quantity,
            'current_stock' => (float) $current_stock,
        ]);
    }
} else {
    // Update existing inventory entry
    $updateSql = "UPDATE hc_medicine_inventory SET quantity = ?, current_stock = ?, note = ? WHERE id = ?";
    $params = [$quantity, $current_stock, $note, $inventory_id];
    if (!dbi_execute($updateSql, $params)) {
        echo "Error updating inventory: " . dbi_error();
    } else {
        audit_log('inventory.updated', 'inventory', (int) $inventory_id, [
            'kind' => 'update',
            'quantity' => (float) $quantity,
            'current_stock' => (float) $current_stock,
        ]);
    }
}

// Redirect back to the inventory list page or dashboard
do_redirect("list_medications.php");
?>
