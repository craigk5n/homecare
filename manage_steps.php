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

$sql = 'SELECT ms.id, m.name, ms.frequency, ms.unit_per_dose, ms.start_date
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.id = ?';
$rows = dbi_get_cached_rows($sql, [$schedule_id]);
if (empty($rows)) {
    die_miserable_death('Schedule not found.');
}
$medName = htmlspecialchars((string) $rows[0][1], ENT_QUOTES, 'UTF-8');
$baseUpd = (float) $rows[0][3];
$schedStart = (string) $rows[0][4];

$db = new \HomeCare\Database\DbiAdapter();
$stepRepo = new \HomeCare\Repository\StepRepository($db);
$steps = $stepRepo->getForSchedule($schedule_id);

print_header();

echo "<div class='container mt-3'>\n";
echo "<h2>Dose Steps: {$medName}</h2>\n";
echo "<p class='text-muted'>Base dose: " . htmlspecialchars((string) $baseUpd, ENT_QUOTES, 'UTF-8')
    . " units/dose (from schedule start " . htmlspecialchars($schedStart, ENT_QUOTES, 'UTF-8') . ")</p>\n";

if ($steps !== []) {
    echo "<table class='table table-sm'>\n";
    echo "<thead><tr><th>Start Date</th><th>Units/Dose</th><th>Note</th><th></th></tr></thead>\n";
    echo "<tbody>\n";
    foreach ($steps as $step) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($step['start_date'], ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars((string) $step['unit_per_dose'], ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($step['note'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>";
        echo "<form method='POST' action='manage_steps_handler.php' class='d-inline'>";
        print_form_key();
        echo "<input type='hidden' name='action' value='delete'>";
        echo "<input type='hidden' name='step_id' value='" . $step['id'] . "'>";
        echo "<input type='hidden' name='schedule_id' value='" . $schedule_id . "'>";
        echo "<input type='hidden' name='patient_id' value='" . $patient_id . "'>";
        echo "<button type='submit' class='btn btn-sm btn-outline-danger' onclick='return confirm(\"Remove this step?\")'>Remove</button>";
        echo "</form>";
        echo "</td></tr>\n";
    }
    echo "</tbody></table>\n";
} else {
    echo "<p class='text-muted'>No dose steps — using the base schedule dose throughout.</p>\n";
}

echo "<h4 class='mt-4'>Add Step</h4>\n";
echo "<form action='manage_steps_handler.php' method='POST'>\n";
print_form_key();
echo "<input type='hidden' name='action' value='add'>\n";
echo "<input type='hidden' name='schedule_id' value='" . $schedule_id . "'>\n";
echo "<input type='hidden' name='patient_id' value='" . $patient_id . "'>\n";

echo "<div class='form-row'>\n";
echo "<div class='form-group col-md-3'>\n";
echo "<label for='start_date'>Effective Date:</label>\n";
echo "<input type='date' name='start_date' id='start_date' class='form-control' required value='" . date('Y-m-d') . "'>\n";
echo "</div>\n";

echo "<div class='form-group col-md-3'>\n";
echo "<label for='unit_per_dose'>Units/Dose:</label>\n";
echo "<input type='number' step='0.001' min='0.001' name='unit_per_dose' id='unit_per_dose' class='form-control' required>\n";
echo "</div>\n";

echo "<div class='form-group col-md-4'>\n";
echo "<label for='note'>Note (optional):</label>\n";
echo "<input type='text' name='note' id='note' class='form-control' maxlength='255' placeholder='e.g. Week 2: increase'>\n";
echo "</div>\n";

echo "<div class='form-group col-md-2 d-flex align-items-end'>\n";
echo "<button type='submit' class='btn btn-primary'>Add Step</button>\n";
echo "</div>\n";
echo "</div>\n";

echo "</form>\n";

echo "<div class='mt-3'>\n";
echo "<a href='list_schedule.php?patient_id=" . urlencode((string) $patient_id) . "' class='btn btn-secondary'>Back to Schedule</a>\n";
echo "</div>\n";
echo "</div>\n";

echo print_trailer();
