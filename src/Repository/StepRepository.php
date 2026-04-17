<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Read/write access to hc_schedule_steps (HC-122).
 *
 * @phpstan-type Step array{
 *     id:int,
 *     schedule_id:int,
 *     start_date:string,
 *     unit_per_dose:float,
 *     note:?string,
 *     created_at:string
 * }
 */
final class StepRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function create(int $scheduleId, string $startDate, float $unitPerDose, ?string $note): int
    {
        $this->db->execute(
            'INSERT INTO hc_schedule_steps (schedule_id, start_date, unit_per_dose, note)
             VALUES (?, ?, ?, ?)',
            [$scheduleId, $startDate, $unitPerDose, $note]
        );

        return $this->db->lastInsertId();
    }

    public function delete(int $stepId): bool
    {
        return $this->db->execute(
            'DELETE FROM hc_schedule_steps WHERE id = ?',
            [$stepId]
        );
    }

    /**
     * @return list<Step>
     */
    public function getForSchedule(int $scheduleId): array
    {
        $rows = $this->db->query(
            'SELECT id, schedule_id, start_date, unit_per_dose, note, created_at
             FROM hc_schedule_steps
             WHERE schedule_id = ?
             ORDER BY start_date ASC',
            [$scheduleId]
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * Return the effective step for a given date: the latest step whose
     * start_date <= $date. Returns null when no steps exist (callers
     * fall back to the schedule's own unit_per_dose).
     *
     * @return Step|null
     */
    public function getEffectiveStep(int $scheduleId, string $date): ?array
    {
        $rows = $this->db->query(
            'SELECT id, schedule_id, start_date, unit_per_dose, note, created_at
             FROM hc_schedule_steps
             WHERE schedule_id = ?
               AND start_date <= ?
             ORDER BY start_date DESC
             LIMIT 1',
            [$scheduleId, $date]
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * Check whether a step with the same schedule_id and start_date
     * already exists (excluding a given step id for updates).
     */
    public function hasOverlap(int $scheduleId, string $startDate, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM hc_schedule_steps
                WHERE schedule_id = ? AND start_date = ?';
        $params = [$scheduleId, $startDate];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        $sql .= ' LIMIT 1';

        return $this->db->query($sql, $params) !== [];
    }

    /**
     * @param array<string, scalar|null> $row
     * @return Step
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'schedule_id' => (int) $row['schedule_id'],
            'start_date' => (string) $row['start_date'],
            'unit_per_dose' => (float) $row['unit_per_dose'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
