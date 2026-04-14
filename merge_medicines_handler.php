<?php
require_once 'includes/init.php';
require_role('caregiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: merge_medicines.php');
    exit();
}

$primary_id = intval(getPostValue('primary_id'));
$duplicate_ids = isset($_POST['duplicate_ids']) ? array_map('intval', $_POST['duplicate_ids']) : [];
$duplicate_ids = array_filter($duplicate_ids, function($id) use ($primary_id) {
    return $id > 0 && $id !== $primary_id;
});

if (empty($primary_id) || empty($duplicate_ids)) {
    die_miserable_death('Missing primary or duplicate medicine IDs.');
}

// Verify primary exists
$sql = "SELECT id FROM hc_medicines WHERE id = ?";
$rows = dbi_get_cached_rows($sql, [$primary_id]);
if (empty($rows)) {
    die_miserable_death('Primary medicine not found.');
}

// Build placeholders for IN clause
$placeholders = implode(',', array_fill(0, count($duplicate_ids), '?'));

// Reassign schedules
$sql = "UPDATE hc_medicine_schedules SET medicine_id = ? WHERE medicine_id IN ($placeholders)";
$params = array_merge([$primary_id], $duplicate_ids);
if (!dbi_execute($sql, $params)) {
    die_miserable_death('Error reassigning schedules: ' . dbi_error());
}

// Reassign inventory
$sql = "UPDATE hc_medicine_inventory SET medicine_id = ? WHERE medicine_id IN ($placeholders)";
if (!dbi_execute($sql, $params)) {
    die_miserable_death('Error reassigning inventory: ' . dbi_error());
}

// Delete duplicate medicines (intake rows reference schedule_id, not medicine_id, so no change needed)
$sql = "DELETE FROM hc_medicines WHERE id IN ($placeholders)";
if (!dbi_execute($sql, $duplicate_ids)) {
    die_miserable_death('Error deleting duplicate medicines: ' . dbi_error());
}

$count = count($duplicate_ids);
audit_log('medicines.merged', 'medicine', $primary_id, [
    'duplicate_ids' => array_values($duplicate_ids),
    'duplicate_count' => $count,
]);
do_redirect('merge_medicines.php?merged=' . $count);
?>
