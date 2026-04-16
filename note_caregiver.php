<?php
require_once 'includes/init.php';
require_role('caregiver');

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\CaregiverNoteRepository;

$repo = new CaregiverNoteRepository(new DbiAdapter());

$note_id = getIntValue('id');
$existing = null;
if (!empty($note_id)) {
    $existing = $repo->getById((int) $note_id);
    if ($existing === null) {
        die_miserable_death('Note not found.');
    }
}

$patient_id = $existing['patient_id'] ?? getIntValue('patient_id');
if (empty($patient_id)) {
    die_miserable_death('Missing required patient_id.');
}
$patient = getPatient((int) $patient_id);

$noteText = $existing['note'] ?? '';
$noteTime = $existing['note_time'] ?? date('Y-m-d H:i:s');

// <input type="datetime-local"> uses a "T" separator and no seconds.
$noteTimeForInput = substr(str_replace(' ', 'T', (string) $noteTime), 0, 16);

$isEdit = $existing !== null;
$pageTitle = $isEdit ? 'Edit Note' : 'Add Note';

print_header();

echo "<div class='container mt-3'>\n";
echo "<h2>" . htmlspecialchars($pageTitle) . " &mdash; " . htmlspecialchars($patient['name']) . "</h2>\n";

echo "<form action='note_caregiver_handler.php' method='POST'>\n";
print_form_key();
echo "<input type='hidden' name='patient_id' value='" . htmlspecialchars((string) $patient_id) . "'>\n";
if ($isEdit) {
    echo "<input type='hidden' name='id' value='" . htmlspecialchars((string) $existing['id']) . "'>\n";
}

echo "<div class='form-group mb-3'>\n";
echo "<label for='note_time'>When:</label>\n";
echo "<input type='datetime-local' name='note_time' id='note_time' class='form-control'"
    . " required value='" . htmlspecialchars($noteTimeForInput) . "'>\n";
echo "<small class='form-text text-muted'>When the event occurred (not when you're recording it).</small>\n";
echo "</div>\n";

echo "<div class='form-group mb-3'>\n";
echo "<label for='note'>Note:</label>\n";
echo "<textarea name='note' id='note' class='form-control' rows='6' maxlength='4000' required>"
    . htmlspecialchars($noteText) . "</textarea>\n";
echo "<small class='form-text text-muted'>Up to 4,000 characters.</small>\n";
echo "</div>\n";

echo "<div class='mt-4 d-flex gap-2'>\n";
echo "<a href='list_schedule.php?patient_id=" . htmlspecialchars((string) $patient_id)
    . "' class='btn btn-secondary mr-2'>Cancel</a>\n";
echo "<button type='submit' name='action' value='save' class='btn btn-primary'>Save Note</button>\n";
if ($isEdit) {
    echo " <button type='submit' name='action' value='delete' class='btn btn-danger ml-2'"
        . " data-confirm='Delete this note? This cannot be undone.'>Delete</button>\n";
}
echo "</div>\n";

echo "</form>\n";
echo "</div>\n";

$nonce = htmlspecialchars($GLOBALS['NONCE'] ?? '');
echo "<script nonce='{$nonce}'>\n";
echo "document.addEventListener('click', function(e) {\n";
echo "  var el = e.target.closest('[data-confirm]');\n";
echo "  if (!el) return;\n";
echo "  if (!confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); }\n";
echo "});\n";
echo "</script>\n";

echo print_trailer();
