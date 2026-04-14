<?php
/**
 * HC-061: report_intake.php using the shared page shell.
 *
 * Sticky header (patient name + Print + CSV/FHIR exports + month nav),
 * desktop table, mobile cards. Records taken at the same time are still
 * grouped together. Sort + month-paging controls live in the sticky
 * header so they're always reachable.
 */

require_once 'includes/init.php';

$patient_id = (int) (getIntValue('patient_id') ?? 0);
$patient = getPatient($patient_id);

$order = getGetValue('sort');
if (empty($order) || $order == 'f') {
    $orderParam = 'f';
    $order = 'DESC';
} else {
    $orderParam = 'r';
    $order = 'ASC';
}

$current_date = getValue('date');
$today = date('Y-m-d');
if (empty($current_date)) {
    $current_date = $today;
}
$start_of_month = date('Y-m-01', strtotime($current_date));
// The page itself queries by DATE_FORMAT(mi.taken_time, '%Y-%m') so it
// shows the entire month. The export links must match — previously
// end_date was $current_date, which silently truncated the export to
// just day-1 of the month when the user navigated via the prev/next
// buttons (those set date=YYYY-MM-01). Use the actual last day of the
// month instead.
$end_of_month = date('Y-m-t', strtotime($current_date));

$sql = "SELECT mi.taken_time, m.name AS medicine_name, mi.note,
               mi.created_at, ms.id AS schedule_id, mi.id AS intake_id
        FROM hc_medicine_intake mi
        JOIN hc_medicine_schedules ms ON mi.schedule_id = ms.id
        JOIN hc_medicines m ON ms.medicine_id = m.id
        WHERE ms.patient_id = ? AND DATE_FORMAT(mi.taken_time, '%Y-%m') = DATE_FORMAT(?, '%Y-%m')
        ORDER BY mi.taken_time $order";
$res = dbi_execute($sql, [$patient_id, $start_of_month]);

// Materialize + group by identical taken_time so the same dose-event
// (e.g. three pills swallowed at 8:00) renders as one row.
$groups = []; // list<['time' => string, 'meds' => list<['name','link','note']>]>
$lastKey = null;
if ($res) {
    while ($row = dbi_fetch_row($res)) {
        $time = formatDateNicely(date('Y-m-d g:i A', strtotime($row[0])));
        $editLink = 'record_intake.php?patient_id=' . $patient_id
            . '&schedule_id=' . (int) $row[4]
            . '&id=' . (int) $row[5];
        $med = [
            'name' => htmlspecialchars((string) $row[1]),
            'link' => $editLink,
            'note' => htmlspecialchars((string) $row[2]),
        ];
        if ($time === $lastKey) {
            $groups[count($groups) - 1]['meds'][] = $med;
        } else {
            $groups[] = ['time' => $time, 'meds' => [$med]];
            $lastKey = $time;
        }
    }
}

// Pagination month bounds
$previous_month = date('Y-m-d', strtotime('-1 month', strtotime($start_of_month)));
$next_month = date('Y-m-d', strtotime('+1 month', strtotime($start_of_month)));
$monthLabel = date('F Y', strtotime($start_of_month));

$exportQs = sprintf(
    '?patient_id=%d&start_date=%s&end_date=%s',
    $patient_id,
    urlencode($start_of_month),
    urlencode($end_of_month)
);

print_header();

// ── Sticky header ──
echo '<div class="page-sticky-header noprint">';
echo '  <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap">';
echo '    <h5 class="page-title mb-0">Intake: ' . htmlentities($patient['name']) . '</h5>';
echo '    <div class="page-actions">';
echo '      <a class="btn btn-sm btn-outline-secondary" href="report_intake.php?patient_id='
    . $patient_id . '&date=' . urlencode($previous_month) . '" title="Previous month">&laquo;</a>';
echo '      <span class="px-2 align-self-center small text-muted">' . htmlspecialchars($monthLabel) . '</span>';
echo '      <a class="btn btn-sm btn-outline-secondary" href="report_intake.php?patient_id='
    . $patient_id . '&date=' . urlencode($next_month) . '" title="Next month">&raquo;</a>';
$sortSwap = $orderParam === 'f' ? 'r' : 'f';
$sortLabel = $orderParam === 'f' ? 'Newest first' : 'Oldest first';
echo '      <a class="btn btn-sm btn-outline-secondary" href="report_intake.php?patient_id='
    . $patient_id . '&date=' . urlencode($current_date) . '&sort=' . $sortSwap
    . '" title="Toggle sort order">' . $sortLabel . '</a>';
echo '      <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print</button>';
echo '      <a class="btn btn-sm btn-outline-secondary" href="export_intake_pdf.php' . $exportQs
    . '" title="Print-ready PDF of the current month">PDF</a>';
echo '      <a class="btn btn-sm btn-outline-secondary" href="export_intake_csv.php' . $exportQs . '">CSV</a>';
echo '      <a class="btn btn-sm btn-outline-secondary" href="export_intake_fhir.php' . $exportQs
    . '" title="HL7 FHIR R4 MedicationAdministration bundle">FHIR</a>';
echo '    </div>';
echo '  </div>';
echo '</div>';

if ($groups === []) {
    echo "<p class='text-muted mt-3'>No intakes recorded for " . htmlspecialchars($monthLabel) . ".</p>";
    echo print_trailer();
    exit;
}

/**
 * Render the medication list. Each entry stacks the med name + edit
 * pencil on one line, and (when present) the caregiver note on a
 * second, muted italic line underneath. Notes were previously hidden
 * behind a tooltip icon — that made them invisible on touch devices
 * and vanished entirely when the page was printed, which is exactly
 * when caregivers care about them most (vet visits, handoffs).
 *
 * @param list<array{name:string,link:string,note:string}> $meds
 */
function render_intake_meds(array $meds): string
{
    $html = '';
    foreach ($meds as $m) {
        $html .= '<div class="intake-med">';
        $html .= '<a href="' . $m['link']
            . '" class="text-decoration-none"><img src="images/bootstrap-icons/pencil.svg"'
            . ' alt="Edit" class="edit-icon"></a> ' . $m['name'];
        if ($m['note'] !== '') {
            // $m['note'] is already htmlspecialchars-encoded upstream.
            $html .= '<div class="intake-note">'
                . '<img src="images/bootstrap-icons/sticky.svg" alt="" aria-hidden="true"> '
                . $m['note']
                . '</div>';
        }
        $html .= '</div>';
    }

    return $html;
}

// ── Desktop table ──
echo '<div class="d-none d-md-block mt-3">';
echo '  <div class="table-responsive">';
echo '    <table class="table table-hover page-table">';
echo '      <thead class="thead-light"><tr><th>Date &amp; Time</th><th>Medicines</th></tr></thead>';
echo '      <tbody>';
foreach ($groups as $g) {
    echo '<tr><td>' . $g['time'] . '</td><td>' . render_intake_meds($g['meds']) . '</td></tr>';
}
echo '      </tbody>';
echo '    </table>';
echo '  </div>';
echo '</div>';

// ── Mobile cards ──
echo '<div class="d-md-none mt-3">';
foreach ($groups as $g) {
    echo '<div class="page-card">';
    echo '  <div class="card-meta">' . $g['time'] . '</div>';
    echo '  <div class="card-detail">' . render_intake_meds($g['meds']) . '</div>';
    echo '</div>';
}
echo '</div>';

echo print_trailer();
