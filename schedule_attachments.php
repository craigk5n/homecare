<?php
require_once 'includes/init.php';

$schedule_id = (int) (getIntValue('schedule_id') ?? 0);
$patient_id = (int) (getIntValue('patient_id') ?? 0);

if ($schedule_id < 1 || $patient_id < 1) {
    die_miserable_death('Missing schedule_id or patient_id');
}

$patient = getPatient($patient_id);
$patientName = $patient['name'];

$sql = "SELECT m.name FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON m.id = ms.medicine_id
        WHERE ms.id = ?";
$rows = dbi_get_cached_rows($sql, [$schedule_id]);
$medName = !empty($rows) ? $rows[0][0] : 'Unknown';

print_header();

echo "<div class='container mt-3'>\n";
echo "<h2>Attachments</h2>\n";
echo "<p>" . htmlspecialchars($medName) . " &mdash; " . htmlspecialchars($patientName) . "</p>\n";
echo "<p><a href='list_schedule.php?patient_id=" . urlencode((string) $patient_id)
    . "' class='btn btn-sm btn-outline-secondary'>&larr; Back to schedule</a></p>\n";

$att_owner_type = 'schedule';
$att_owner_id = $schedule_id;
$att_return_url = 'schedule_attachments.php?schedule_id=' . $schedule_id . '&patient_id=' . $patient_id;
include __DIR__ . '/includes/attachment_widget.php';

echo "</div>\n";
echo print_trailer();
