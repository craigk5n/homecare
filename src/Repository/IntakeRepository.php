<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Access to hc_medicine_intake (recorded doses).
 *
 * `reassignIntakes` counts the affected rows before issuing the UPDATE
 * rather than relying on a driver-specific row-count method. That keeps
 * the {@see DatabaseInterface} contract narrow and works identically on
 * mysqli (production) and SQLite PDO (tests).
 *
 * @phpstan-type Intake array{
 *     id:int,
 *     schedule_id:int,
 *     taken_time:?string,
 *     note:?string,
 *     created_at:?string
 * }
 */
final class IntakeRepository implements IntakeRepositoryInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @return list<Intake>
     */
    public function getIntakesSince(int $scheduleId, string $since): array
    {
        $rows = $this->db->query(
            'SELECT id, schedule_id, taken_time, note, created_at
             FROM hc_medicine_intake
             WHERE schedule_id = ? AND taken_time > ?
             ORDER BY taken_time ASC',
            [$scheduleId, $since],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function countIntakesSince(int $scheduleId, string $since): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS n FROM hc_medicine_intake WHERE schedule_id = ? AND taken_time > ?',
            [$scheduleId, $since],
        );

        return $rows === [] ? 0 : (int) $rows[0]['n'];
    }

    public function countIntakesBetween(int $scheduleId, string $from, string $to): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS n FROM hc_medicine_intake
             WHERE schedule_id = ? AND taken_time >= ? AND taken_time <= ?',
            [$scheduleId, $from, $to],
        );

        return $rows === [] ? 0 : (int) $rows[0]['n'];
    }

    public function recordIntake(
        int $scheduleId,
        ?string $takenTime = null,
        ?string $note = null,
    ): int {
        if ($takenTime === null) {
            // Use the DB default (CURRENT_TIMESTAMP). Explicit NULL would violate
            // the default; omitting the column in the insert triggers the default.
            $this->db->execute(
                'INSERT INTO hc_medicine_intake (schedule_id, note) VALUES (?, ?)',
                [$scheduleId, $note],
            );
        } else {
            $this->db->execute(
                'INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES (?, ?, ?)',
                [$scheduleId, $takenTime, $note],
            );
        }

        return $this->db->lastInsertId();
    }

    /**
     * Move intakes recorded after $since from one schedule to another.
     *
     * Returns the number of rows that changed schedule_id.
     */
    public function reassignIntakes(int $fromScheduleId, int $toScheduleId, string $since): int
    {
        $count = $this->countIntakesSince($fromScheduleId, $since);
        if ($count === 0) {
            return 0;
        }

        $this->db->execute(
            'UPDATE hc_medicine_intake SET schedule_id = ?
             WHERE schedule_id = ? AND taken_time > ?',
            [$toScheduleId, $fromScheduleId, $since],
        );

        return $count;
    }

    /**
     * @param array<string,scalar|null> $row
     *
     * @return Intake
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'schedule_id' => (int) $row['schedule_id'],
            'taken_time' => $row['taken_time'] === null ? null : (string) $row['taken_time'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'created_at' => $row['created_at'] === null ? null : (string) $row['created_at'],
        ];
    }
}
