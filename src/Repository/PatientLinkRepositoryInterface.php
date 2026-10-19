<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Resolve indirect ids (schedule, intake, note, attachment, medicine)
 * to the patient(s) they belong to, so per-patient access checks
 * (HC-237) can guard pages that never see a patient_id directly.
 *
 * Each single-patient lookup returns null when the row does not exist.
 */
interface PatientLinkRepositoryInterface
{
    public function patientIdForSchedule(int $scheduleId): ?int;

    public function patientIdForIntake(int $intakeId): ?int;

    public function patientIdForNote(int $noteId): ?int;

    public function patientIdForAttachment(int $attachmentId): ?int;

    /**
     * Patient that owns an attachment target (owner_type patient /
     * schedule / note). Null for unknown types or missing rows.
     */
    public function patientIdForOwner(string $ownerType, int $ownerId): ?int;

    /**
     * Distinct patients with any schedule (past or present) for the
     * medicine. Empty when the medicine is not linked to anyone.
     *
     * @return list<int>
     */
    public function patientIdsForMedicine(int $medicineId): array;

    /**
     * Same as {@see patientIdsForMedicine} for every linked medicine at
     * once; medicines with no schedules are absent from the map.
     *
     * @return array<int,list<int>>
     */
    public function patientIdsByMedicine(): array;
}
