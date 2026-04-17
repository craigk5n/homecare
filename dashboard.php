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
            $patientStats[$patientId]['overdue'][] = $entry;
            $totalOverdue++;
        } elseif ($seconds <= $dueSoonSeconds) {
            $patientStats[$patientId]['due_soon'][] = $entry;
            $totalDueSoon++;
        } else {
            $patientStats[$patientId]['ok']++;
        }
    } else {
        $patientStats[$patientId]['overdue'][] = $entry;
        $totalOverdue++;
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

<div class="container mt-4" style="max-width:1100px;">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?php etranslate('Dashboard'); ?></h1>
    <small class="text-muted" title="Page refreshes automatically every 60 seconds">Auto-refresh: 60s</small>
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
                <a href="list_schedule.php?patient_id=<?php echo (int) $pid; ?>"
                   class="font-weight-bold text-dark text-decoration-none">
                  <?php echo htmlspecialchars($s['name']); ?>
                </a>
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
                        — overdue
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
