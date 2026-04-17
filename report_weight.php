<?php
/**
 * Patient weight history with Chart.js line chart.
 *
 * Shows a trend line of recorded weights, a history table, and a
 * form to add new weight entries. Linked from the Reports menu.
 */

declare(strict_types=1);

require_once 'includes/init.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\WeightRepository;

$patient_id = (int) (getIntValue('patient_id') ?? 0);
if ($patient_id <= 0) {
    header('Location: index.php');
    exit;
}

$patient = getPatient($patient_id);
$patientName = $patient['name'];

$db = new DbiAdapter();
$weightRepo = new WeightRepository($db);

// Handle new weight entry (POST).
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && getPostValue('action') === 'add_weight') {
    require_role('caregiver');
    $newWeight = getPostValue('weight_kg');
    $newDate = getPostValue('recorded_at');
    $newNote = trim((string) (getPostValue('note') ?? ''));

    if ($newWeight !== null && $newWeight !== '' && (float) $newWeight > 0) {
        $recordedAt = !empty($newDate) ? (string) $newDate : date('Y-m-d');
        $weightRepo->insert($patient_id, (float) $newWeight, $recordedAt, $newNote !== '' ? $newNote : null);

        // Also update the patient's current weight.
        dbi_execute(
            'UPDATE hc_patients SET weight_kg = ?, weight_as_of = ? WHERE id = ?',
            [(float) $newWeight, $recordedAt, $patient_id],
        );

        audit_log('weight.recorded', 'patient', $patient_id, [
            'weight_kg' => (float) $newWeight,
            'recorded_at' => $recordedAt,
        ]);

        $flash = ['type' => 'success', 'text' => 'Weight recorded: ' . number_format((float) $newWeight, 2) . ' kg'];
    } else {
        $flash = ['type' => 'danger', 'text' => 'Please enter a valid weight.'];
    }
}

$history = $weightRepo->getHistory($patient_id, 200);

// Prepare chart data (oldest first for the X-axis).
$chartHistory = array_reverse($history);
$chartLabels = array_map(static fn(array $r): string => $r['recorded_at'], $chartHistory);
$chartValues = array_map(static fn(array $r): float => $r['weight_kg'], $chartHistory);

// Compute stats.
$latest = !empty($history) ? $history[0] : null;
$minWeight = !empty($chartValues) ? min($chartValues) : null;
$maxWeight = !empty($chartValues) ? max($chartValues) : null;
$changeText = '';
if (count($chartValues) >= 2) {
    $first = $chartValues[0];
    $last = $chartValues[count($chartValues) - 1];
    $diff = $last - $first;
    $sign = $diff >= 0 ? '+' : '';
    $changeText = $sign . number_format($diff, 2) . ' kg overall';
}

print_header();
?>

<?php if ($flash): ?>
  <div class="alert alert-<?php echo $flash['type']; ?> alert-dismissible fade show mt-2" role="alert">
    <?php echo htmlspecialchars($flash['text']); ?>
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
  </div>
<?php endif; ?>

