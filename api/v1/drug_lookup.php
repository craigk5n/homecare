<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use HomeCare\Api\DrugLookupApi;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\DrugCatalogRepository;

api_authenticate_or_exit();

$api = new DrugLookupApi(new DrugCatalogRepository(new DbiAdapter()));
api_send($api->handle($_GET));
