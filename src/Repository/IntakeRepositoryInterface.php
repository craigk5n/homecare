<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Intake read/write contract consumed by services and handlers.
 *
 * @phpstan-import-type Intake from IntakeRepository
 */
interface IntakeRepositoryInterface
{
    /**
     * @return list<Intake>
     */
    public function getIntakesSince(int $scheduleId, string $since): array;

    public function countIntakesSince(int $scheduleId, string $since): int;

    /**
     * Count intakes whose taken_time falls within [$from, $to] (inclusive
     * on both bounds). Used by adherence calculations that need a bounded
     * window rather than the half-open "since" semantics.
     */
    public function countIntakesBetween(int $scheduleId, string $from, string $to): int;

    public function recordIntake(
        int $scheduleId,
        ?string $takenTime = null,
        ?string $note = null
    ): int;

    public function reassignIntakes(int $fromScheduleId, int $toScheduleId, string $since): int;
}
