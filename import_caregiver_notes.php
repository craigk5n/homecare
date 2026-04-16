<?php
/**
 * HC-084: Caregiver-notes CSV/TSV import.
 *
 * Two-phase flow driven by POST `action`:
 *
 *   action=preview → parse + validate the uploaded file, render the
 *                    preview table (first 100 rows + file/row errors).
 *                    Valid payload is re-emitted into a hidden textarea
 *                    so the confirm POST re-parses the exact same bytes.
 *
 *   action=commit  → re-parse the hidden payload and, if still valid,
 *                    insert every row inside a transaction.
 *
 * The re-parse-on-commit design keeps us stateless (no session storage,
 * no signed blobs) and guarantees commit sees the same validation
 * verdict the operator saw on the preview screen.
 */

require_once 'includes/init.php';
require_role('admin');

use HomeCare\Database\DbiAdapter;
use HomeCare\Import\CaregiverNoteImporter;
use HomeCare\Import\ImportPlan;
use HomeCare\Import\ParsedRow;
use HomeCare\Repository\CaregiverNoteRepository;
use HomeCare\Repository\PatientRepository;

const IMPORT_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
const IMPORT_PREVIEW_LIMIT = 100;
const IMPORT_ALLOWED_MIME = [
    'text/csv',
    'text/tab-separated-values',
    'text/plain',
    'application/csv',
    'application/vnd.ms-excel',
    'application/octet-stream', // some OSes use this for .csv
];

$db = new DbiAdapter();
$importer = new CaregiverNoteImporter(
    $db,
    new CaregiverNoteRepository($db),
    new PatientRepository($db),
);

$action = (string) (getPostValue('action') ?? '');
$defaultPatientIdRaw = (string) (getPostValue('default_patient_id') ?? getGetValue('patient_id') ?? '');
$defaultPatientId = $defaultPatientIdRaw !== '' && ctype_digit($defaultPatientIdRaw)
    ? (int) $defaultPatientIdRaw
    : null;

$fileContent = null;
$fileName = null;
$formErrors = [];

if ($action === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    [$fileContent, $fileName, $uploadErrors] = read_uploaded_import_file();
    $formErrors = $uploadErrors;
}

if ($action === 'commit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fileContent = (string) getPostValue('file_content');
    $fileName = (string) (getPostValue('file_name') ?? 'import.csv');
    if ($fileContent === '') {
        $formErrors[] = 'Missing file content; please upload the file again.';
    }
}

print_header();
echo '<div class="container mt-3">';
echo '<h2>Import Caregiver Notes</h2>';
echo '<p class="text-muted">Upload a CSV, TSV, or semicolon-separated file with a header row.'
   . ' Required columns: <code>note_time</code>, <code>note</code>, plus one of'
   . ' <code>patient_id</code> or <code>patient_name</code>.</p>';

if ($formErrors !== []) {
    echo '<div class="alert alert-danger"><ul class="mb-0">';
    foreach ($formErrors as $e) {
        echo '<li>' . htmlspecialchars($e) . '</li>';
    }
    echo '</ul></div>';
}

if ($action === '' || $formErrors !== []) {
    render_upload_form($defaultPatientId);
    echo '</div>';
    echo print_trailer();
    exit;
}

$plan = $importer->parse((string) $fileContent, $defaultPatientId);

if ($action === 'commit' && $plan->isValid()) {
    try {
        $inserted = $importer->commit($plan);
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">Import failed during commit: '
           . htmlspecialchars($e->getMessage()) . '</div>';
        render_upload_form($defaultPatientId);
        echo '</div>';
        echo print_trailer();
        exit;
    }

    audit_log('note.imported', 'caregiver_note', null, [
        'filename' => $fileName,
        'row_count' => $inserted,
        'patient_id' => $defaultPatientId,
    ]);

    echo '<div class="alert alert-success"><strong>Imported '
       . (int) $inserted . ' note' . ($inserted === 1 ? '' : 's') . '.</strong>'
       . ' Source: ' . htmlspecialchars((string) $fileName) . '</div>';
    echo '<a href="import_caregiver_notes.php" class="btn btn-secondary">Import another file</a>';
    if ($defaultPatientId !== null) {
        echo ' <a href="list_caregiver_notes.php?patient_id=' . (int) $defaultPatientId
           . '" class="btn btn-primary ml-2">View Notes</a>';
    }
    echo '</div>';
    echo print_trailer();
    exit;
}

// Preview (either the user clicked Preview, or commit was blocked by errors)
render_preview($plan, (string) $fileContent, (string) $fileName, $defaultPatientId);
echo '</div>';
echo print_trailer();


/**
 * Pull the uploaded file off $_FILES, validate size / MIME, return
 * `[content, filename, errors]`. Content is null when any error fires.
 *
 * @return array{0:?string,1:?string,2:list<string>}
 */
function read_uploaded_import_file(): array
{
    $errors = [];
    if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
        return [null, null, ['No file was uploaded.']];
    }
    /** @var array{error?:int,size?:int,tmp_name?:string,name?:string,type?:string} $f */
    $f = $_FILES['import_file'];
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return [null, null, ['Please choose a file to upload.']];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return [null, null, ["File upload failed (code {$err})."]];
    }
    if (($f['size'] ?? 0) === 0) {
        return [null, null, ['Uploaded file is empty.']];
    }
    if (($f['size'] ?? 0) > IMPORT_MAX_BYTES) {
        return [null, null, ['File exceeds the 2 MB limit.']];
    }

    $tmp = (string) ($f['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) {
        return [null, null, ['Upload integrity check failed.']];
    }

    // MIME by content — client-supplied MIME is advisory.
    $detected = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $tmp);
            finfo_close($finfo);
        }
    }
    if ($detected !== null && $detected !== false
        && !in_array($detected, IMPORT_ALLOWED_MIME, true)
    ) {
        $errors[] = "Unsupported file type '{$detected}'. Upload a .csv, .tsv, or .txt file.";
    }

    $name = (string) ($f['name'] ?? 'import.csv');
    // Basic extension sanity-check as a second line of defence.
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'tsv', 'txt'], true)) {
        $errors[] = "Unsupported file extension '.{$ext}'. Upload .csv, .tsv, or .txt.";
    }

    if ($errors !== []) {
        return [null, $name, $errors];
    }

    $content = (string) file_get_contents($tmp);

    return [$content, $name, []];
}

