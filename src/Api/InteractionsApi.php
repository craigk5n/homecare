<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Auth\PatientScope;
use HomeCare\Service\InteractionServiceInterface;

/**
 * GET /api/v1/interactions.php?patient_id=N&medicine_id=M
 *
 * Returns interaction warnings between medicine M and the patient's
 * active schedules.
 */
final class InteractionsApi
{
    private readonly PatientScope $scope;

    public function __construct(
        private readonly InteractionServiceInterface $service,
        ?PatientScope $scope = null,
    ) {
        $this->scope = $scope ?? PatientScope::unrestricted();
    }

    /**
     * @param array<string, string> $params GET parameters
     */
    public function handle(array $params): ApiResponse
    {
        $patientId = (int) ($params['patient_id'] ?? 0);
        $medicineId = (int) ($params['medicine_id'] ?? 0);

        if ($patientId < 1 || $medicineId < 1) {
            return ApiResponse::error('patient_id and medicine_id are required', 400);
        }
        // HC-237: same answer as a missing patient.
        if (!$this->scope->allows($patientId)) {
            return ApiResponse::error('patient not found', 404);
        }

        $results = $this->service->checkForPatient($patientId, $medicineId);

        return ApiResponse::ok($results);
    }
}
