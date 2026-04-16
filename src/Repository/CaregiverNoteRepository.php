<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Read/write access to hc_caregiver_notes.
 *
 * `note_time` is the caller-supplied moment the event occurred; `created_at`
 * is when the row landed in the DB and is immutable after insert.
 * `getForPatient` returns newest-first by `note_time` so the journal reads
 * chronologically from top to bottom.
 *
 * @phpstan-type CaregiverNote array{
 *     id:int,
 *     patient_id:int,
 *     note:string,
 *     note_time:?string,
 *     created_at:?string
 * }
 */
final class CaregiverNoteRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function create(int $patientId, string $note, string $noteTime): int
    {
        $this->db->execute(
            'INSERT INTO hc_caregiver_notes (patient_id, note, note_time) VALUES (?, ?, ?)',
            [$patientId, $note, $noteTime]
        );

        return $this->db->lastInsertId();
    }

    public function update(int $id, string $note, string $noteTime): bool
    {
        return $this->db->execute(
            'UPDATE hc_caregiver_notes SET note = ?, note_time = ? WHERE id = ?',
            [$note, $noteTime, $id]
        );
    }

    public function delete(int $id): bool
    {
        return $this->db->execute(
            'DELETE FROM hc_caregiver_notes WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return CaregiverNote|null
     */
    public function getById(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT id, patient_id, note, note_time, created_at
             FROM hc_caregiver_notes WHERE id = ?',
            [$id]
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * Notes for one patient, newest-first by note_time, with a tiebreaker on
     * id so two notes stamped the same second still return in insert order.
     *
     * @return list<CaregiverNote>
     */
    public function getForPatient(int $patientId, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->db->query(
            'SELECT id, patient_id, note, note_time, created_at
             FROM hc_caregiver_notes
             WHERE patient_id = ?
             ORDER BY note_time DESC, id DESC
             LIMIT ? OFFSET ?',
            [$patientId, $limit, $offset]
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @param array<string,scalar|null> $row
     *
     * @return CaregiverNote
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'note' => (string) $row['note'],
            'note_time' => $row['note_time'] === null ? null : (string) $row['note_time'],
            'created_at' => $row['created_at'] === null ? null : (string) $row['created_at'],
        ];
    }
}
