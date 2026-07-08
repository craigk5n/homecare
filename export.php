<?php
/**
 * Unified intake-export UI.
 *
 * One form to pick an output format (Text, Markdown, CSV, PDF, FHIR),
 * a date range, and a delivery method (download or email). On submit the
 * form is routed to the matching export_intake_*.php endpoint — GET for
 * a browser download, POST (with CSRF token) for the "email me a copy"
 * path. The endpoints do the actual auth / query / render, so this page
 * stays a thin form over the existing export pipeline.
 */

declare(strict_types=1);

require_once 'includes/init.php';

require_role('caregiver');

$patientId = getActivePatientId();
$patient = $patientId > 0 ? getPatient($patientId) : null;

$startDefault = parse_export_date(getGetValue('start_date'), date('Y-m-01'));
$endDefault = parse_export_date(getGetValue('end_date'), date('Y-m-d'));

print_header();

if ($patient === null) {
    echo '<div class="container mt-3"><div class="alert alert-warning">'
        . 'No patient selected. Choose a patient first, then return to Export.'
        . '</div><p><a class="btn btn-secondary" href="dashboard.php">Go to dashboard</a></p></div>';
    echo print_trailer();
    exit;
}

/** @var array<string,string> $formats value => human label */
$formats = [
    'text' => 'Text (.txt)',
    'markdown' => 'Markdown (.md)',
    'csv' => 'CSV (.csv)',
    'pdf' => 'PDF (.pdf)',
    'fhir' => 'FHIR (.json)',
];
$selectedFormat = (string) getGetValue('format', 'text');
if (!isset($formats[$selectedFormat])) {
    $selectedFormat = 'text';
}

$nonce = htmlspecialchars($GLOBALS['NONCE'] ?? '');
?>
<div class="container mt-3" style="max-width: 640px;">
  <h4 class="mb-3">Export intake history — <?php echo htmlspecialchars((string) $patient['name']); ?></h4>

  <form id="export-form" action="export_intake_text.php" method="get">
    <?php print_form_key(); ?>
    <input type="hidden" name="patient_id" value="<?php echo (int) $patientId; ?>">

    <div class="form-group">
      <label for="export-format">Format</label>
      <select class="form-control" id="export-format" name="format">
        <?php foreach ($formats as $value => $label) { ?>
          <option value="<?php echo htmlspecialchars($value); ?>"<?php echo $value === $selectedFormat ? ' selected' : ''; ?>>
            <?php echo htmlspecialchars($label); ?>
          </option>
        <?php } ?>
      </select>
    </div>

    <div class="form-row">
      <div class="form-group col">
        <label for="export-start">From</label>
        <input type="date" class="form-control" id="export-start" name="start_date"
               value="<?php echo htmlspecialchars($startDefault); ?>">
      </div>
      <div class="form-group col">
        <label for="export-end">To</label>
        <input type="date" class="form-control" id="export-end" name="end_date"
               value="<?php echo htmlspecialchars($endDefault); ?>">
      </div>
    </div>

    <div class="form-group">
      <label class="d-block">Delivery</label>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="radio" name="delivery" id="delivery-download"
               value="download" checked>
        <label class="form-check-label" for="delivery-download">Download</label>
      </div>
      <div class="form-check form-check-inline" id="delivery-email-wrap">
        <input class="form-check-input" type="radio" name="delivery" id="delivery-email"
               value="email">
        <label class="form-check-label" for="delivery-email">Email me a copy</label>
      </div>
      <small id="email-note" class="form-text text-muted">
        Emailing requires a verified email with reminders enabled in Settings. PDF can't be emailed.
      </small>
    </div>

    <button type="submit" class="btn btn-primary">Export</button>
    <a class="btn btn-outline-secondary" href="report_intake.php?patient_id=<?php echo (int) $patientId; ?>">Cancel</a>
  </form>
</div>

<script nonce="<?php echo $nonce; ?>">
(function () {
  var form = document.getElementById('export-form');
  var fmt = document.getElementById('export-format');
  var emailRadio = document.getElementById('delivery-email');
  var downloadRadio = document.getElementById('delivery-download');
  var emailWrap = document.getElementById('delivery-email-wrap');
  var csrf = form.querySelector('input[name="csrf_form_key"]');

  var ENDPOINTS = {
    text: 'export_intake_text.php',
    markdown: 'export_intake_markdown.php',
    csv: 'export_intake_csv.php',
    pdf: 'export_intake_pdf.php',
    fhir: 'export_intake_fhir.php'
  };
  // PDF is the only format with no email path (report_intake never
  // offered "Email PDF"); everything else can be attached.
  var EMAILABLE = { text: true, markdown: true, csv: true, pdf: false, fhir: true };

  function syncEmail() {
    var canEmail = EMAILABLE[fmt.value] !== false;
    emailRadio.disabled = !canEmail;
    emailWrap.classList.toggle('text-muted', !canEmail);
    if (!canEmail && emailRadio.checked) {
      downloadRadio.checked = true;
    }
  }

  fmt.addEventListener('change', syncEmail);
  syncEmail();

  form.addEventListener('submit', function () {
    var isEmail = emailRadio.checked && !emailRadio.disabled;
    form.action = ENDPOINTS[fmt.value] || ENDPOINTS.text;
    form.method = isEmail ? 'post' : 'get';
    // The CSRF token is only valid on (and only needed for) the POST
    // email path. Drop it from GET downloads so it never lands in the
    // URL / browser history.
    if (csrf) {
      csrf.disabled = !isEmail;
    }
  });
})();
</script>
<?php
echo print_trailer();
