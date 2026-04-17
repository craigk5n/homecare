<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Repository\DrugCatalogRepositoryInterface;

/**
 * GET /api/v1/drug_lookup.php?ndc=... — barcode/NDC lookup against hc_drug_catalog.
 *
 * Returns matching catalog entries for the given NDC code. The NDC is
 * stripped to digits before lookup (hyphens and spaces are tolerated).
 */
final class DrugLookupApi
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
        $ndc = trim($params['ndc'] ?? '');

        if ($ndc === '') {
            return ApiResponse::error('ndc parameter is required', 400);
        }

        $digitsOnly = preg_replace('/[^0-9]/', '', $ndc) ?? '';
        if (strlen($digitsOnly) < 10 || strlen($digitsOnly) > 13) {
            return ApiResponse::error('NDC must be 10-13 digits', 400);
        }

        $results = $this->catalog->findByNdc($ndc);

        if ($results === []) {
            return ApiResponse::error('No matching drug found for NDC ' . $digitsOnly, 404);
        }

        return ApiResponse::ok($results);
    }
}
