<?php
require_once 'includes/init.php';

$primary_id = getPostValue('primary_id');
$duplicate_ids = isset($_POST['duplicate_ids']) ? $_POST['duplicate_ids'] : [];

if (empty($primary_id) || empty($duplicate_ids)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing primary_id or duplicate_ids']);
    exit;
}

// Sanitize
$primary_id = intval($primary_id);
$duplicate_ids = array_map('intval', $duplicate_ids);
$duplicate_ids = array_filter($duplicate_ids, function($id) use ($primary_id) {
    return $id > 0 && $id !== $primary_id;
});

if (empty($duplicate_ids)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No valid duplicate IDs after filtering']);
    exit;
}

$response = [
    'primary' => null,
    'duplicates' => [],
];

// Get primary name
$sql = "SELECT id, name, dosage FROM hc_medicines WHERE id = ?";
$rows = dbi_get_cached_rows($sql, [$primary_id]);
if (!empty($rows)) {
    $response['primary'] = ['id' => $rows[0][0], 'name' => $rows[0][1], 'dosage' => $rows[0][2]];
}

// Get info for each duplicate
foreach ($duplicate_ids as $dup_id) {
    $sql = "SELECT id, name, dosage FROM hc_medicines WHERE id = ?";
    $rows = dbi_get_cached_rows($sql, [$dup_id]);
    if (empty($rows)) continue;

    $dup = ['id' => $rows[0][0], 'name' => $rows[0][1], 'dosage' => $rows[0][2]];

    // Count schedules
    $sql = "SELECT COUNT(*) FROM hc_medicine_schedules WHERE medicine_id = ?";
    $countRows = dbi_get_cached_rows($sql, [$dup_id]);
    $dup['schedule_count'] = intval($countRows[0][0]);

    // Count inventory rows
    $sql = "SELECT COUNT(*) FROM hc_medicine_inventory WHERE medicine_id = ?";
    $countRows = dbi_get_cached_rows($sql, [$dup_id]);
    $dup['inventory_count'] = intval($countRows[0][0]);

    // Count intake rows (via schedules)
    $sql = "SELECT COUNT(*) FROM hc_medicine_intake i
            JOIN hc_medicine_schedules s ON s.id = i.schedule_id
            WHERE s.medicine_id = ?";
    $countRows = dbi_get_cached_rows($sql, [$dup_id]);
    $dup['intake_count'] = intval($countRows[0][0]);

    $response['duplicates'][] = $dup;
}

header('Content-Type: application/json');
echo json_encode($response);
?>
