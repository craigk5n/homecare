<?php

declare(strict_types=1);

require_once 'includes/init.php';
require_role('caregiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$action = getPostValue('action');
$schedule_id = (int) getPostValue('schedule_id');
$patient_id = (int) getPostValue('patient_id');

if ($schedule_id <= 0 || $patient_id <= 0) {
    header('Location: index.php');
    exit;
}

$db = new \HomeCare\Database\DbiAdapter();
$stepRepo = new \HomeCare\Repository\StepRepository($db);

$returnUrl = 'manage_steps.php?schedule_id=' . urlencode((string) $schedule_id)
    . '&patient_id=' . urlencode((string) $patient_id);

if ($action === 'add') {
    $start_date = getPostValue('start_date');
    $unit_per_dose = getPostValue('unit_per_dose');
    $note = getPostValue('note');

    if (empty($start_date) || empty($unit_per_dose)) {
        echo '<p>Start date and units/dose are required.</p>';
        exit;
    }

    $upd = (float) $unit_per_dose;
    if ($upd <= 0) {
        echo '<p>Units/dose must be positive.</p>';
        exit;
    }

    if ($stepRepo->hasOverlap($schedule_id, $start_date)) {
        echo '<p>A step already exists for that date. Remove it first or pick a different date.</p>';
        exit;
    }

    $stepId = $stepRepo->create($schedule_id, $start_date, $upd, empty($note) ? null : $note);

    audit_log('step.added', 'schedule', $schedule_id, [
        'step_id' => $stepId,
        'start_date' => $start_date,
        'unit_per_dose' => $upd,
        'note' => $note,
    ]);

    do_redirect($returnUrl);
} elseif ($action === 'delete') {
    $step_id = (int) getPostValue('step_id');
    if ($step_id <= 0) {
        header('Location: index.php');
        exit;
    }

    $stepRepo->delete($step_id);

    audit_log('step.removed', 'schedule', $schedule_id, [
        'step_id' => $step_id,
    ]);

    do_redirect($returnUrl);
} else {
    header('Location: index.php');
    exit;
}
