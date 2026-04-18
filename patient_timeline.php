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
// Each query returns: event_time, event_type, title, detail, note

// Each subquery selects into the same column shapes. To avoid
// "Illegal mix of collations" in the UNION (MySQL 8's server-default
// collation for string literals can differ from table collations),
// CAST the type-tag literals so they match the table charset.
$intakeSql = "SELECT mi.taken_time AS event_time,
                     CAST('intake' AS CHAR) AS event_type,
                     CONCAT(m.name, ' ', m.dosage) AS title,
                     CAST(ms.frequency AS CHAR) AS detail,
                     CAST(mi.note AS CHAR) AS note
                FROM hc_medicine_intake mi
                JOIN hc_medicine_schedules ms ON mi.schedule_id = ms.id
                JOIN hc_medicines m ON ms.medicine_id = m.id
               WHERE ms.patient_id = ?";

$weightSql = "SELECT CAST(CONCAT(wh.recorded_at, ' 00:00:00') AS CHAR) AS event_time,
                     CAST('weight' AS CHAR) AS event_type,
                     CAST(CONCAT(wh.weight_kg, ' kg') AS CHAR) AS title,
                     CAST(NULL AS CHAR) AS detail,
                     CAST(wh.note AS CHAR) AS note
                FROM hc_weight_history wh
               WHERE wh.patient_id = ?";

$noteSql = "SELECT cn.note_time AS event_time,
                   CAST('note' AS CHAR) AS event_type,
                   CAST(SUBSTRING(cn.note, 1, 80) AS CHAR) AS title,
                   CAST(NULL AS CHAR) AS detail,
                   CAST(cn.note AS CHAR) AS note
              FROM hc_caregiver_notes cn
             WHERE cn.patient_id = ?";

// Inventory: join through schedules to find medicines used by this patient.
$inventorySql = "SELECT inv.recorded_at AS event_time,
                        CAST('inventory' AS CHAR) AS event_type,
                        CONCAT(m.name, ' ', m.dosage) AS title,
                        CAST(CONCAT('Stock: ', inv.current_stock) AS CHAR) AS detail,
                        CAST(inv.note AS CHAR) AS note
                   FROM hc_medicine_inventory inv
                   JOIN hc_medicines m ON inv.medicine_id = m.id
                  WHERE inv.medicine_id IN (
                      SELECT DISTINCT ms2.medicine_id
                        FROM hc_medicine_schedules ms2
                       WHERE ms2.patient_id = ?
                  )";

// Union all four, apply date filter, sort, paginate.
$unionSql = "SELECT * FROM (
    ({$intakeSql}) UNION ALL
    ({$weightSql}) UNION ALL
    ({$noteSql}) UNION ALL
    ({$inventorySql})
) AS timeline
WHERE 1=1 {$dateWhere}
ORDER BY event_time DESC
LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage);

$params = array_merge(
    [$patient_id], // intakes
    [$patient_id], // weight
    [$patient_id], // notes
    [$patient_id], // inventory
    $dateParams,
);

$events = dbi_get_cached_rows($unionSql, $params);

// Count for pagination.
$countSql = "SELECT COUNT(*) FROM (
    ({$intakeSql}) UNION ALL
    ({$weightSql}) UNION ALL
    ({$noteSql}) UNION ALL
    ({$inventorySql})
) AS timeline
WHERE 1=1 {$dateWhere}";

$countRows = dbi_get_cached_rows($countSql, $params);
$total = (int) ($countRows[0][0] ?? 0);
$totalPages = (int) ceil($total / $perPage);

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
        $eventTime = (string) $ev[0];
        $eventType = (string) $ev[1];
        $title = (string) $ev[2];
        $detail = $ev[3] !== null ? (string) $ev[3] : null;
        $note = $ev[4] !== null ? (string) $ev[4] : null;
        $eventDate = substr($eventTime, 0, 10);
        $eventTimeShort = substr($eventTime, 11, 5); // HH:MM

        // Display weight in configured unit.
        if ($eventType === 'weight') {
            $kgMatch = [];
            if (preg_match('/^([\d.]+)\s*kg$/', $title, $kgMatch)) {
                $title = displayWeight((float) $kgMatch[1]) . ' ' . weightUnitLabel();
            }
        }

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
