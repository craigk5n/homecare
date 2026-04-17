<?php
/**
 * Record intake at NOW() for all currently-overdue doses.
 *
 * Called via POST from the dashboard "Mark all caught up" button.
 * Expects: schedule_ids[] (array of schedule IDs to mark).
 */

declare(strict_types=1);

require_once 'includes/init.php';
require_role('caregiver');

$scheduleIds = $_POST['schedule_ids'] ?? [];
if (!is_array($scheduleIds)) {
    $scheduleIds = [];
}
$scheduleIds = array_map('intval', $scheduleIds);
$scheduleIds = array_filter($scheduleIds, static fn(int $id): bool => $id > 0);

if (empty($scheduleIds)) {
    header('Location: dashboard.php');
    exit;
}

$now = date('Y-m-d H:i:s');
$recorded = 0;

foreach ($scheduleIds as $scheduleId) {
    // Verify the schedule exists and is active.
    $check = dbi_get_cached_rows(
        "SELECT ms.id, ms.patient_id FROM hc_medicine_schedules ms
         WHERE ms.id = ? AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())",
        [$scheduleId],
    );
    if (empty($check[0])) {
        continue;
    }

    $sql = 'INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES (?, ?, ?)';
    if (dbi_execute($sql, [$scheduleId, $now, 'Bulk catch-up from dashboard'])) {
        $newId = (int) mysqli_insert_id($GLOBALS['c']);
        audit_log('intake.recorded', 'intake', $newId ?: null, [
            'schedule_id' => $scheduleId,
            'patient_id' => (int) ($check[0][1] ?? 0),
            'taken_time' => $now,
            'via' => 'bulk_catchup',
        ]);
        $recorded++;
    }
}

// Flash-style feedback via query param (dashboard reads it).
header('Location: dashboard.php?caught_up=' . $recorded);
exit;
