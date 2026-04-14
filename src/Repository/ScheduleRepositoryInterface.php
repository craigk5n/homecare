<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Schedule read/write contract consumed by services and handlers.
 *
 * @phpstan-import-type Schedule from ScheduleRepository
 * @phpstan-import-type ScheduleInput from ScheduleRepository
 */
interface ScheduleRepositoryInterface
{
    /**
     * @return Schedule|null
     */
    public function getScheduleById(int $id): ?array;

    /**
     * @return list<Schedule>
     */
    public function getActiveSchedules(int $patientId, string $today): array;

    public function endSchedule(int $id, string $endDate): bool;

    /**
     * @param ScheduleInput $data
     */
    public function createSchedule(array $data): int;
}
