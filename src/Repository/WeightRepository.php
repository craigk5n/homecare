<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Repository for hc_weight_history — patient weight tracking.
 */
final class WeightRepository
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * Record a new weight entry.
     */
    public function insert(int $patientId, float $weightKg, string $recordedAt, ?string $note = null): int
    {
        $this->db->execute(
            'INSERT INTO hc_weight_history (patient_id, weight_kg, recorded_at, note) VALUES (?, ?, ?, ?)',
            [$patientId, $weightKg, $recordedAt, $note],
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get weight history for a patient, newest first.
     *
     * @return list<array{id: int, weight_kg: float, recorded_at: string, note: string|null, created_at: string}>
     */
    public function getHistory(int $patientId, int $limit = 100): array
    {
        $rows = $this->db->query(
            'SELECT id, weight_kg, recorded_at, note, created_at
               FROM hc_weight_history
              WHERE patient_id = ?
              ORDER BY recorded_at DESC, id DESC
              LIMIT ' . $limit,
            [$patientId],
        );

        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'weight_kg' => (float) $r['weight_kg'],
            'recorded_at' => (string) $r['recorded_at'],
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * Get the most recent weight for a patient, or null if none recorded.
     *
     * @return array{weight_kg: float, recorded_at: string}|null
     */
    public function getLatest(int $patientId): ?array
    {
        $rows = $this->db->query(
            'SELECT weight_kg, recorded_at FROM hc_weight_history
              WHERE patient_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1',
            [$patientId],
        );

        if ($rows === []) {
            return null;
        }

        return [
            'weight_kg' => (float) $rows[0]['weight_kg'],
            'recorded_at' => (string) $rows[0]['recorded_at'],
        ];
    }

    /**
     * Delete a weight entry by ID.
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM hc_weight_history WHERE id = ?', [$id]);
    }
}
