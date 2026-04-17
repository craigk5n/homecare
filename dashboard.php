<?php
/**
 * Multi-patient dashboard — at-a-glance overview of all patients.
 *
 * Shows:
 *   - Summary cards: total overdue, due soon, low supply
 *   - Per-patient dose status (overdue + due-soon schedules)
 *   - Low-supply medicines (< 7 days)
 *   - Recent intakes (last 24 hours)
 */

declare(strict_types=1);

require_once 'includes/init.php';

/**
 * Format seconds into a human-readable duration (e.g., "2h 15m", "45m", "3d 6h").
 */
function formatOverdueDuration(int $seconds): string
{
    if ($seconds < 60) {
        return '< 1m';
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($days > 0) {
        return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
    }
    if ($hours > 0) {
        return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
    }
    return "{$minutes}m";
}

$patients = getPatients();
$patientCount = count($patients);

if ($patientCount === 0) {
    header('Location: index.php');
    exit;
}

// ── 1. Dose status across all patients ──────────────────────────────
$dueSoonSeconds = 1800; // 30 minutes

$sql = "SELECT p.id AS patient_id, p.name AS patient_name,
               ms.id AS schedule_id, m.name AS medicine_name, m.dosage,
               ms.frequency,
               (SELECT MAX(mi.taken_time)
                  FROM hc_medicine_intake mi
                 WHERE mi.schedule_id = ms.id) AS last_taken
          FROM hc_patients p
     LEFT JOIN hc_medicine_schedules ms
            ON ms.patient_id = p.id
           AND ms.is_prn = 'N'
           AND ms.frequency IS NOT NULL
           AND (ms.end_date IS NULL OR ms.end_date >= CURDATE())
     LEFT JOIN hc_medicines m ON ms.medicine_id = m.id
         WHERE p.is_active = 1
         ORDER BY p.name, ms.id";

$rows = dbi_get_cached_rows($sql, []);

$patientStats = [];
foreach ($patients as $p) {
    $patientStats[(int) $p['id']] = [
        'name'      => $p['name'],
        'overdue'   => [],
        'due_soon'  => [],
        'ok'        => 0,
    ];
}

$totalOverdue = 0;
$totalDueSoon = 0;

foreach ($rows as $row) {
    $patientId  = (int) $row[0];
    $frequency  = $row[5];
    $lastTaken  = $row[6] ?? null;

    if (empty($frequency)) {
        continue;
    }

    $entry = [
        'schedule_id'   => (int) $row[2],
        'medicine_name' => (string) $row[3],
        'dosage'        => (string) $row[4],
        'last_taken'    => $lastTaken,
        'patient_id'    => $patientId,
    ];

    if ($lastTaken) {
        $seconds = calculateSecondsUntilDue($lastTaken, $frequency, true);
        if ($seconds <= 0) {
            $entry['overdue_seconds'] = abs($seconds);
            $entry['last_taken_display'] = formatDateNicely($lastTaken);
            $patientStats[$patientId]['overdue'][] = $entry;
            $totalOverdue++;
        } elseif ($seconds <= $dueSoonSeconds) {
            $patientStats[$patientId]['due_soon'][] = $entry;
            $totalDueSoon++;
        } else {
            $patientStats[$patientId]['ok']++;
        }
    } else {
        $entry['overdue_seconds'] = null; // never taken
        $entry['last_taken_display'] = null;
        $patientStats[$patientId]['overdue'][] = $entry;
        $totalOverdue++;
    }
}

// ── 1b. 7-day adherence per patient (sparkline data) ────────────────
// For each patient, count expected vs actual intakes over the last 7 days.
// This is a lightweight aggregate — not the full per-schedule breakdown.
$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$today = date('Y-m-d');

$adherenceSql = "SELECT ms.patient_id,
                        ms.frequency,
                        ms.start_date,
                        ms.end_date,
                        (SELECT COUNT(*) FROM hc_medicine_intake mi
                          WHERE mi.schedule_id = ms.id
                            AND mi.taken_time >= ? AND mi.taken_time <= ?) AS actual
                   FROM hc_medicine_schedules ms
                  WHERE ms.patient_id IN (" . implode(',', array_map('intval', array_keys($patientStats))) . ")
                    AND ms.is_prn = 'N'
                    AND ms.frequency IS NOT NULL
                    AND ms.start_date <= ?
                    AND (ms.end_date IS NULL OR ms.end_date >= ?)";
$adherenceRows = dbi_get_cached_rows($adherenceSql, [
    $sevenDaysAgo . ' 00:00:00', $today . ' 23:59:59',
    $today, $sevenDaysAgo,
]);

$patientAdherence = []; // patient_id => ['expected' => int, 'actual' => int]
foreach ($adherenceRows as $ar) {
    $pid = (int) $ar[0];
    $freq = (string) $ar[1];
    $actual = (int) $ar[4];

    if (!isset($patientAdherence[$pid])) {
        $patientAdherence[$pid] = ['expected' => 0, 'actual' => 0];
    }

    // Calculate how many days this schedule was active in the 7-day window.
    $schedStart = (string) $ar[2];
    $schedEnd = $ar[3] !== null ? (string) $ar[3] : $today;
    $effectiveStart = max($sevenDaysAgo, $schedStart);
    $effectiveEnd = min($today, $schedEnd);
    $activeDays = max(0, (int) ((strtotime($effectiveEnd) - strtotime($effectiveStart)) / 86400) + 1);

    try {
        $dosesPerDay = 86400 / frequencyToSeconds($freq);
    } catch (\InvalidArgumentException) {
        continue;
    }

    $patientAdherence[$pid]['expected'] += (int) round($activeDays * $dosesPerDay);
    $patientAdherence[$pid]['actual'] += $actual;
}

// Compute percentage per patient.
$patientAdherencePct = [];
foreach ($patientAdherence as $pid => $a) {
    if ($a['expected'] > 0) {
        $patientAdherencePct[$pid] = min(100.0, round(($a['actual'] / $a['expected']) * 100, 1));
    }
}

// ── 1c. Latest weight per patient (for weight badges) ───────────────
$weightSql = "SELECT patient_id, weight_kg, recorded_at
                FROM hc_weight_history
               WHERE patient_id IN (" . implode(',', array_map('intval', array_keys($patientStats))) . ")
               ORDER BY patient_id, recorded_at DESC, id DESC";
$weightRows = dbi_get_cached_rows($weightSql, []);

// Collect latest + previous weight per patient for trend arrow.
$patientWeights = []; // pid => ['current' => float, 'previous' => float|null, 'date' => string]
foreach ($weightRows as $wr) {
    $pid = (int) $wr[0];
    if (!isset($patientWeights[$pid])) {
        $patientWeights[$pid] = [
            'current' => (float) $wr[1],
            'date' => (string) $wr[2],
            'previous' => null,
        ];
    } elseif ($patientWeights[$pid]['previous'] === null) {
        $patientWeights[$pid]['previous'] = (float) $wr[1];
    }
}

// ── 2. Low-supply medicines ─────────────────────────────────────────
$inventoryData = getInventoryDashboardData();
$lowSupply = [];
foreach ($inventoryData as $item) {
    if ($item['days_supply'] !== null && $item['days_supply'] < 7) {
        $lowSupply[] = $item;
    }
}

// ── 3. Recent intakes (last 24 hours) ──────────────────────────────
$recentSql = "SELECT p.name AS patient_name, m.name AS medicine_name,
                     mi.taken_time, mi.note
                FROM hc_medicine_intake mi
                JOIN hc_medicine_schedules ms ON mi.schedule_id = ms.id
                JOIN hc_medicines m ON ms.medicine_id = m.id
                JOIN hc_patients p ON ms.patient_id = p.id
               WHERE mi.taken_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               ORDER BY mi.taken_time DESC
               LIMIT 20";
$recentRows = dbi_get_cached_rows($recentSql, []);

// Auto-refresh every 60 seconds. The ?t= cache-buster ensures the
// browser doesn't serve a stale cached page.
$refreshUrl = 'dashboard.php?t=' . time();
print_header('', '<meta http-equiv="refresh" content="60;url=' . htmlspecialchars($refreshUrl) . '">');
?>

<?php
// Collect all overdue schedule IDs for the catch-up button.
$allOverdueIds = [];
foreach ($patientStats as $s) {
    foreach ($s['overdue'] as $dose) {
        $allOverdueIds[] = (int) $dose['schedule_id'];
    }
}

// Flash message from bulk_catchup_handler.
$caughtUp = getGetValue('caught_up');
?>

<div class="container mt-4" style="max-width:1100px;">
  <?php if ($caughtUp !== null && $caughtUp !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      Recorded <?php echo (int) $caughtUp; ?> dose(s) as caught up.
      <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?php etranslate('Dashboard'); ?></h1>
    <div class="d-flex align-items-center">
      <?php if (!empty($allOverdueIds) && (empty($readonly) || $readonly !== 'Y')): ?>
        <form method="post" action="bulk_catchup_handler.php" class="d-inline mr-3"
              onsubmit="return confirm('Record <?php echo count($allOverdueIds); ?> overdue dose(s) as taken now?')">
          <?php print_form_key(); ?>
          <?php foreach ($allOverdueIds as $sid): ?>
            <input type="hidden" name="schedule_ids[]" value="<?php echo $sid; ?>">
          <?php endforeach; ?>
          <button type="submit" class="btn btn-sm btn-danger">
            Mark all caught up (<?php echo count($allOverdueIds); ?>)
          </button>
        </form>
      <?php endif; ?>
      <a href="schedule_print.php" class="btn btn-sm btn-outline-secondary mr-2" title="Printable daily medication sheet">Print Sheet</a>
      <small class="text-muted" title="Page refreshes automatically every 60 seconds">Auto-refresh: 60s</small>
    </div>
  </div>

  <!-- ── Summary cards ─────────────────────────────────────────── -->
  <div class="row mb-4">
    <div class="col-md-4 mb-3">
      <div class="card border-<?php echo $totalOverdue > 0 ? 'danger' : 'success'; ?> h-100">
        <div class="card-body text-center">
          <h2 class="display-4 mb-1 text-<?php echo $totalOverdue > 0 ? 'danger' : 'success'; ?>">
            <?php echo (int) $totalOverdue; ?>
          </h2>
          <p class="text-muted mb-0"><?php etranslate('Overdue Doses'); ?></p>
        </div>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="card border-<?php echo $totalDueSoon > 0 ? 'warning' : 'success'; ?> h-100">
        <div class="card-body text-center">
          <h2 class="display-4 mb-1 text-<?php echo $totalDueSoon > 0 ? 'warning' : 'success'; ?>">
            <?php echo (int) $totalDueSoon; ?>
          </h2>
          <p class="text-muted mb-0"><?php etranslate('Due Soon'); ?></p>
        </div>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="card border-<?php echo count($lowSupply) > 0 ? 'danger' : 'success'; ?> h-100">
        <div class="card-body text-center">
          <h2 class="display-4 mb-1 text-<?php echo count($lowSupply) > 0 ? 'danger' : 'success'; ?>">
            <?php echo count($lowSupply); ?>
          </h2>
          <p class="text-muted mb-0"><?php etranslate('Low Supply'); ?></p>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <!-- ── Left column: dose status per patient ────────────────── -->
    <div class="col-lg-7 mb-4">
      <div class="card shadow-sm">
        <div class="card-header bg-white">
          <strong><?php etranslate('Dose Status by Patient'); ?></strong>
        </div>
        <div class="card-body p-0">
          <?php
          $hasAnyIssues = false;
          foreach ($patientStats as $pid => $s):
            $overdueCount = count($s['overdue']);
            $dueSoonCount = count($s['due_soon']);
            if ($overdueCount === 0 && $dueSoonCount === 0 && $s['ok'] === 0) {
                continue; // no schedules at all
            }
            $hasAnyIssues = true;
          ?>
            <div class="border-bottom px-3 py-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <div>
                  <a href="list_schedule.php?patient_id=<?php echo (int) $pid; ?>"
                     class="font-weight-bold text-dark text-decoration-none">
                    <?php echo htmlspecialchars($s['name']); ?>
                  </a>
                  <?php if (isset($patientAdherencePct[$pid])): ?>
                    <?php
                      $pct = $patientAdherencePct[$pid];
                      $barColor = $pct >= 90 ? '#28a745' : ($pct >= 70 ? '#ffc107' : '#dc3545');
                    ?>
                    <span class="ml-2 d-inline-flex align-items-center" title="7-day adherence: <?php echo $pct; ?>%">
                      <span style="display:inline-block;width:50px;height:8px;background:#e9ecef;border-radius:4px;overflow:hidden;vertical-align:middle;">
                        <span style="display:block;height:100%;width:<?php echo $pct; ?>%;background:<?php echo $barColor; ?>;border-radius:4px;"></span>
                      </span>
                      <span class="small text-muted ml-1"><?php echo $pct; ?>%</span>
                    </span>
                  <?php endif; ?>
                  <?php if (isset($patientWeights[$pid])):
                    $pw = $patientWeights[$pid];
                    $arrow = '';
                    if ($pw['previous'] !== null) {
                        $diff = $pw['current'] - $pw['previous'];
                        if ($diff > 0.05) {
                            $arrow = ' &#9650;'; // ▲
                        } elseif ($diff < -0.05) {
                            $arrow = ' &#9660;'; // ▼
                        }
                    }
                  ?>
                    <a href="report_weight.php?patient_id=<?php echo (int) $pid; ?>"
                       class="ml-2 small text-muted text-decoration-none"
                       title="<?php echo displayWeight($pw['current']); ?> <?php echo weightUnitLabel(); ?> as of <?php echo htmlspecialchars($pw['date']); ?>"
                       ><?php echo displayWeight($pw['current'], 1); ?><?php echo weightUnitLabel(); ?><?php echo $arrow; ?></a>
                  <?php endif; ?>
                  <a href="patient_timeline.php?patient_id=<?php echo (int) $pid; ?>"
                     class="ml-2 small text-muted" title="View full timeline">timeline</a>
                </div>
                <span>
                  <?php if ($overdueCount > 0): ?>
                    <span class="badge badge-danger"><?php echo $overdueCount; ?> overdue</span>
                  <?php endif; ?>
                  <?php if ($dueSoonCount > 0): ?>
                    <span class="badge badge-warning"><?php echo $dueSoonCount; ?> due soon</span>
                  <?php endif; ?>
                  <?php if ($overdueCount === 0 && $dueSoonCount === 0): ?>
                    <span class="badge badge-success">all caught up</span>
                  <?php endif; ?>
                </span>
              </div>
              <?php if ($overdueCount > 0 || $dueSoonCount > 0): ?>
                <div class="ml-2 mt-1">
                  <?php foreach ($s['overdue'] as $dose): ?>
                    <div class="small text-danger">
                      <a href="record_intake.php?schedule_id=<?php echo (int) $dose['schedule_id']; ?>&patient_id=<?php echo (int) $dose['patient_id']; ?>"
                         class="text-danger" title="Record intake">
                        <?php echo htmlspecialchars($dose['medicine_name']); ?>
                        <span class="text-muted"><?php echo htmlspecialchars($dose['dosage']); ?></span>
                        — <?php
                        if ($dose['overdue_seconds'] !== null) {
                            echo 'overdue by ' . formatOverdueDuration((int) $dose['overdue_seconds']);
                        } else {
                            echo 'never taken';
                        }
                        ?>
                      </a>
                    </div>
                  <?php endforeach; ?>
                  <?php foreach ($s['due_soon'] as $dose): ?>
                    <div class="small text-warning">
                      <a href="record_intake.php?schedule_id=<?php echo (int) $dose['schedule_id']; ?>&patient_id=<?php echo (int) $dose['patient_id']; ?>"
                         class="text-dark" title="Record intake">
                        <?php echo htmlspecialchars($dose['medicine_name']); ?>
                        <span class="text-muted"><?php echo htmlspecialchars($dose['dosage']); ?></span>
                        — due soon
                      </a>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$hasAnyIssues): ?>
            <div class="p-3 text-muted text-center">No active schedules.</div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (count($lowSupply) > 0): ?>
      <!-- ── Low supply alerts ────────────────────────────────── -->
      <div class="card shadow-sm mt-4">
        <div class="card-header bg-white">
          <strong><?php etranslate('Low Supply Alerts'); ?></strong>
          <small class="text-muted">&lt; 7 days</small>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="thead-light">
              <tr>
                <th><?php etranslate('Medicine'); ?></th>
                <th class="text-right"><?php etranslate('Stock'); ?></th>
                <th class="text-right"><?php etranslate('Days Supply'); ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lowSupply as $item):
                $rowClass = $item['days_supply'] < 3 ? 'table-danger' : 'table-warning';
              ?>
                <tr class="<?php echo $rowClass; ?>">
                  <td>
                    <?php echo htmlspecialchars((string) $item['name']); ?>
                    <small class="text-muted"><?php echo htmlspecialchars((string) $item['dosage']); ?></small>
                  </td>
                  <td class="text-right"><?php echo $item['current_stock'] !== null ? number_format((float) $item['current_stock'], 1) : 'N/A'; ?></td>
                  <td class="text-right"><?php echo (int) $item['days_supply']; ?> days</td>
                  <td class="text-right">
                    <a href="inventory_refill.php?medicine_id=<?php echo (int) $item['medicine_id']; ?>"
                       class="btn btn-sm btn-success">Refill</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Right column: recent activity ──────────────────────── -->
    <div class="col-lg-5 mb-4">
      <div class="card shadow-sm">
        <div class="card-header bg-white">
          <strong><?php etranslate('Recent Intakes'); ?></strong>
          <small class="text-muted">— last 24h</small>
        </div>
        <div class="card-body p-0">
          <?php if (empty($recentRows)): ?>
            <div class="p-3 text-muted text-center">No intakes recorded in the last 24 hours.</div>
          <?php else: ?>
            <?php foreach ($recentRows as $r): ?>
              <div class="border-bottom px-3 py-2">
                <div class="d-flex justify-content-between">
                  <span class="font-weight-bold small">
                    <?php echo htmlspecialchars((string) $r[0]); ?>
                  </span>
                  <span class="text-muted small">
                    <?php echo htmlspecialchars(formatDateNicely((string) $r[2])); ?>
                  </span>
                </div>
                <div class="small text-muted">
                  <?php echo htmlspecialchars((string) $r[1]); ?>
                  <?php if (!empty($r[3])): ?>
                    — <em><?php echo htmlspecialchars((string) $r[3]); ?></em>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php echo print_trailer(); ?>
