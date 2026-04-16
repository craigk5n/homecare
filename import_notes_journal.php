<?php
/**
 * HC-085: Paste-a-journal import for caregiver notes.
 *
 * Three-state flow driven by POST `action`:
 *
 *   (no action) → render the textarea + patient picker.
 *   preview     → parse, annotate duplicates against the selected
 *                 patient, render the preview grouped by day.
 *   commit      → re-parse the same textarea content, re-annotate,
 *                 then insert non-duplicates inside a transaction.
 *
 * Re-parsing on commit keeps the page stateless (no session blobs,
 * no signed payloads) and guarantees commit honours the exact bytes
 * the operator saw in the preview.
 */

require_once 'includes/init.php';
require_role('caregiver');

use HomeCare\Database\DbiAdapter;
use HomeCare\Import\JournalImporter;
use HomeCare\Import\JournalImportPlan;
use HomeCare\Import\JournalParser;
use HomeCare\Import\ParsedJournalEntry;
use HomeCare\Repository\CaregiverNoteRepository;

const JOURNAL_MAX_BYTES = 1024 * 1024; // 1 MB paste cap

$db = new DbiAdapter();
$parser = new JournalParser();
$importer = new JournalImporter($db, new CaregiverNoteRepository($db));

$action = (string) (getPostValue('action') ?? '');
$content = (string) (getPostValue('journal_text') ?? '');
$patientIdRaw = (string) (getPostValue('patient_id') ?? getGetValue('patient_id') ?? '');
$patientId = ($patientIdRaw !== '' && ctype_digit($patientIdRaw)) ? (int) $patientIdRaw : 0;

$formErrors = [];
if ($action === 'preview' || $action === 'commit') {
    if ($patientId <= 0 || !patientExistsById($patientId)) {
        $formErrors[] = 'Please pick a patient.';
    }
    if (strlen($content) === 0) {
        $formErrors[] = 'Please paste some journal text.';
    } elseif (strlen($content) > JOURNAL_MAX_BYTES) {
        $formErrors[] = 'Paste exceeds the 1 MB limit.';
    }
}

print_header();
echo '<div class="container mt-3">';
echo '<h2>Import Notes from Journal Paste</h2>';
echo '<p class="text-muted">Paste your running notes below. The importer recognises'
   . ' date headers like <em>Wednesday April 15, 2026</em> and entry lines like'
   . ' <em>7:45 AM Ate breakfast</em>. Review the preview before committing.</p>';

if ($formErrors !== []) {
    echo '<div class="alert alert-danger"><ul class="mb-0">';
    foreach ($formErrors as $e) {
        echo '<li>' . htmlspecialchars($e) . '</li>';
    }
    echo '</ul></div>';
}

if ($action === '' || $formErrors !== []) {
    render_upload_form($content, $patientId);
    echo '</div>';
    echo print_trailer();
    exit;
}

$plan = $parser->parse($content);
$plan = $importer->annotateDuplicates($plan, $patientId);

if ($action === 'commit') {
    if (!$plan->isValid()) {
        echo '<div class="alert alert-danger">Cannot commit: file has errors.</div>';
        render_preview($plan, $content, $patientId);
        echo '</div>';
        echo print_trailer();
        exit;
    }

    try {
        $result = $importer->commit($plan, $patientId);
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">Import failed during commit: '
           . htmlspecialchars($e->getMessage()) . '</div>';
        render_upload_form($content, $patientId);
        echo '</div>';
        echo print_trailer();
        exit;
    }

    audit_log('note.journal_imported', 'caregiver_note', null, [
        'patient_id' => $patientId,
        'parsed_count' => count($plan->entries),
        'inserted_count' => $result['inserted'],
        'skipped_duplicates' => $result['skipped'],
        'non_monotonic_count' => $plan->nonMonotonicCount(),
        'source_bytes' => strlen($content),
    ]);

    echo '<div class="alert alert-success"><strong>Imported '
       . (int) $result['inserted'] . ' note' . ($result['inserted'] === 1 ? '' : 's')
       . '.</strong>';
    if ($result['skipped'] > 0) {
        echo ' Skipped ' . (int) $result['skipped'] . ' duplicate'
           . ($result['skipped'] === 1 ? '' : 's') . '.';
    }
    echo '</div>';
    echo '<a href="list_caregiver_notes.php?patient_id=' . (int) $patientId
       . '" class="btn btn-primary">View Notes</a>';
    echo ' <a href="import_notes_journal.php" class="btn btn-secondary ml-2">Paste another</a>';
    echo '</div>';
    echo print_trailer();
    exit;
}

// action === 'preview'
render_preview($plan, $content, $patientId);
echo '</div>';
echo print_trailer();


