<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Read/write access to hc_schedule_pauses.
 *
 * @phpstan-type Pause array{
 *     id:int,
 *     schedule_id:int,
 *     start_date:string,
 *     end_date:?string,
 *     reason:?string,
 *     created_at:string
 * }
 */
final class PauseRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function create(int $scheduleId, string $startDate, ?string $endDate, ?string $reason): int
    {
        $this->db->execute(
            'INSERT INTO hc_schedule_pauses (schedule_id, start_date, end_date, reason)
             VALUES (?, ?, ?, ?)',
            [$scheduleId, $startDate, $endDate, $reason]
        );

        return $this->db->lastInsertId();
    }

    public function delete(int $pauseId): bool
    {
        return $this->db->execute(
            'DELETE FROM hc_schedule_pauses WHERE id = ?',
            [$pauseId]
        );
    }

    /**
     * @return list<Pause>
     */
    public function getForSchedule(int $scheduleId): array
    {
        $rows = $this->db->query(
            'SELECT id, schedule_id, start_date, end_date, reason, created_at
             FROM hc_schedule_pauses
             WHERE schedule_id = ?
             ORDER BY start_date DESC',
            [$scheduleId]
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * Is the schedule paused on the given date?
     *
     * A schedule is paused when any pause row covers the date:
     *   start_date <= $date AND (end_date IS NULL OR end_date >= $date)
     */
    public function isPausedOn(int $scheduleId, string $date): bool
    {
        $rows = $this->db->query(
            'SELECT 1 FROM hc_schedule_pauses
             WHERE schedule_id = ?
               AND start_date <= ?
               AND (end_date IS NULL OR end_date >= ?)
             LIMIT 1',
            [$scheduleId, $date, $date]
        );

        return $rows !== [];
    }

    /**
     * Count the number of calendar days within [$startDate, $endDate] that
     * are covered by at least one pause. Used by AdherenceService to
     * subtract paused days from the expected dose count.
     *
     * Walks each pause that overlaps the range, clamps it to the range,
     * and unions the day intervals to avoid double-counting overlapping
     * pauses.
     */
    public function countPausedDaysInRange(int $scheduleId, string $startDate, string $endDate): int
    {
        $rows = $this->db->query(
            'SELECT start_date, end_date FROM hc_schedule_pauses
             WHERE schedule_id = ?
               AND start_date <= ?
               AND (end_date IS NULL OR end_date >= ?)
             ORDER BY start_date ASC',
            [$scheduleId, $endDate, $startDate]
        );

        if ($rows === []) {
            return 0;
        }

        $rangeStartTs = strtotime($startDate);
        $rangeEndTs = strtotime($endDate);
        if ($rangeStartTs === false || $rangeEndTs === false) {
            return 0;
        }

        $intervals = [];
        foreach ($rows as $row) {
            $pStartTs = strtotime((string) $row['start_date']);
            $pEndTs = $row['end_date'] !== null ? strtotime((string) $row['end_date']) : $rangeEndTs;
            if ($pStartTs === false || $pEndTs === false) {
                continue;
            }
            $clampedStart = max($pStartTs, $rangeStartTs);
            $clampedEnd = min($pEndTs, $rangeEndTs);
            if ($clampedStart <= $clampedEnd) {
                $intervals[] = [$clampedStart, $clampedEnd];
            }
        }

        if ($intervals === []) {
            return 0;
        }

        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [$intervals[0]];
        for ($i = 1; $i < count($intervals); $i++) {
            $last = &$merged[count($merged) - 1];
            if ($intervals[$i][0] <= $last[1] + 86400) {
                $last[1] = max($last[1], $intervals[$i][1]);
            } else {
                $merged[] = $intervals[$i];
            }
        }
        unset($last);

        $days = 0;
        foreach ($merged as [$s, $e]) {
            $days += (int) floor(($e - $s) / 86400) + 1;
        }

        return $days;
    }

    /**
     * Resume a schedule by setting end_date on all open-ended pauses
     * that are currently active. Returns the number of pauses closed.
     */
    public function resumeSchedule(int $scheduleId, string $resumeDate): int
    {
        $active = $this->db->query(
            'SELECT id FROM hc_schedule_pauses
             WHERE schedule_id = ?
               AND start_date <= ?
               AND (end_date IS NULL OR end_date >= ?)
             ORDER BY id',
            [$scheduleId, $resumeDate, $resumeDate]
        );

        $count = 0;
        foreach ($active as $row) {
            $this->db->execute(
                'UPDATE hc_schedule_pauses SET end_date = ? WHERE id = ?',
                [$resumeDate, (int) $row['id']]
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, scalar|null> $row
     * @return Pause
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'schedule_id' => (int) $row['schedule_id'],
            'start_date' => (string) $row['start_date'],
            'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
