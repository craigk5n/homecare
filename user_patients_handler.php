<?php
/**
 * Save one user's HC-237 patient assignments (POST from user_patients.php).
 * CSRF is enforced for every *_handler.php by preventHacking().
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_role('admin');

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\UserPatientRepository;
use HomeCare\Service\PatientAssignmentService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: user_patients.php');
    exit;
}

$login = (string) (getPostValue('login') ?? '');
$rawIds = getPostValue('patient_ids', []);
$patientIds = [];
foreach (is_array($rawIds) ? $rawIds : [] as $raw) {
    $id = (int) $raw;
    if ($id > 0) {
        $patientIds[] = $id;
    }
}

$db = new DbiAdapter();
$service = new PatientAssignmentService($db, new UserPatientRepository($db));

try {
    $change = $service->assign($login, $patientIds);
} catch (\InvalidArgumentException $e) {
    die_miserable_death(htmlspecialchars($e->getMessage()));
}

if ($change['added'] !== [] || $change['removed'] !== []) {
    audit_log('user.patients_assigned', 'user', null, [
        'login' => $login,
        'patient_ids' => $change['after'],
        'added' => $change['added'],
        'removed' => $change['removed'],
    ]);
}

do_redirect('user_patients.php?login=' . urlencode($login) . '&saved=1');