function render_upload_form(string $content, int $activePatientId): void
{
    echo '<form method="POST" action="import_notes_journal.php" class="mt-3">';
    print_form_key();
    echo '<input type="hidden" name="action" value="preview">';

    echo '<div class="form-group mb-3">';
    echo '  <label for="patient_id">Patient:</label>';
    echo '  <select name="patient_id" id="patient_id" class="form-control" required>';
    echo '    <option value="">— choose one —</option>';
    foreach (getPatients(includeDisabled: true) as $p) {
        $sel = ((int) $p['id'] === $activePatientId) ? ' selected' : '';
        echo '    <option value="' . (int) $p['id'] . '"' . $sel . '>'
           . htmlspecialchars($p['name']) . '</option>';
    }
    echo '  </select>';
    echo '</div>';

    echo '<div class="form-group mb-3">';
    echo '  <label for="journal_text">Journal paste (up to 1 MB):</label>';
    echo '  <textarea name="journal_text" id="journal_text" class="form-control"'
       . ' rows="14" spellcheck="false"'
       . ' placeholder="Wednesday April 15, 2026&#10;&#10;7:45 AM Ate breakfast.&#10;&#10;1:00 PM Ate lunch.">'
       . htmlspecialchars($content) . '</textarea>';
    echo '</div>';

    echo '<button type="submit" class="btn btn-primary">Preview</button>';
    echo '</form>';
}

function render_preview(JournalImportPlan $plan, string $content, int $patientId): void
{
    $total = count($plan->entries);
    $dupCount = $plan->duplicateCount();
    $nmCount = $plan->nonMonotonicCount();
    $toInsert = $total - $dupCount;

    echo '<h4 class="mt-4">Preview</h4>';
    echo '<p class="text-muted">' . (int) $total . ' entr'
       . ($total === 1 ? 'y' : 'ies') . ' parsed'
       . ($dupCount > 0 ? " · {$dupCount} duplicate" . ($dupCount === 1 ? '' : 's') . ' will be skipped' : '')
       . ($nmCount > 0 ? " · <strong class='text-warning'>{$nmCount} time" . ($nmCount === 1 ? '' : 's')
                       . ' ran backward — please confirm</strong>' : '')
       . '</p>';

    if ($plan->errors !== []) {
        echo '<div class="alert alert-danger"><strong>File errors (fix and re-paste):</strong><ul class="mb-0">';
        foreach ($plan->errors as $e) {
            echo '<li>' . htmlspecialchars($e) . '</li>';
        }
        echo '</ul></div>';
    }

    if ($plan->entries !== []) {
        // Group entries by date for readability
        $byDate = [];
        foreach ($plan->entries as $entry) {
            $date = substr($entry->noteTime, 0, 10);
            $byDate[$date] ??= [];
            $byDate[$date][] = $entry;
        }

        echo '<div class="table-responsive"><table class="table table-sm table-striped">';
        echo '<thead class="thead-light"><tr>'
           . '<th style="width:10rem;">Time</th>'
           . '<th style="width:8rem;">Status</th>'
           . '<th>Note</th>'
           . '</tr></thead><tbody>';
        foreach ($byDate as $date => $entries) {
            echo '<tr><th colspan="3" class="bg-light">'
               . htmlspecialchars(date('l, F j, Y', (int) strtotime($date . ' 00:00:00')))
               . '</th></tr>';
            foreach ($entries as $entry) {
                /** @var ParsedJournalEntry $entry */
                $rowClass = '';
                if ($entry->isDuplicate) {
                    $rowClass = ' class="text-muted"';
                } elseif ($entry->confidence === ParsedJournalEntry::CONF_NON_MONOTONIC) {
                    $rowClass = ' class="status-due-soon"';
                }
                echo '<tr' . $rowClass . '>';
                echo '<td>' . htmlspecialchars(substr($entry->noteTime, 11, 5)) . '</td>';
                echo '<td>' . render_status_badge($entry) . '</td>';
                echo '<td>' . nl2br(htmlspecialchars($entry->note)) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    if ($plan->isValid()) {
        echo '<div class="alert alert-info">Nothing will be inserted until you click Commit.</div>';
        echo '<form method="POST" action="import_notes_journal.php">';
        print_form_key();
        echo '<input type="hidden" name="action" value="commit">';
        echo '<input type="hidden" name="patient_id" value="' . (int) $patientId . '">';
        echo '<textarea name="journal_text" class="d-none" aria-hidden="true">'
           . htmlspecialchars($content) . '</textarea>';
        echo '<button type="submit" class="btn btn-success">Commit ' . (int) $toInsert
           . ' note' . ($toInsert === 1 ? '' : 's') . '</button>';
        echo ' <a href="import_notes_journal.php" class="btn btn-secondary ml-2">Start over</a>';
        echo '</form>';
    } else {
        echo '<a href="import_notes_journal.php" class="btn btn-secondary">Start over</a>';
    }
}

function render_status_badge(ParsedJournalEntry $entry): string
{
    if ($entry->isDuplicate) {
        return '<span class="badge badge-secondary">duplicate — will skip</span>';
    }
    if ($entry->confidence === ParsedJournalEntry::CONF_NON_MONOTONIC) {
        return '<span class="badge badge-warning" title="Time ran backward vs previous entry">non-monotonic</span>';
    }
    return '<span class="badge badge-success">ok</span>';
}
