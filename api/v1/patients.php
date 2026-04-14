<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HomeCare\Api\PatientsApi;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\PatientRepository;

api_authenticate_or_exit();

$api = new PatientsApi(new PatientRepository(new DbiAdapter()));
api_send($api->handle($_GET));
