<?php
/**
 * HC-021: Printable, print-first medication summary for a patient.
 *
 * A one-sheet document a caregiver can fold into a folder for a vet or
 * doctor visit: patient name + date at the top, active medications in
 * a table, recently-discontinued medications in a smaller secondary
 * section. Uses $friendly=true to suppress the nav/menu/chrome so the
 * page prints clean.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Report\MedicationSummaryReport;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\InventoryService;

require_once __DIR__ . '/includes/email_export_dispatch.php';

// Suppress chrome; pages that set $friendly=true get a minimal header.
$friendly = true;

$patient_id = (int) (getIntValue('patient_id') ?? 0);
if ($patient_id <= 0) {
    die_miserable_death('Missing or invalid patient_id.');
}

$db = new DbiAdapter();
$report = new MedicationSummaryReport(
    $db,
    new InventoryService(new InventoryRepository($db), new ScheduleRepository($db)),
);

$summary = $report->build($patient_id, date('Y-m-d'));
if ($summary === null) {
    die_miserable_death('Patient not found.');
}

// HC-108: delivery=email branch renders the summary into a short
// plain-text body instead of streaming the printable HTML page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && getPostValue('delivery') === 'email') {
    $friendly = false; // undo the printable-layout flag; we want the nav on the feedback page
    hc108_email_and_exit(
        type: 'medication_summary',
        patientId: $patient_id,
        startDate: null,
        endDate: null,
        patient: $summary['patient'],
    );
}

print_header();

/**
 * @param float $value
 */
function medsum_format_float($value): string
{
    $s = rtrim(rtrim(sprintf('%.4f', (float) $value), '0'), '.');

    return $s === '' ? '0' : $s;
}

?>
<style>
  /* HC-021: print-first layout. On screen it still looks reasonable,
     but the real target is paper -- no colors, compact table, sensible
     margins. */
  .med-summary {
    max-width: 780px;
    margin: 1.5rem auto;
    font-family: Georgia, 'Times New Roman', serif;
    color: #000;
  }
  .med-summary h1 {
    font-size: 1.5rem;
    margin: 0 0 0.25rem;
    border-bottom: 2px solid #000;
    padding-bottom: 0.25rem;
  }
  .med-summary .generated {
    font-size: 0.85rem;
    color: #444;
    margin-bottom: 1rem;
  }
  .med-summary table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1.5rem;
  }
  .med-summary th, .med-summary td {
    border: 1px solid #333;
    padding: 0.35rem 0.5rem;
    text-align: left;
    vertical-align: top;
    font-size: 0.95rem;
  }
  .med-summary th {
    background: #f0f0f0;
    font-weight: 600;
  }
  .med-summary h2 {
    font-size: 1.1rem;
    margin: 1rem 0 0.5rem;
    border-bottom: 1px solid #999;
  }
  .med-summary .meta {
    margin-bottom: 0.75rem;
    font-size: 0.9rem;
  }
  .med-summary .empty {
    font-style: italic;
    color: #555;
  }
  .med-summary .print-only { display: none; }
  .med-summary .print-actions {
    margin: 1rem 0;
  }
  @media print {
    @page { margin: 0.5in; }
    body { background: #fff !important; font-size: 11pt; }
    .med-summary { margin: 0; max-width: none; }
    .med-summary th { background: #e8e8e8 !important; }
    .no-print, .print-actions { display: none !important; }
    .med-summary .print-only { display: block; }
  }
</style>

<div class="med-summary">
  <h1>Medication Summary: <?= htmlspecialchars($summary['patient']['name']) ?></h1>
  <div class="generated">Generated <?= htmlspecialchars($summary['generated_at']) ?></div>

  <div class="print-actions no-print">
    <button data-print class="btn btn-primary btn-sm">Print</button>
    <form method="post" class="d-inline">
      <?php print_form_key(); ?>
      <input type="hidden" name="patient_id" value="<?= (int) $summary['patient']['id'] ?>">
      <input type="hidden" name="delivery" value="email">
      <button type="submit" class="btn btn-outline-secondary btn-sm"
              title="Email this summary to my account's address">Email to me</button>
    </form>
    <a href="list_schedule.php?patient_id=<?= (int) $summary['patient']['id'] ?>"
       class="btn btn-outline-secondary btn-sm">Back to Schedule</a>
  </div>

  <h2>Active Medications</h2>
  <?php if ($summary['active'] === []): ?>
    <p class="empty">No active medications.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Medication</th>
          <th>Dosage</th>
          <th>Frequency</th>
          <th>Unit / dose</th>
          <th>Start</th>
          <th>Remaining</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($summary['active'] as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['medicine_name']) ?></td>
            <td><?= htmlspecialchars($row['dosage']) ?></td>
            <td><?= htmlspecialchars($row['frequency']) ?></td>
            <td><?= htmlspecialchars(medsum_format_float($row['unit_per_dose'])) ?></td>
            <td><?= htmlspecialchars($row['start_date']) ?></td>
            <td>
              <?php if ($row['last_inventory'] === null): ?>
                <span class="empty">no stock recorded</span>
              <?php else: ?>
                <?= htmlspecialchars(medsum_format_float($row['remaining_doses'])) ?> doses
                (<?= (int) $row['remaining_days'] ?> days)
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>Recently Discontinued
    <small>(last <?= (int) $summary['discontinued_window_days'] ?> days)</small>
  </h2>
  <?php if ($summary['discontinued'] === []): ?>
    <p class="empty">No medications discontinued in this window.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Medication</th>
          <th>Dosage</th>
          <th>Frequency</th>
          <th>Start</th>
          <th>End</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($summary['discontinued'] as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['medicine_name']) ?></td>
            <td><?= htmlspecialchars($row['dosage']) ?></td>
            <td><?= htmlspecialchars($row['frequency']) ?></td>
            <td><?= htmlspecialchars($row['start_date']) ?></td>
            <td><?= htmlspecialchars($row['end_date']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
document.addEventListener('click', function(e) {
  if (e.target.closest('[data-print]')) window.print();
});
</script>
<?php echo print_trailer(); ?>
