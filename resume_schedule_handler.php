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

if ($schedule_id <= 0 || $patient_id <= 0) {
    header('Location: index.php');
    exit;
}

$today = date('Y-m-d');

$db = new \HomeCare\Database\DbiAdapter();
$repo = new \HomeCare\Repository\PauseRepository($db);

$closed = $repo->resumeSchedule($schedule_id, $today);

if ($closed > 0) {
    audit_log('schedule.resumed', 'schedule', $schedule_id, [
        'resume_date' => $today,
        'pauses_closed' => $closed,
    ]);
}

do_redirect('list_schedule.php?patient_id=' . urlencode((string) $patient_id));
