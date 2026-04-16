<?php
/**
 * HC-061: report_missed.php using the shared page shell.
 *
 * Sticky header + dual layout. Each row gets a status-stripe colour
 * by lateness severity (Late = yellow, Missed = red).
 *
 * The "deviation thresholds" math is unchanged from the legacy version:
 *   Late   = actual interval > 120% of frequency
 *   Missed = actual interval > 150% of frequency
 */

require_once 'includes/init.php';

$lowerDeviationThreshold = 0.20;  // +20% → Late
$upperDeviationThreshold = 0.50;  // +50% → Missed

$patient_id = (int) (getIntValue('patient_id') ?? 0);
$patient = getPatient($patient_id);

print_header();

// Walk each schedule's intake history, collect deviations.
// HC-120: skip PRN (as-needed) schedules — they have no cadence, so
// "late" and "missed" are not meaningful.
$schedulesSql = "SELECT ms.id, m.name, ms.frequency
                 FROM hc_medicine_schedules ms
                 JOIN hc_medicines m ON ms.medicine_id = m.id
                 WHERE ms.patient_id = ?
                   AND ms.is_prn = 'N'
                   AND ms.frequency IS NOT NULL
                 ORDER BY m.name ASC";
$schedules = dbi_get_cached_rows($schedulesSql, [$patient_id]);

$rows = []; // list<['name','frequency','prev','expected','actual','status']>
foreach ($schedules as $s) {
    $scheduleId = (int) $s[0];
    $name = (string) $s[1];
    $frequency = (string) $s[2];

    $intakes = dbi_get_cached_rows(
        'SELECT taken_time FROM hc_medicine_intake WHERE schedule_id = ? ORDER BY taken_time ASC',
        [$scheduleId]
    );
    $prev = null;
    foreach ($intakes as $intake) {
        $taken = (string) $intake[0];
        if ($prev !== null) {
            try {
                $secsExpected = frequencyToSeconds($frequency);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $actualSecs = strtotime($taken) - strtotime($prev);
            $lowerAllowed = $secsExpected * (1 + $lowerDeviationThreshold);
            $upperAllowed = $secsExpected * (1 + $upperDeviationThreshold);

            $status = 'On Time';
            if ($actualSecs > $upperAllowed) {
                $status = 'Missed';
            } elseif ($actualSecs > $lowerAllowed) {
                $status = 'Late';
            }

            if ($status !== 'On Time') {
                $rows[] = [
                    'name' => $name,
                    'frequency' => $frequency,
                    'prev' => $prev,
                    'expected' => calculateNextDueDate($prev, $frequency),
                    'actual' => $taken,
                    'status' => $status,
                ];
            }
        }
        $prev = $taken;
    }
}

// Newest first, so today's misses appear at the top.
usort($rows, static fn (array $a, array $b): int => strcmp($b['actual'], $a['actual']));

// ── Sticky header ──
echo '<div class="page-sticky-header noprint">';
echo '  <div class="container-fluid d-flex justify-content-between align-items-center">';
echo '    <h5 class="page-title mb-0">Timing report: ' . htmlentities($patient['name']) . '</h5>';
echo '    <div class="page-actions">';
echo '      <button class="btn btn-sm btn-outline-secondary" data-print>Print</button>';
echo '      <a href="list_schedule.php?patient_id=' . $patient_id
    . '" class="btn btn-sm btn-outline-secondary">Back to Schedule</a>';
echo '    </div>';
echo '  </div>';
echo '</div>';

if ($rows === []) {
    echo "<p class='text-muted mt-3'>No late or missed doses found. 🎉</p>";
    echo print_trailer();
    exit;
}

/** Status → status-stripe / section-color class. */
function missed_status_class(string $status): string
{
    return match ($status) {
        'Missed' => 'status-overdue',
        'Late' => 'status-due-soon',
        default => 'status-ok',
    };
}

/** Status → Bootstrap badge class. */
function missed_badge_class(string $status): string
{
    return match ($status) {
        'Missed' => 'badge badge-danger',
        'Late' => 'badge badge-warning',
        default => 'badge badge-secondary',
    };
}

// ── Desktop table ──
echo '<div class="d-none d-md-block mt-3">';
echo '  <div class="table-responsive">';
echo '    <table class="table table-hover page-table">';
echo '      <thead class="thead-light"><tr>';
echo '        <th>Medication</th><th>Frequency</th><th>Previous</th>';
echo '        <th>Expected</th><th>Actual</th><th>Status</th>';
echo '      </tr></thead>';
echo '      <tbody>';
foreach ($rows as $r) {
    echo '<tr class="' . missed_status_class($r['status']) . '">';
    echo '<td>' . htmlspecialchars($r['name']) . '</td>';
    echo '<td>' . htmlspecialchars($r['frequency']) . '</td>';
    echo '<td>' . htmlspecialchars(formatDateNicely($r['prev'])) . '</td>';
    echo '<td>' . htmlspecialchars(formatDateNicely($r['expected'])) . '</td>';
    echo '<td>' . htmlspecialchars(formatDateNicely($r['actual'])) . '</td>';
    echo '<td><span class="' . missed_badge_class($r['status']) . '">'
        . htmlspecialchars($r['status']) . '</span></td>';
    echo '</tr>';
}
echo '      </tbody>';
echo '    </table>';
echo '  </div>';
echo '</div>';

// ── Mobile cards ──
echo '<div class="d-md-none mt-3">';
foreach ($rows as $r) {
    echo '<div class="page-card ' . missed_status_class($r['status']) . '">';
    echo '  <div class="card-title-row">';
    echo '    <span class="card-primary">' . htmlspecialchars($r['name']) . '</span>';
    echo '    <span class="' . missed_badge_class($r['status']) . '">'
        . htmlspecialchars($r['status']) . '</span>';
    echo '  </div>';
    echo '  <div class="card-meta">Every ' . htmlspecialchars($r['frequency']) . '</div>';
    echo '  <div class="card-detail">';
    echo '    <strong>Expected:</strong> ' . htmlspecialchars(formatDateNicely($r['expected'])) . '<br>';
    echo '    <strong>Actual:</strong> ' . htmlspecialchars(formatDateNicely($r['actual']));
    echo '  </div>';
    echo '</div>';
}
echo '</div>';
?>
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
document.addEventListener('click', function(e) {
  if (e.target.closest('[data-print]')) window.print();
});
</script>
<?php
echo print_trailer();
