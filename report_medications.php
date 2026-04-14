<?php
/**
 * HC-061: report_medications.php using the shared page shell.
 *
 * Sticky header (patient + Print + Show-completed toggle +
 * Assume-past toggle), desktop table, mobile cards. Status stripe
 * per row by remainingDays bucket so low-supply meds visually pop.
 */

require_once 'includes/init.php';

$patient_id = (int) (getIntValue('patient_id') ?? 0);
$patient = getPatient($patient_id);
$patientName = $patient['name'];

$showCompleted = !empty(getIntValue('show_completed'));
$assumePastIntake = !empty(getIntValue('assume_past_intake'));

$sql = "SELECT ms.id, m.name, ms.frequency, ms.start_date, ms.end_date, ms.medicine_id,
        (SELECT MAX(mi.taken_time) FROM hc_medicine_intake mi WHERE mi.schedule_id = ms.id) AS last_taken
        FROM hc_medicine_schedules ms
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.patient_id = ?"
        . ($showCompleted ? "" : " AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())")
        . " ORDER BY last_taken ASC";
$rawRows = dbi_get_cached_rows($sql, [$patient_id]);

// Build display rows and sort by days-until-empty so the most urgent
// medicines end up at the top.
$rows = [];
foreach ($rawRows as $row) {
    $scheduleId = (int) $row[0];
    $medicineId = (int) $row[5];
    $name = (string) $row[1];
    $frequency = (string) $row[2];
    $startDate = (string) $row[3];
    $endDate = $row[4] === null ? null : (string) $row[4];
    $lastTaken = $row[6] !== null ? formatDateNicely((string) $row[6]) : 'Not yet taken';

    $remaining = dosesRemaining($medicineId, $scheduleId, $assumePastIntake, $startDate, $frequency);
    $days = (int) $remaining['remainingDays'];
    $doses = $remaining['remainingDoses'];

    $isEnded = $endDate !== null && $endDate < date('Y-m-d');
    if ($isEnded) {
        $remainText = sprintf('%s doses, %d days', $doses, $days);
        $sortKey = sprintf('Z-%06d %s', $days, $name);
    } else {
        $until = date('M j, Y', strtotime("+$days days"));
        $remainText = sprintf('Until %s — %s doses, %d days', $until, $doses, $days);
        $sortKey = sprintf('%06d %s', $days, $name);
    }
    if ($assumePastIntake && (float) $doses > 0) {
        $remainText .= ' <span class="text-muted">(assuming past doses taken)</span>';
    }

    // Status stripe by days-of-supply.
    $statusClass = 'status-ok';
    if ($isEnded) {
        $statusClass = 'status-done';
    } elseif ($days <= 3) {
        $statusClass = 'status-overdue';
    } elseif ($days <= 7) {
        $statusClass = 'status-due-soon';
    }

    $rows[$sortKey] = [
        'name' => $name,
        'frequency' => $frequency,
        'last_taken' => $lastTaken,
        'remain' => $remainText,
        'status' => $statusClass,
    ];
}
ksort($rows);

print_header();

// Toggle URLs preserve the current state of the OTHER toggle.
$toggleShowUrl = 'report_medications.php?patient_id=' . $patient_id
    . ($assumePastIntake ? '&assume_past_intake=1' : '')
    . ($showCompleted ? '' : '&show_completed=1');
$toggleAssumeUrl = 'report_medications.php?patient_id=' . $patient_id
    . ($showCompleted ? '&show_completed=1' : '')
    . ($assumePastIntake ? '' : '&assume_past_intake=1');

// ── Sticky header ──
echo '<div class="page-sticky-header noprint">';
echo '  <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap">';
echo '    <h5 class="page-title mb-0">Supply: ' . htmlentities($patientName) . '</h5>';
echo '    <div class="page-actions">';
echo '      <a href="' . htmlspecialchars($toggleAssumeUrl) . '" class="btn btn-sm btn-outline-secondary">'
    . ($assumePastIntake ? '✓ Assume past taken' : 'Assume past taken') . '</a>';
echo '      <a href="' . htmlspecialchars($toggleShowUrl) . '" class="btn btn-sm btn-outline-secondary">'
    . ($showCompleted ? 'Hide completed' : 'Show completed') . '</a>';
echo '      <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>';
echo '    </div>';
echo '  </div>';
echo '</div>';

if ($rows === []) {
    echo "<p class='text-muted mt-3'>No medications to report.</p>";
    echo print_trailer();
    exit;
}

// ── Desktop table ──
echo '<div class="d-none d-md-block mt-3">';
echo '  <div class="table-responsive">';
echo '    <table class="table table-hover page-table">';
echo '      <thead class="thead-light"><tr>';
echo '        <th>Medication</th><th>Frequency</th><th>Last taken</th><th>Remaining</th>';
echo '      </tr></thead>';
echo '      <tbody>';
foreach ($rows as $r) {
    echo '<tr class="' . $r['status'] . '">';
    echo '<td>' . htmlspecialchars($r['name']) . '</td>';
    echo '<td>' . htmlspecialchars($r['frequency']) . '</td>';
    echo '<td>' . htmlspecialchars($r['last_taken']) . '</td>';
    echo '<td>' . $r['remain'] . '</td>';
    echo '</tr>';
}
echo '      </tbody>';
echo '    </table>';
echo '  </div>';
echo '</div>';

// ── Mobile cards ──
echo '<div class="d-md-none mt-3">';
foreach ($rows as $r) {
    echo '<div class="page-card ' . $r['status'] . '">';
    echo '  <div class="card-title-row">';
    echo '    <span class="card-primary">' . htmlspecialchars($r['name']) . '</span>';
    echo '  </div>';
    echo '  <div class="card-meta">'
        . 'Every ' . htmlspecialchars($r['frequency'])
        . ' · last taken ' . htmlspecialchars($r['last_taken']) . '</div>';
    echo '  <div class="card-detail">' . $r['remain'] . '</div>';
    echo '</div>';
}
echo '</div>';

echo print_trailer();
