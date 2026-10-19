<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Auth\PatientScope;
use HomeCare\Repository\PatientRepository;

/**
 * GET /api/v1/patients.php
 *
 * Returns the full list of patients. Disabled patients are excluded by
 * default; `?include_disabled=1` asks for everyone. Limited to the API
 * user's patients (HC-237).
 */
final class PatientsApi
{
    private readonly PatientScope $scope;

    public function __construct(
        private readonly PatientRepository $patients,
        ?PatientScope $scope = null,
    ) {
        $this->scope = $scope ?? PatientScope::unrestricted();
    }

    /**
     * @param array<string,mixed> $query Typically `$_GET`.
     */
    public function handle(array $query): ApiResponse
    {
        $includeDisabled = self::flag($query, 'include_disabled');

        return ApiResponse::ok($this->scope->filter($this->patients->getAll($includeDisabled)));
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
