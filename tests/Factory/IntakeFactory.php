<?php

declare(strict_types=1);

namespace HomeCare\Tests\Factory;

use HomeCare\Database\DatabaseInterface;

/**
 * Test factory for hc_medicine_intake (recorded doses).
 *
 * @phpstan-type IntakeOverrides array{
 *     schedule_id:int,
 *     taken_time?:string,
 *     note?:?string
 * }
 * @phpstan-type IntakeRecord array{
 *     id:int,
 *     schedule_id:int,
 *     taken_time:string,
 *     note:?string
 * }
 */
final class IntakeFactory
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @param IntakeOverrides $overrides
     *
     * @return IntakeRecord
     */
    public function create(array $overrides): array
    {
        // Default to "now" in MySQL's canonical DATETIME format so the value
        // round-trips across drivers without timezone surprises.
        $takenTime = $overrides['taken_time'] ?? date('Y-m-d H:i:s');
        $note = $overrides['note'] ?? null;

        $this->db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES (?, ?, ?)',
            [$overrides['schedule_id'], $takenTime, $note]
        );

        return [
            'id' => $this->db->lastInsertId(),
            'schedule_id' => $overrides['schedule_id'],
            'taken_time' => $takenTime,
            'note' => $note,
        ];
    }
}
