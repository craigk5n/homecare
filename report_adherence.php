<?php
/**
 * HC-023: Adherence report — table + bar chart.
 *
 * Shows 7-day / 30-day / 90-day adherence for each active schedule of
 * a given patient, with a grouped bar chart powered by Chart.js (bundled
 * locally at pub/chart.umd.min.js -- no CDN dependency, because our
 * deploys are LAN-isolated).
 *
 * `?range=7d|30d|90d|custom` picks which window the chart emphasises;
 * the table always shows all three for comparison. Custom opens two
 * date pickers and replaces the chart with that single window.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Report\PatientAdherenceReport;
use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\AdherenceService;

$patient_id = (int) (getIntValue('patient_id') ?? 0);
if ($patient_id <= 0) {
    die_miserable_death('Missing or invalid patient_id.');
}
$patient = getPatient($patient_id);

$range = getGetValue('range');
if (!in_array($range, ['7d', '30d', '90d', 'custom'], true)) {
    $range = '30d';
}

$today = date('Y-m-d');
$customStart = getGetValue('start_date');
$customEnd = getGetValue('end_date');
if (!is_string($customStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStart)) {
    $customStart = date('Y-m-d', strtotime('-14 days'));
}
if (!is_string($customEnd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEnd)) {
    $customEnd = $today;
}

$db = new DbiAdapter();
$report = new PatientAdherenceReport(
    $db,
    new AdherenceService(new ScheduleRepository($db), new IntakeRepository($db)),
);

// For the custom range, widen the schedule filter to the custom window so
// discontinued schedules (e.g. a 4-week antibiotic course from last autumn)
// surface when the user scrolls back to a historical period. Default 7/30/90
// ranges keep the built-in 90-day filter.
if ($range === 'custom') {
    $rows = $report->build($patient_id, $today, $customStart, $customEnd);
} else {
    $rows = $report->build($patient_id, $today);
}

// If custom, compute a fourth column's worth of data per-row.
$customLabel = $customStart . ' → ' . $customEnd;
$customRows = [];
if ($range === 'custom') {
    foreach ($rows as $r) {
        $customRows[$r['schedule_id']] = $report->calculateCustom(
            $r['schedule_id'],
            $customStart,
            $customEnd,
        );
    }
}

/**
 * Pick a table-cell class by adherence data.
 * - gray   coverage_days == 0 (schedule wasn't active in the window — N/A)
 * - green  ≥ 90
 * - yellow 70-89
 * - red    < 70
 *
 * @param array{coverage_days:int,percentage:float} $cell
 */
function adherence_cell_class(array $cell): string
{
    if ($cell['coverage_days'] === 0) return 'table-secondary';
    $pct = (float) $cell['percentage'];
    if ($pct >= 90.0) return 'table-success';
    if ($pct >= 70.0) return 'table-warning';
    return 'table-danger';
}

// Data for the chart (array of medicine_name + the selected window's rate
// and coverage-day counts). The coverage arrays let the chart script gray
// out "not active in this window" bars instead of rendering them as 0%.
$chartLabels = array_map(
    static fn (array $r): string => $r['medicine_name'],
    $rows
);
$chart7d  = array_map(static fn (array $r): float => (float) $r['adherence_7d']['percentage'], $rows);
$chart30d = array_map(static fn (array $r): float => (float) $r['adherence_30d']['percentage'], $rows);
$chart90d = array_map(static fn (array $r): float => (float) $r['adherence_90d']['percentage'], $rows);
$coverage7d  = array_map(static fn (array $r): int => (int) $r['adherence_7d']['coverage_days'], $rows);
$coverage30d = array_map(static fn (array $r): int => (int) $r['adherence_30d']['coverage_days'], $rows);
$coverage90d = array_map(static fn (array $r): int => (int) $r['adherence_90d']['coverage_days'], $rows);
$chartCustom = [];
$coverageCustom = [];
if ($range === 'custom') {
    foreach ($rows as $r) {
        $c = $customRows[$r['schedule_id']] ?? ['percentage' => 0.0, 'coverage_days' => 0];
        $chartCustom[]    = (float) $c['percentage'];
        $coverageCustom[] = (int) $c['coverage_days'];
    }
}

