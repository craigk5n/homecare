<?php

declare(strict_types=1);

require_once 'includes/init.php';
require_role('caregiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$schedule_id = (int) getPostValue('schedule_id');
$patient_id = (int) getPostValue('patient_id');
$start_date = getPostValue('start_date');
$end_date = getPostValue('end_date');
$reason = getPostValue('reason');

if ($schedule_id <= 0 || $patient_id <= 0 || empty($start_date)) {
    echo '<p>Missing required fields.</p>';
    exit;
}

if (empty($end_date)) {
    $end_date = null;
}
if (empty($reason)) {
    $reason = null;
}

if ($end_date !== null && $end_date < $start_date) {
    echo '<p>End date cannot be before start date.</p>';
    exit;
}

$db = new \HomeCare\Database\DbiAdapter();
$repo = new \HomeCare\Repository\PauseRepository($db);

$repo->create($schedule_id, $start_date, $end_date, $reason);

audit_log('schedule.paused', 'schedule', $schedule_id, [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'reason' => $reason,
]);

do_redirect('list_schedule.php?patient_id=' . urlencode((string) $patient_id));
