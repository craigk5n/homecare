<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * @phpstan-import-type InteractionResult from InteractionService
 */
interface InteractionServiceInterface
{
    /**
     * @return list<InteractionResult>
     */
    public function checkForPatient(int $patientId, int $newMedicineId): array;

    /**
     * @return list<InteractionResult>
     */
    public function checkAllForPatient(int $patientId): array;
}
