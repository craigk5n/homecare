<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HomeCare\Api\InventoryApi;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\InventoryRepository;

api_authenticate_or_exit();

$db = new DbiAdapter();
$api = new InventoryApi($db, new InventoryRepository($db));
api_send($api->handle($_GET));
