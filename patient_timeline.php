<?php
/**
 * Patient timeline — unified chronological feed of all events.
 *
 * Shows intakes, weight changes, caregiver notes, and inventory
 * events on one scrollable page with type badges and icons.
 */

declare(strict_types=1);

require_once 'includes/init.php';

$patient_id = (int) (getIntValue('patient_id') ?? 0);
if ($patient_id <= 0) {
    header('Location: index.php');
    exit;
}

$patient = getPatient($patient_id);
$patientName = $patient['name'];

// ── Pagination ──────────────────────────────────────────────────────
$perPage = 50;
$page = max(1, (int) (getGetValue('page') ?? 1));

// ── Date filter ─────────────────────────────────────────────────────
$dateFrom = trim((string) (getGetValue('date_from') ?? ''));
$dateTo = trim((string) (getGetValue('date_to') ?? ''));

$dateWhere = '';
$dateParams = [];
if ($dateFrom !== '') {
    $dateWhere .= ' AND event_time >= ?';
    $dateParams[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $dateWhere .= ' AND event_time <= ?';
    $dateParams[] = $dateTo . ' 23:59:59';
}

// ── Gather events from all sources ──────────────────────────────────
// Run four separate queries and merge in PHP. This avoids MySQL
// collation conflicts that plague UNION across tables with different
// charsets / server defaults — a persistent issue on MySQL 8.

$allEvents = [];

// 1. Intakes
$intakeRows = dbi_get_cached_rows(
    "SELECT mi.taken_time, m.name, m.dosage, ms.frequency, mi.note
       FROM hc_medicine_intake mi
       JOIN hc_medicine_schedules ms ON mi.schedule_id = ms.id
       JOIN hc_medicines m ON ms.medicine_id = m.id
      WHERE ms.patient_id = ?
      ORDER BY mi.taken_time DESC",
    [$patient_id],
);
foreach ($intakeRows as $r) {
    $allEvents[] = [
        'time' => (string) $r[0],
        'type' => 'intake',
        'title' => $r[1] . ' ' . $r[2],
        'detail' => (string) $r[3],
        'note' => $r[4],
    ];
}

// 2. Weight history
$weightRows = dbi_get_cached_rows(
    "SELECT recorded_at, weight_kg, note FROM hc_weight_history
      WHERE patient_id = ? ORDER BY recorded_at DESC",
    [$patient_id],
);
foreach ($weightRows as $r) {
    $allEvents[] = [
        'time' => $r[0] . ' 00:00:00',
        'type' => 'weight',
        'title' => displayWeight((float) $r[1]) . ' ' . weightUnitLabel(),
        'detail' => null,
        'note' => $r[2],
    ];
}

// 3. Caregiver notes
$noteRows = dbi_get_cached_rows(
    "SELECT note_time, note FROM hc_caregiver_notes
      WHERE patient_id = ? ORDER BY note_time DESC",
    [$patient_id],
);
foreach ($noteRows as $r) {
    $fullNote = (string) $r[1];
    $allEvents[] = [
        'time' => (string) $r[0],
        'type' => 'note',
        'title' => mb_substr($fullNote, 0, 80),
        'detail' => null,
        'note' => $fullNote,
    ];
}

// 4. Inventory (for medicines used by this patient)
$invRows = dbi_get_cached_rows(
    "SELECT inv.recorded_at, m.name, m.dosage, inv.current_stock, inv.note
       FROM hc_medicine_inventory inv
       JOIN hc_medicines m ON inv.medicine_id = m.id
      WHERE inv.medicine_id IN (
          SELECT DISTINCT ms2.medicine_id FROM hc_medicine_schedules ms2
           WHERE ms2.patient_id = ?
      )
      ORDER BY inv.recorded_at DESC",
    [$patient_id],
);
foreach ($invRows as $r) {
    $allEvents[] = [
        'time' => (string) $r[0],
        'type' => 'inventory',
        'title' => $r[1] . ' ' . $r[2],
        'detail' => 'Stock: ' . $r[3],
        'note' => $r[4],
    ];
}

// Apply date filter.
if ($dateFrom !== '' || $dateTo !== '') {
    $allEvents = array_filter($allEvents, static function (array $ev) use ($dateFrom, $dateTo): bool {
        $t = $ev['time'];
        if ($dateFrom !== '' && $t < $dateFrom . ' 00:00:00') {
            return false;
        }
        if ($dateTo !== '' && $t > $dateTo . ' 23:59:59') {
            return false;
        }
        return true;
    });
}

// Sort newest first.
usort($allEvents, static fn(array $a, array $b): int => strcmp($b['time'], $a['time']));

$total = count($allEvents);
$totalPages = (int) ceil($total / $perPage);

// Paginate.
$events = array_slice($allEvents, ($page - 1) * $perPage, $perPage);

// ── Helper: badge style per event type ──────────────────────────────
function timeline_badge(string $type): string
{
    return match ($type) {
        'intake' => '<span class="badge badge-success">Dose</span>',
        'weight' => '<span class="badge badge-info">Weight</span>',
        'note' => '<span class="badge badge-secondary">Note</span>',
        'inventory' => '<span class="badge badge-warning">Inventory</span>',
        default => '<span class="badge badge-light">Event</span>',
    };
}

function timeline_filter_qs(array $overrides = []): string
{
    $params = array_merge([
        'patient_id' => getGetValue('patient_id') ?? '',
        'date_from' => getGetValue('date_from') ?? '',
        'date_to' => getGetValue('date_to') ?? '',
    ], $overrides);
    $parts = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $parts[] = urlencode($k) . '=' . urlencode((string) $v);
        }
    }

    return implode('&', $parts);
}