function render_upload_form(?int $defaultPatientId): void
{
    echo '<form method="POST" action="import_caregiver_notes.php" enctype="multipart/form-data" class="mt-3">';
    print_form_key();
    echo '<input type="hidden" name="action" value="preview">';

    echo '<div class="form-group mb-3">';
    echo '  <label for="import_file">File (up to 2 MB):</label>';
    echo '  <input type="file" name="import_file" id="import_file" class="form-control-file"'
       . ' accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values,text/plain" required>';
    echo '</div>';

    echo '<div class="form-group mb-3">';
    echo '  <label for="default_patient_id">Default patient (applied when a row has no patient column):</label>';
    echo '  <select name="default_patient_id" id="default_patient_id" class="form-control">';
    echo '    <option value="">— none —</option>';
    foreach (getPatients(includeDisabled: true) as $p) {
        $sel = ($defaultPatientId !== null && $defaultPatientId === (int) $p['id']) ? ' selected' : '';
        echo '    <option value="' . (int) $p['id'] . '"' . $sel . '>'
           . htmlspecialchars($p['name']) . '</option>';
    }
    echo '  </select>';
    echo '</div>';

    echo '<button type="submit" class="btn btn-primary">Preview</button>';
    echo '</form>';
}

function render_preview(ImportPlan $plan, string $content, string $fileName, ?int $defaultPatientId): void
{
    $total = $plan->totalRows();
    $invalid = $plan->invalidRows();
    $invalidCount = count($invalid);

    echo '<h4 class="mt-4">Preview</h4>';
    echo '<p class="text-muted">Source file: <code>' . htmlspecialchars($fileName)
       . '</code> · ' . (int) $total . ' row' . ($total === 1 ? '' : 's') . ' parsed'
       . ($invalidCount > 0 ? " · <strong class='text-danger'>{$invalidCount} with errors</strong>" : '')
       . '</p>';

    if ($plan->fileErrors !== []) {
        echo '<div class="alert alert-danger"><strong>File-level errors:</strong><ul class="mb-0">';
        foreach ($plan->fileErrors as $e) {
            echo '<li>' . htmlspecialchars($e) . '</li>';
        }
        echo '</ul></div>';
    }

    if ($invalid !== []) {
        echo '<div class="alert alert-warning"><strong>Row errors (fix your file and re-upload):</strong><ul class="mb-0">';
        foreach (array_slice($invalid, 0, 50) as $row) {
            /** @var ParsedRow $row */
            echo '<li>Row ' . $row->lineNumber . ': '
               . htmlspecialchars(implode('; ', $row->errors)) . '</li>';
        }
        if (count($invalid) > 50) {
            echo '<li><em>' . (count($invalid) - 50) . ' more…</em></li>';
        }
        echo '</ul></div>';
    }

    if ($plan->rows !== []) {
        echo '<div class="table-responsive"><table class="table table-sm table-striped page-table">';
        echo '<thead class="thead-light"><tr>'
           . '<th style="width:4rem;">Line</th>'
           . '<th style="width:14rem;">When</th>'
           . '<th style="width:10rem;">Patient</th>'
           . '<th>Note</th>'
           . '</tr></thead><tbody>';
        foreach (array_slice($plan->rows, 0, IMPORT_PREVIEW_LIMIT) as $row) {
            /** @var ParsedRow $row */
            $rowClass = $row->isValid() ? '' : ' class="status-overdue"';
            echo '<tr' . $rowClass . '>';
            echo '<td>' . $row->lineNumber . '</td>';
            echo '<td>' . htmlspecialchars($row->noteTime ?? '—') . '</td>';
            echo '<td>' . ($row->patientId === null ? '—' : (int) $row->patientId) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row->note ?? '—')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if (count($plan->rows) > IMPORT_PREVIEW_LIMIT) {
            echo '<p class="text-muted small">Showing first '
               . IMPORT_PREVIEW_LIMIT . ' of ' . count($plan->rows) . ' rows.</p>';
        }
    }

    if ($plan->isValid()) {
        echo '<form method="POST" action="import_caregiver_notes.php" class="mt-3">';
        print_form_key();
        echo '<input type="hidden" name="action" value="commit">';
        echo '<input type="hidden" name="file_name" value="' . htmlspecialchars($fileName) . '">';
        if ($defaultPatientId !== null) {
            echo '<input type="hidden" name="default_patient_id" value="' . (int) $defaultPatientId . '">';
        }
        // Re-parse on commit from the exact same bytes the preview validated.
        echo '<textarea name="file_content" class="d-none" aria-hidden="true">'
           . htmlspecialchars($content) . '</textarea>';
        echo '<button type="submit" class="btn btn-success">Commit '
           . (int) $total . ' notes</button>';
        echo ' <a href="import_caregiver_notes.php" class="btn btn-secondary ml-2">Start over</a>';
        echo '</form>';
    } else {
        echo '<a href="import_caregiver_notes.php" class="btn btn-secondary">Start over</a>';
    }
}
