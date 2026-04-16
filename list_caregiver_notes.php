<?php
/**
 * HC-083: Per-patient caregiver-notes list with filters + pagination.
 *
 * Uses the shared page shell (sticky header, card/table dual layout,
 * status stripes, print stylesheet) introduced in HC-061.
 */

require_once 'includes/init.php';

use HomeCare\Auth\Authorization;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\CaregiverNoteRepository;

const NOTES_PAGE_SIZE = 50;

$patient_id = (int) (getIntValue('patient_id') ?? 0);
if ($patient_id <= 0) {
    die_miserable_death('Missing required patient_id.');
}
$patient = getPatient($patient_id);

// Filters
$startDate = trim((string) (getGetValue('start_date') ?? ''));
$endDate   = trim((string) (getGetValue('end_date') ?? ''));
$query     = trim((string) (getGetValue('q') ?? ''));
$pageNum   = max(1, (int) (getIntValue('page') ?? 1));

// Normalise user-supplied date bounds to DATETIME. The HTML <input type=date>
// widget emits YYYY-MM-DD; widen end-of-day on the upper bound so an inclusive
// filter actually includes notes stamped later that day.
$startFilter = $startDate !== '' ? $startDate . ' 00:00:00' : null;
$endFilter   = $endDate   !== '' ? $endDate   . ' 23:59:59' : null;
$queryFilter = $query !== '' ? $query : null;

$repo  = new CaregiverNoteRepository(new DbiAdapter());
$total = $repo->countSearch($patient_id, $startFilter, $endFilter, $queryFilter);
$offset = ($pageNum - 1) * NOTES_PAGE_SIZE;
$notes = $repo->search(
    $patient_id,
    startDate: $startFilter,
    endDate:   $endFilter,
    query:     $queryFilter,
    limit:     NOTES_PAGE_SIZE,
    offset:    $offset,
);

$auth = new Authorization(getCurrentUserRole());
$canWrite = $auth->canWrite();

print_header();

$patientIdEsc = htmlspecialchars((string) $patient_id, ENT_QUOTES, 'UTF-8');
$patientNameEsc = htmlspecialchars($patient['name'], ENT_QUOTES, 'UTF-8');

// ── Sticky header ──
echo '<div class="page-sticky-header noprint">';
echo '  <div class="container-fluid d-flex justify-content-between align-items-center flex-wrap" style="gap:.5rem;">';
echo '    <h5 class="page-title mb-0">Notes: ' . $patientNameEsc . '</h5>';
echo '    <div class="page-actions">';
echo '      <button class="btn btn-sm btn-outline-secondary" data-print>Print</button>';
echo '      <a href="list_schedule.php?patient_id=' . $patientIdEsc
   . '" class="btn btn-sm btn-outline-secondary">Back to Schedule</a>';
if ($canWrite) {
    echo '      <a href="note_caregiver.php?patient_id=' . $patientIdEsc
       . '" class="btn btn-sm btn-primary">+ Add Note</a>';
}
echo '    </div>';
echo '  </div>';
echo '</div>';

// ── Filters ──
echo '<form method="GET" action="list_caregiver_notes.php" class="page-controls noprint mt-3">';
echo '  <input type="hidden" name="patient_id" value="' . $patientIdEsc . '">';
echo '  <div class="d-flex flex-wrap align-items-end" style="gap:.75rem;">';
echo '    <div>';
echo '      <label for="start_date" class="small text-muted mb-0">From</label><br>';
echo '      <input type="date" id="start_date" name="start_date" class="form-control form-control-sm"'
   . ' value="' . htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') . '">';
echo '    </div>';
echo '    <div>';
echo '      <label for="end_date" class="small text-muted mb-0">To</label><br>';
echo '      <input type="date" id="end_date" name="end_date" class="form-control form-control-sm"'
   . ' value="' . htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') . '">';
echo '    </div>';
echo '    <div class="flex-grow-1" style="min-width:12rem;">';
echo '      <label for="q" class="small text-muted mb-0">Search</label><br>';
echo '      <input type="search" id="q" name="q" class="form-control form-control-sm"'
   . ' placeholder="Find in notes…" value="' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '">';
