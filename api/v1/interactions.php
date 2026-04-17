<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HomeCare\Api\InteractionsApi;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\InteractionRepository;
use HomeCare\Service\InteractionService;

api_authenticate_or_exit();

$db = new DbiAdapter();
$service = new InteractionService(new InteractionRepository($db), $db);
$api = new InteractionsApi($service);
api_send($api->handle($_GET));
