<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HomeCare\Api\ApiResponse;
use HomeCare\Api\IntakesApi;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\ScheduleRepository;

$user = api_authenticate_or_exit();

$db = new DbiAdapter();
$api = new IntakesApi(new ScheduleRepository($db), new IntakeRepository($db));

$method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
    ? strtoupper($_SERVER['REQUEST_METHOD'])
    : 'GET';

if ($method === 'GET') {
    api_send($api->handle($_GET));
}

if ($method === 'POST') {
    $role = isset($user['role']) && is_string($user['role']) ? $user['role'] : 'viewer';
    $resp = $api->record(api_parse_json_body(), $role);

    // Audit trail: parity with the web handlers (HC-013). The audit
    // helper reads $GLOBALS['login']; API auth populated $user, so
    // seed it here before logging.
    if ($resp->httpStatus === 201 && is_array($resp->data) && isset($resp->data['id'])) {
        $GLOBALS['login'] = $user['login'] ?? '';
        require_once __DIR__ . '/../../includes/homecare.php';
        $intakeId = $resp->data['id'];
        audit_log(
            'intake.recorded',
            'intake',
            is_int($intakeId) ? $intakeId : null,
            [
                'source' => 'api',
                'schedule_id' => $resp->data['schedule_id'] ?? null,
                'taken_time' => $resp->data['taken_time'] ?? null,
            ]
        );
    }

    api_send($resp);
}

header('Allow: GET, POST');
api_send(ApiResponse::error('method not allowed', 405));
