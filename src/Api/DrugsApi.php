<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Repository\DrugCatalogRepositoryInterface;

/**
 * GET /api/v1/drugs.php?q=... — autocomplete search against hc_drug_catalog.
 *
 * Returns up to 20 matching entries. Minimum query length: 2 characters.
 */
final class DrugsApi
{
    public function __construct(
        private readonly DrugCatalogRepositoryInterface $catalog,
    ) {
    }

    /**
     * @param array<string, string> $params GET parameters
     */
    public function handle(array $params): ApiResponse
    {
        $query = trim($params['q'] ?? '');

        if (mb_strlen($query) < 2) {
            return ApiResponse::ok([]);
        }

        $rawLimit = $params['limit'] ?? '20';
        $limit = min(max((int) $rawLimit, 1), 50);
        $results = $this->catalog->search($query, $limit);

        return ApiResponse::ok($results);
    }
}
