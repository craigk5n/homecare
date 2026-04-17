<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Service\InteractionServiceInterface;

/**
 * GET /api/v1/interactions.php?patient_id=N&medicine_id=M
 *
 * Returns interaction warnings between medicine M and the patient's
 * active schedules.
 */
final class InteractionsApi
{
    public function __construct(
        private readonly InteractionServiceInterface $service,
    ) {
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

        $results = $this->service->checkForPatient($patientId, $medicineId);

        return ApiResponse::ok($results);
    }
}