<div class="container mt-4" style="max-width:1100px;">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0"><?php echo htmlspecialchars($patientName); ?> — Weight History</h1>
    <a href="list_schedule.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-sm btn-outline-secondary">Back to Schedule</a>
  </div>

  <!-- Stats row -->
  <?php if ($latest): ?>
  <div class="row mb-4">
    <div class="col-md-3 mb-2">
      <div class="card h-100">
        <div class="card-body text-center py-3">
          <div class="h3 mb-0"><?php echo number_format($latest['weight_kg'], 2); ?> kg</div>
          <small class="text-muted">Current (<?php echo htmlspecialchars($latest['recorded_at']); ?>)</small>
        </div>
      </div>
    </div>
    <?php if ($minWeight !== null && $maxWeight !== null): ?>
    <div class="col-md-3 mb-2">
      <div class="card h-100">
        <div class="card-body text-center py-3">
          <div class="h5 mb-0"><?php echo number_format($minWeight, 2); ?> – <?php echo number_format($maxWeight, 2); ?> kg</div>
          <small class="text-muted">Range</small>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($changeText !== ''): ?>
    <div class="col-md-3 mb-2">
      <div class="card h-100">
        <div class="card-body text-center py-3">
          <div class="h5 mb-0"><?php echo htmlspecialchars($changeText); ?></div>
          <small class="text-muted">Change</small>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <div class="col-md-3 mb-2">
      <div class="card h-100">
        <div class="card-body text-center py-3">
          <div class="h5 mb-0"><?php echo count($history); ?></div>
          <small class="text-muted">Readings</small>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="row">
    <!-- Chart -->
    <div class="col-lg-8 mb-4">
      <?php if (count($chartValues) >= 2): ?>
      <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Weight Trend</strong></div>
        <div class="card-body">
          <canvas id="weightChart" height="280"></canvas>
        </div>
      </div>
      <?php elseif (count($chartValues) === 1): ?>
      <div class="card shadow-sm">
        <div class="card-body text-center text-muted py-5">
          Only one weight recorded. Add another to see the trend chart.
        </div>
      </div>
      <?php else: ?>
      <div class="card shadow-sm">
        <div class="card-body text-center text-muted py-5">
          No weight history yet. Use the form to record the first weight.
        </div>
      </div>
      <?php endif; ?>

      <!-- History table -->
      <?php if (!empty($history)): ?>
      <div class="card shadow-sm mt-4">
        <div class="card-header bg-white"><strong>All Readings</strong></div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="thead-light">
              <tr>
                <th>Date</th>
                <th class="text-right">Weight (kg)</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($history as $row): ?>
              <tr>
                <td><?php echo htmlspecialchars($row['recorded_at']); ?></td>
                <td class="text-right"><?php echo number_format($row['weight_kg'], 2); ?></td>
                <td class="text-muted"><?php echo $row['note'] ? htmlspecialchars($row['note']) : '—'; ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Add weight form -->
    <div class="col-lg-4 mb-4">
      <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Record Weight</strong></div>
        <div class="card-body">
          <form method="post">
            <?php print_form_key(); ?>
            <input type="hidden" name="action" value="add_weight">
            <div class="form-group">
              <label for="weight_kg">Weight (kg)</label>
              <input type="number" step="0.01" min="0.01" class="form-control"
                     id="weight_kg" name="weight_kg" required
                     value="<?php echo $latest ? number_format($latest['weight_kg'], 2, '.', '') : ''; ?>">
            </div>
            <div class="form-group">
              <label for="recorded_at">Date</label>
              <input type="date" class="form-control" id="recorded_at" name="recorded_at"
                     value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
              <label for="note">Note <small class="text-muted">(optional)</small></label>
              <input type="text" class="form-control" id="note" name="note"
                     placeholder="e.g., After breakfast">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Record Weight</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (count($chartValues) >= 2): ?>
<script src="pub/chart.umd.min.js" nonce="<?php echo htmlspecialchars($GLOBALS['NONCE'] ?? ''); ?>"></script>
<script nonce="<?php echo htmlspecialchars($GLOBALS['NONCE'] ?? ''); ?>">
(function() {
  var labels = <?php echo json_encode($chartLabels); ?>;
  var values = <?php echo json_encode($chartValues); ?>;

  var ctx = document.getElementById('weightChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Weight (kg)',
        data: values,
        borderColor: 'rgba(44, 122, 123, 1)',
        backgroundColor: 'rgba(44, 122, 123, 0.1)',
        fill: true,
        tension: 0.3,
        pointBackgroundColor: 'rgba(44, 122, 123, 1)',
        pointRadius: 4,
        pointHoverRadius: 6,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(context) {
              return context.parsed.y.toFixed(2) + ' kg';
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: false,
          ticks: {
            callback: function(value) { return value + ' kg'; }
          }
        },
        x: {
          ticks: {
            autoSkip: true,
            maxTicksLimit: 12,
            maxRotation: 45
          }
        }
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php echo print_trailer(); ?>
