<?php

declare(strict_types=1);

require_once 'includes/init.php';
require_role('caregiver');

$schedule_id = (int) getGetValue('schedule_id');
$patient_id = (int) getGetValue('patient_id');

if ($schedule_id <= 0 || $patient_id <= 0) {
    header('Location: index.php');
    exit;
}

$sql = 'SELECT m.name FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.id = ?';
$rows = dbi_get_cached_rows($sql, [$schedule_id]);
$medName = !empty($rows) ? htmlspecialchars((string) $rows[0][0], ENT_QUOTES, 'UTF-8') : 'Unknown';

print_header();

echo "<div class='container mt-3'>\n";
echo "<h2>Pause Schedule: {$medName}</h2>\n";
echo "<p class='text-muted'>While paused, no doses are expected and no reminders will fire.</p>\n";

echo "<form action='pause_schedule_handler.php' method='POST'>\n";
print_form_key();
echo "<input type='hidden' name='schedule_id' value='" . htmlspecialchars((string) $schedule_id, ENT_QUOTES, 'UTF-8') . "'>\n";
echo "<input type='hidden' name='patient_id' value='" . htmlspecialchars((string) $patient_id, ENT_QUOTES, 'UTF-8') . "'>\n";

echo "<div class='form-group'>\n";
echo "<label for='start_date'>Start Date:</label>\n";
echo "<input type='date' name='start_date' id='start_date' class='form-control' required value='" . date('Y-m-d') . "'>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='end_date'>End Date (leave blank for open-ended):</label>\n";
echo "<input type='date' name='end_date' id='end_date' class='form-control'>\n";
echo "<small class='form-text text-muted'>Leave blank to pause indefinitely until you resume manually.</small>\n";
echo "</div>\n";

echo "<div class='form-group'>\n";
echo "<label for='reason'>Reason (optional):</label>\n";
echo "<input type='text' name='reason' id='reason' class='form-control' maxlength='255' placeholder='e.g. Vacation, groomer visit'>\n";
echo "</div>\n";

echo "<button type='submit' class='btn btn-warning'>Pause Schedule</button>\n";
echo " <a href='list_schedule.php?patient_id=" . urlencode((string) $patient_id) . "' class='btn btn-secondary'>Cancel</a>\n";

echo "</form>\n";
echo "</div>\n";

echo print_trailer();