print_header();
?>
<h2>Adherence Report: <?= htmlspecialchars($patient['name']) ?></h2>

<form class="row g-2 align-items-end mb-3" method="get" action="report_adherence.php">
  <input type="hidden" name="patient_id" value="<?= (int) $patient_id ?>">
  <div class="col-auto">
    <label class="form-label mb-0" for="range">Chart range</label>
    <select class="form-control" id="range" name="range" data-autosubmit>
      <?php foreach (['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'custom' => 'Custom'] as $v => $lbl): ?>
        <option value="<?= $v ?>"<?= $range === $v ? ' selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($range === 'custom'): ?>
    <div class="col-auto">
      <label class="form-label mb-0" for="start_date">Start</label>
      <input type="date" class="form-control" id="start_date" name="start_date" value="<?= htmlspecialchars($customStart) ?>">
    </div>
    <div class="col-auto">
      <label class="form-label mb-0" for="end_date">End</label>
      <input type="date" class="form-control" id="end_date" name="end_date" value="<?= htmlspecialchars($customEnd) ?>">
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-primary">Apply</button>
    </div>
  <?php endif; ?>
</form>

<?php if ($rows === []): ?>
  <div class="alert alert-info">No active medications for this patient.</div>
<?php else: ?>

<div class="mb-4" style="max-width: 960px;">
  <canvas id="adherenceChart" height="280"></canvas>
</div>

<div class="table-responsive">
  <table class="table table-bordered table-sm align-middle">
    <thead class="thead-light">
      <tr>
        <th>Medication</th>
        <th>Dosage</th>
        <th>Frequency</th>
        <th class="text-end">7-day</th>
        <th class="text-end">30-day</th>
        <th class="text-end">90-day</th>
        <?php if ($range === 'custom'): ?>
          <th class="text-end"><?= htmlspecialchars($customLabel) ?></th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php
      /**
       * Render one adherence cell. When the schedule wasn't active in
       * the window at all (coverage_days == 0), show an em-dash with a
       * tooltip rather than a misleading "0.0%".
       *
       * @param array{expected:int,actual:int,percentage:float,coverage_days:int,window_days:int} $cell
       */
      $renderAdherenceCell = static function (array $cell): string {
          $cls = adherence_cell_class($cell);
          if ($cell['coverage_days'] === 0) {
              $title = 'Schedule was not active during this window.';
              return '<td class="text-end ' . $cls . '" title="' . htmlspecialchars($title) . '">'
                   . '<span aria-label="Not applicable">&mdash;</span>'
                   . '<small class="text-muted d-block">N/A</small>'
                   . '</td>';
          }
          $pct      = number_format((float) $cell['percentage'], 1);
          $coverage = (int) $cell['coverage_days'] . ' of ' . (int) $cell['window_days'] . ' days';
          return '<td class="text-end ' . $cls . '">'
               . htmlspecialchars($pct) . '%'
               . '<small class="text-muted d-block">'
               . (int) $cell['actual'] . '/' . (int) $cell['expected']
               . ' &middot; ' . htmlspecialchars($coverage)
               . '</small></td>';
      };
      ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['medicine_name']) ?></td>
          <td><?= htmlspecialchars($r['dosage']) ?></td>
          <td><?= htmlspecialchars($r['frequency']) ?></td>
          <?php foreach (['adherence_7d', 'adherence_30d', 'adherence_90d'] as $col): ?>
            <?= $renderAdherenceCell($r[$col]) ?>
          <?php endforeach; ?>
          <?php if ($range === 'custom'):
              $c = $customRows[$r['schedule_id']] ?? [
                  'expected' => 0, 'actual' => 0, 'percentage' => 0.0,
                  'coverage_days' => 0, 'window_days' => 0,
              ];
          ?>
            <?= $renderAdherenceCell($c) ?>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
document.querySelectorAll('[data-autosubmit]').forEach(function(el) {
  el.addEventListener('change', function() { this.form.submit(); });
});
</script>
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>" src="pub/chart.umd.min.js"></script>
<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
(function () {
  'use strict';
  var labels = <?= json_encode($chartLabels) ?>;
  var data7  = <?= json_encode($chart7d) ?>;
  var data30 = <?= json_encode($chart30d) ?>;
  var data90 = <?= json_encode($chart90d) ?>;
  var cov7   = <?= json_encode($coverage7d) ?>;
  var cov30  = <?= json_encode($coverage30d) ?>;
  var cov90  = <?= json_encode($coverage90d) ?>;
  var range  = <?= json_encode($range) ?>;
  var customLabel = <?= json_encode($customLabel) ?>;
  var dataCustom     = <?= json_encode($chartCustom) ?>;
  var coverageCustom = <?= json_encode($coverageCustom) ?>;

  // Colour per value; greyed-out when the schedule wasn't active in
  // this window (coverage_days == 0) so "N/A" is visually distinct from
  // "0% — took none of the expected doses".
  function colorFor(value, coverageDays) {
    if (coverageDays <= 0)   return 'rgba(173,181,189,0.35)'; // gray (N/A)
    if (value >= 90)         return 'rgba(40,167,69,0.75)';   // green
    if (value >= 70)         return 'rgba(255,193,7,0.75)';   // yellow
    return 'rgba(220,53,69,0.75)';                             // red
  }
  function colorsFor(arr, coverage) {
    return arr.map(function (v, i) { return colorFor(v, coverage[i] || 0); });
  }

  // The chart shows exactly one dataset: the range the user picked. The
  // table below still shows all three windows for cross-comparison, so
  // the chart doesn't need to duplicate that.
  var seriesData, seriesCoverage, seriesLabel;
  if (range === 'custom') {
    seriesData     = dataCustom;
    seriesCoverage = coverageCustom;
    seriesLabel    = customLabel;
  } else if (range === '7d') {
    seriesData     = data7;
    seriesCoverage = cov7;
    seriesLabel    = 'Last 7 days';
  } else if (range === '90d') {
    seriesData     = data90;
    seriesCoverage = cov90;
    seriesLabel    = 'Last 90 days';
  } else {
    seriesData     = data30;
    seriesCoverage = cov30;
    seriesLabel    = 'Last 30 days';
  }

  // Render the gray "N/A" bars as a small positive value so they're
  // visible on the axis (a zero-height bar is indistinguishable from
  // missing data). The tooltip + legend make clear that it's N/A, not 3%.
  var NA_DISPLAY_HEIGHT = 3;
  var displayData = seriesData.map(function (v, i) {
    return (seriesCoverage[i] || 0) === 0 ? NA_DISPLAY_HEIGHT : v;
  });

  var datasets = [
    {
      label: seriesLabel,
      data: displayData,
      backgroundColor: colorsFor(seriesData, seriesCoverage),
      // stash the raw values + coverage on the dataset so the tooltip
      // can report the true number instead of NA_DISPLAY_HEIGHT.
      _rawValues: seriesData,
      _coverage: seriesCoverage
    }
  ];

  var ctx = document.getElementById('adherenceChart').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: { labels: labels, datasets: datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          suggestedMax: 110,
          ticks: { callback: function (v) { return v + '%'; } }
        },
        x: { ticks: { autoSkip: false, maxRotation: 45, minRotation: 0 } }
      },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: function (c) {
              var coverage = c.dataset._coverage[c.dataIndex] || 0;
              if (coverage === 0) {
                return c.dataset.label + ': N/A (not active in this window)';
              }
              var pct = c.dataset._rawValues[c.dataIndex];
              return c.dataset.label + ': ' + pct.toFixed(1) + '% (' + coverage + ' days active)';
            }
          }
        }
      }
    }
  });
})();
</script>

<?php endif; ?>

<?php echo print_trailer(); ?>
