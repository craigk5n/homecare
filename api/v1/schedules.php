<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HomeCare\Api\SchedulesApi;
use HomeCare\Database\DbiAdapter;

api_authenticate_or_exit();

$api = new SchedulesApi(new DbiAdapter());
api_send($api->handle($_GET));
