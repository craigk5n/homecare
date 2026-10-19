<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * SQL implementation of {@see PatientLinkRepositoryInterface}.
 */
final class PatientLinkRepository implements PatientLinkRepositoryInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function patientIdForSchedule(int $scheduleId): ?int
    {
        return $this->single(
            'SELECT patient_id FROM hc_medicine_schedules WHERE id = ?',
            $scheduleId,
        );
    }

    public function patientIdForIntake(int $intakeId): ?int
    {
        return $this->single(
            'SELECT ms.patient_id
               FROM hc_medicine_intake mi
               JOIN hc_medicine_schedules ms ON ms.id = mi.schedule_id
              WHERE mi.id = ?',
            $intakeId,
        );
    }

    public function patientIdForNote(int $noteId): ?int
    {
        return $this->single(
            'SELECT patient_id FROM hc_caregiver_notes WHERE id = ?',
            $noteId,
        );
    }

    public function patientIdForAttachment(int $attachmentId): ?int
    {
        $rows = $this->db->query(
            'SELECT owner_type, owner_id FROM hc_attachments WHERE id = ?',
            [$attachmentId],
        );
        if ($rows === []) {
            return null;
        }

        return $this->patientIdForOwner((string) $rows[0]['owner_type'], (int) $rows[0]['owner_id']);
    }

    public function patientIdForOwner(string $ownerType, int $ownerId): ?int
    {
        return match ($ownerType) {
            'patient' => $this->single('SELECT id FROM hc_patients WHERE id = ?', $ownerId),
            'schedule' => $this->patientIdForSchedule($ownerId),
            'note' => $this->patientIdForNote($ownerId),
            default => null,
        };
    }

    public function patientIdsForMedicine(int $medicineId): array
    {
        $rows = $this->db->query(
            'SELECT DISTINCT patient_id FROM hc_medicine_schedules WHERE medicine_id = ? ORDER BY patient_id ASC',
            [$medicineId],
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['patient_id'];
        }

        return $ids;
    }

    public function patientIdsByMedicine(): array
    {
        $rows = $this->db->query(
            'SELECT DISTINCT medicine_id, patient_id FROM hc_medicine_schedules ORDER BY medicine_id ASC, patient_id ASC',
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['medicine_id']][] = (int) $row['patient_id'];
        }

        return $map;
    }

    private function single(string $sql, int $id): ?int
    {
        $rows = $this->db->query($sql, [$id]);
        if ($rows === []) {
            return null;
        }
        $value = reset($rows[0]);

        return $value === null || $value === false ? null : (int) $value;
    }
}
