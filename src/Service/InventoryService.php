<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Domain\ScheduleCalculator;
use HomeCare\Repository\InventoryRepositoryInterface;
use HomeCare\Repository\ScheduleRepositoryInterface;

/**
 * Stock-math service for the medication schedule.
 *
 * Replaces the procedural `dosesRemaining()` in `includes/homecare.php` with
 * a composable, testable layer: data access lives in the repositories, pure
 * math lives in {@see ScheduleCalculator}, and this service only orchestrates.
 *
 * The shape returned by {@see calculateRemaining()} is deliberately identical
 * to the legacy function's return so existing pages keep rendering without
 * template changes. When we retire the legacy pages, we can swap this for a
 * typed value object.
 *
 * @phpstan-type RemainingReport array{
 *     remainingDays:int,
 *     remainingDoses:float,
 *     lastInventory:?float,
 *     quantityTakenSince:float,
 *     unitPerDose:float,
 *     medicineName:string
 * }
 */
final class InventoryService
{
    public function __construct(
        private readonly InventoryRepositoryInterface $inventory,
        private readonly ScheduleRepositoryInterface $schedules,
    ) {
    }

    /**
     * Project how much medication is left and how many days of supply remain.
     *
     * When `$assumePastIntake` is true the caller is viewing the schedule as
     * though all doses between `$startDate` and the earlier of "yesterday"
     * and the schedule's `end_date` have been taken -- useful for caregivers
     * catching up on recording after a gap. `$frequency` overrides the
     * schedule's frequency for the assumed-consumption math, same as the
     * original helper.
     *
     * @return RemainingReport
     */
    public function calculateRemaining(
        int $medicineId,
        int $scheduleId,
        bool $assumePastIntake = false,
        ?string $startDate = null,
        ?string $frequency = null
    ): array {
        $report = [
            'remainingDays' => 0,
            'remainingDoses' => 0.0,
            'lastInventory' => null,
            'quantityTakenSince' => 0.0,
            'unitPerDose' => 0.0,
            'medicineName' => (string) ($this->inventory->getMedicineName($medicineId) ?? ''),
        ];

        $schedule = $this->schedules->getScheduleById($scheduleId);
        if ($schedule !== null) {
            $report['unitPerDose'] = $schedule['unit_per_dose'];
        }

        $stock = $this->inventory->getLatestStock($medicineId);
        if ($stock === null) {
            return $report;
        }

        $lastStock = $stock['current_stock'];
        $report['lastInventory'] = $lastStock;

        $consumed = $this->inventory->getTotalConsumedSince($medicineId, $stock['recorded_at']);
        $report['quantityTakenSince'] = $consumed;

        $assumed = 0.0;
        if ($assumePastIntake && $startDate !== null && $frequency !== null && $report['unitPerDose'] > 0.0) {
            $assumed = self::assumedConsumption(
                $startDate,
                $schedule['end_date'] ?? null,
                $frequency,
                $report['unitPerDose']
            );
        }

        $remainingAmount = $lastStock - ($consumed + $assumed);

        if ($report['unitPerDose'] <= 0.0) {
            // No dosing info -- we can report stock but not project doses/days.
            return $report;
        }

        $remainingDoses = max(0.0, $remainingAmount / $report['unitPerDose']);
        $report['remainingDoses'] = $remainingDoses;

        $freqForDays = $frequency ?? ($schedule['frequency'] ?? null);
        if ($freqForDays !== null && $freqForDays !== '') {
            $secondsPerDose = ScheduleCalculator::frequencyToSeconds($freqForDays);
            $dosesPerDay = 86400 / $secondsPerDose;
            $report['remainingDays'] = max(0, (int) floor($remainingDoses / $dosesPerDay));
        }

        return $report;
    }

    /**
     * Units projected to be consumed between $startDate and the earlier of
     * "yesterday" and $endDate. Extracted as a pure helper so the unit tests
     * can exercise the edge cases in isolation.
     */
    private static function assumedConsumption(
        string $startDate,
        ?string $endDate,
        string $frequency,
        float $unitPerDose
    ): float {
        $yesterday = (new \DateTimeImmutable())->modify('-1 day');
        $start = new \DateTimeImmutable($startDate);
        $end = $yesterday;

        if ($endDate !== null) {
            $scheduleEnd = new \DateTimeImmutable($endDate);
            if ($scheduleEnd < $yesterday) {
                $end = $scheduleEnd;
            }
        }

        if ($start > $end) {
            return 0.0;
        }

        $days = (int) $start->diff($end)->days + 1;
        $secondsPerDose = ScheduleCalculator::frequencyToSeconds($frequency);
        $dosesPerDay = 86400 / $secondsPerDose;

        return $dosesPerDay * $days * $unitPerDose;
    }
}
