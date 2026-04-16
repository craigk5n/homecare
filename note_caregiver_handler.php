<?php
require_once 'includes/init.php';
require_role('caregiver');

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\CaregiverNoteRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list_medications.php');
    exit();
}

$action = getPostValue('action') ?: 'save';
$patient_id = getPostValue('patient_id');
$note_id_raw = getPostValue('id');
$note_id = !empty($note_id_raw) ? (int) $note_id_raw : null;

if (empty($patient_id)) {
    die_miserable_death('Missing required patient_id.');
}
$patient_id = (int) $patient_id;

$repo = new CaregiverNoteRepository(new DbiAdapter());

if ($action === 'delete') {
    if ($note_id === null) {
        die_miserable_death('Cannot delete without a note id.');
    }
    $existing = $repo->getById($note_id);
    if ($existing === null) {
        die_miserable_death('Note not found.');
    }

    $repo->delete($note_id);
    audit_log('note.deleted', 'caregiver_note', $note_id, [
        'patient_id' => $existing['patient_id'],
        'note_time' => $existing['note_time'],
        'note_len' => strlen($existing['note']),
    ]);

    do_redirect('list_schedule.php?patient_id=' . urlencode((string) $patient_id));
    exit();
}

// Save path (create or update).
$noteText = (string) getPostValue('note');
$noteTimeRaw = (string) getPostValue('note_time');

if ($noteText === '' || trim($noteText) === '') {
    die_miserable_death('Note text is required.');
}
if (strlen($noteText) > 4000) {
    die_miserable_death('Note exceeds 4,000 characters.');
}
if ($noteTimeRaw === '') {
    die_miserable_death('Note time is required.');
}

// HTML datetime-local submits "YYYY-MM-DDTHH:MM" (no seconds). Normalise.
$noteTime = str_replace('T', ' ', $noteTimeRaw);
if (strlen($noteTime) === 16) {
    $noteTime .= ':00';
}
if (\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $noteTime) === false) {
    die_miserable_death('Invalid note time: ' . htmlspecialchars($noteTimeRaw));
}

if ($note_id !== null) {
    $existing = $repo->getById($note_id);
    if ($existing === null) {
        die_miserable_death('Note not found.');
    }
    $repo->update($note_id, $noteText, $noteTime);
    audit_log('note.updated', 'caregiver_note', $note_id, [
        'patient_id' => $patient_id,
        'note_time' => $noteTime,
        'note_len' => strlen($noteText),
    ]);
} else {
    $newId = $repo->create($patient_id, $noteText, $noteTime);
    audit_log('note.created', 'caregiver_note', $newId, [
        'patient_id' => $patient_id,
        'note_time' => $noteTime,
        'note_len' => strlen($noteText),
    ]);
}

do_redirect('list_schedule.php?patient_id=' . urlencode((string) $patient_id));