echo '    </div>';
echo '    <div>';
echo '      <button type="submit" class="btn btn-sm btn-primary">Filter</button>';
if ($startDate !== '' || $endDate !== '' || $query !== '') {
    echo '      <a href="list_caregiver_notes.php?patient_id=' . $patientIdEsc
       . '" class="btn btn-sm btn-outline-secondary">Clear</a>';
}
echo '    </div>';
echo '  </div>';
echo '</form>';

echo '<p class="text-muted small mt-2 noprint">' . (int) $total . ' note'
   . ($total === 1 ? '' : 's') . '</p>';

if ($notes === []) {
    echo '<p class="text-muted mt-3">No notes match the current filters.</p>';
    echo print_trailer();
    exit;
}

/** Format a DATETIME string as "Apr 15, 2026 7:45 AM". */
function notes_format_when(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return $dt;
    }
    return date('M j, Y g:i A', $ts);
}

// ── Desktop table ──
echo '<div class="d-none d-md-block mt-3">';
echo '  <div class="table-responsive">';
echo '    <table class="table table-hover page-table">';
echo '      <thead class="thead-light"><tr>';
echo '        <th style="width:14rem;">When</th>';
echo '        <th>Note</th>';
echo '        <th style="width:14rem;">Recorded</th>';
if ($canWrite) {
    echo '        <th class="actions-cell" style="width:8rem;">Actions</th>';
}
echo '      </tr></thead>';
echo '      <tbody>';
foreach ($notes as $n) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars(notes_format_when($n['note_time'])) . '</td>';
    echo '<td style="white-space:pre-wrap;">' . nl2br(htmlspecialchars($n['note'])) . '</td>';
    echo '<td class="text-muted small">' . htmlspecialchars(notes_format_when($n['created_at'])) . '</td>';
    if ($canWrite) {
        echo '<td class="actions-cell">';
        echo '  <a href="note_caregiver.php?id=' . (int) $n['id']
            . '" class="btn btn-sm btn-outline-secondary">Edit</a>';
        echo '</td>';
    }
    echo '</tr>';
}
echo '      </tbody>';
echo '    </table>';
echo '  </div>';
echo '</div>';

// ── Mobile cards ──
echo '<div class="d-md-none mt-3">';
foreach ($notes as $n) {
    echo '<div class="page-card">';
    echo '  <div class="card-title-row">';
    echo '    <span class="card-primary">' . htmlspecialchars(notes_format_when($n['note_time'])) . '</span>';
    echo '  </div>';
    echo '  <div class="card-detail" style="white-space:pre-wrap;">' . nl2br(htmlspecialchars($n['note'])) . '</div>';
    echo '  <div class="card-meta">Recorded '
       . htmlspecialchars(notes_format_when($n['created_at'])) . '</div>';
    if ($canWrite) {
        echo '  <div class="card-actions">';
        echo '    <a href="note_caregiver.php?id=' . (int) $n['id']
            . '" class="btn btn-sm btn-outline-secondary">Edit</a>';
        echo '  </div>';
    }
    echo '</div>';
}
echo '</div>';

// ── Pagination ──
$totalPages = (int) ceil($total / NOTES_PAGE_SIZE);
if ($totalPages > 1) {
    $qsBase = http_build_query([
        'patient_id' => $patient_id,
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'q'          => $query,
    ]);
    echo '<nav class="noprint mt-3"><ul class="pagination pagination-sm">';
    $prev = max(1, $pageNum - 1);
    $next = min($totalPages, $pageNum + 1);
    $prevClass = $pageNum === 1 ? ' disabled' : '';
    $nextClass = $pageNum === $totalPages ? ' disabled' : '';
    echo '<li class="page-item' . $prevClass . '">'
       . '<a class="page-link" href="?' . $qsBase . '&amp;page=' . $prev . '">Previous</a></li>';
    echo '<li class="page-item disabled"><span class="page-link">Page '
       . $pageNum . ' of ' . $totalPages . '</span></li>';
    echo '<li class="page-item' . $nextClass . '">'
       . '<a class="page-link" href="?' . $qsBase . '&amp;page=' . $next . '">Next</a></li>';
    echo '</ul></nav>';
}
?>
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
document.addEventListener('click', function(e) {
  if (e.target.closest('[data-print]')) window.print();
});
</script>
<?php
echo print_trailer();