print_header();
?>

<div class="container mt-4" style="max-width:900px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?php echo htmlspecialchars($patientName); ?> — Timeline</h1>
    <a href="list_schedule.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-sm btn-outline-secondary">Back to Schedule</a>
  </div>

  <!-- Date filter -->
  <form method="get" class="form-inline mb-3 noprint">
    <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
    <label class="small mr-2" for="tf_from">From</label>
    <input type="date" name="date_from" id="tf_from" class="form-control form-control-sm mr-2"
           value="<?php echo htmlspecialchars($dateFrom); ?>">
    <label class="small mr-2" for="tf_to">To</label>
    <input type="date" name="date_to" id="tf_to" class="form-control form-control-sm mr-2"
           value="<?php echo htmlspecialchars($dateTo); ?>">
    <button type="submit" class="btn btn-sm btn-primary mr-1">Filter</button>
    <a href="patient_timeline.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
  </form>

  <p class="text-muted small">Showing <?php echo min(($page - 1) * $perPage + 1, $total); ?>–<?php echo min($page * $perPage, $total); ?> of <?php echo $total; ?> events</p>

  <?php if (empty($events)): ?>
    <p class="text-muted">No events found for this patient<?php echo ($dateFrom || $dateTo) ? ' in the selected date range' : ''; ?>.</p>
  <?php else: ?>

    <?php
    $lastDate = '';
    foreach ($events as $ev):
        $eventTime = (string) $ev['time'];
        $eventType = (string) $ev['type'];
        $title = (string) $ev['title'];
        $detail = $ev['detail'] !== null ? (string) $ev['detail'] : null;
        $note = $ev['note'] !== null ? (string) $ev['note'] : null;
        $eventDate = substr($eventTime, 0, 10);
        $eventTimeShort = substr($eventTime, 11, 5); // HH:MM

        // Date separator.
        if ($eventDate !== $lastDate):
            $lastDate = $eventDate;
    ?>
      <div class="mt-3 mb-2">
        <strong class="text-muted small text-uppercase"><?php echo htmlspecialchars(date('l, F j, Y', strtotime($eventDate))); ?></strong>
        <hr class="mt-1 mb-0">
      </div>
    <?php endif; ?>

      <div class="d-flex py-2 border-bottom">
        <div class="mr-3 text-muted small" style="min-width:50px;">
          <?php echo $eventTimeShort !== '' && $eventTimeShort !== '00:00' ? htmlspecialchars($eventTimeShort) : '—'; ?>
        </div>
        <div class="mr-2"><?php echo timeline_badge($eventType); ?></div>
        <div class="flex-grow-1">
          <span><?php echo htmlspecialchars($title); ?></span>
          <?php if ($detail !== null): ?>
            <span class="text-muted small ml-1">(<?php echo htmlspecialchars($detail); ?>)</span>
          <?php endif; ?>
          <?php if ($note !== null && $eventType === 'note' && strlen($note) > 80): ?>
            <div class="small text-muted mt-1"><?php echo nl2br(htmlspecialchars($note)); ?></div>
          <?php elseif ($note !== null && $eventType === 'intake'): ?>
            <span class="small text-muted ml-1">— <?php echo htmlspecialchars($note); ?></span>
          <?php endif; ?>
        </div>
      </div>

    <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
  <nav aria-label="Timeline pagination" class="mt-3">
    <ul class="pagination pagination-sm justify-content-center flex-wrap">
      <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
        <a class="page-link" href="?<?php echo timeline_filter_qs(['page' => (string) ($page - 1)]); ?>">Previous</a>
      </li>
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
          <a class="page-link" href="?<?php echo timeline_filter_qs(['page' => (string) $p]); ?>"><?php echo $p; ?></a>
        </li>
      <?php endfor; ?>
      <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
        <a class="page-link" href="?<?php echo timeline_filter_qs(['page' => (string) ($page + 1)]); ?>">Next</a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
</div>

<?php echo print_trailer(); ?>
