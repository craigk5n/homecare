<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Repository\PatientRepository;

/**
 * GET /api/v1/patients.php
 *
 * Returns the full list of patients. Disabled patients are excluded by
 * default; `?include_disabled=1` asks for everyone.
 */
final class PatientsApi
{
    public function __construct(private readonly PatientRepository $patients) {}

    /**
     * @param array<string,mixed> $query Typically `$_GET`.
     */
    public function handle(array $query): ApiResponse
    {
        $includeDisabled = self::flag($query, 'include_disabled');

        return ApiResponse::ok($this->patients->getAll($includeDisabled));
    }

    /**
     * @param array<string,mixed> $query
     */
    private static function flag(array $query, string $key): bool
    {
        if (!isset($query[$key])) {
            return false;
        }
        $v = $query[$key];

        return $v === '1' || $v === 1 || $v === true || $v === 'true';
    }
}
