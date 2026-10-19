<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Read/write contract for hc_user_patients, the optional per-user
 * patient allow-list (HC-237).
 */
interface UserPatientRepositoryInterface
{
    /**
     * Patient ids assigned to $login, ascending. Empty when the user has
     * no assignments (which {@see \HomeCare\Auth\PatientAccess} treats as
     * "every patient" for non-admins).
     *
     * @return list<int>
     */
    public function getPatientIds(string $login): array;

    /**
     * Replace the user's assignments with exactly $patientIds. An empty
     * list removes every assignment.
     *
     * @param list<int> $patientIds
     */
    public function replacePatientIds(string $login, array $patientIds): void;
}
