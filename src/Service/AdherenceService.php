<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Domain\ScheduleCalculator;
use HomeCare\Repository\IntakeRepositoryInterface;
use HomeCare\Repository\PauseRepository;
use HomeCare\Repository\ScheduleRepositoryInterface;

/**
 * Medication adherence for a single schedule over a date range.
 *
 * Adherence = actual doses recorded / doses expected in the effective
 * overlap of [query range] and [schedule lifetime]. The "effective"
 * clamp is what makes schedules that started mid-period honest: a
 * schedule begun on day 5 of a 10-day window only has 6 days to
 * account for, not 10.
 *
 * Doses-per-day comes straight from frequency via
 * {@see ScheduleCalculator::frequencyToSeconds()}. We use `round()` on
 * the expected total so a partial day's worth (e.g. a half-day at 2d
 * frequency) is reflected correctly rather than silently floored to zero.
 *
 * Return shape also reports `coverage_days` (days of overlap between the
 * query window and the schedule's lifetime) and `window_days` (length of
 * the query window itself). Callers use these to tell "N/A — schedule
 * wasn't active in this window" (coverage_days == 0) apart from the very
 * different "was active but nothing was recorded" (coverage_days > 0,
 * actual == 0, percentage == 0.0).
 *
 * @phpstan-type AdherenceReport array{
 *     expected:int,
 *     actual:int,
 *     percentage:float,
 *     coverage_days:int,
 *     window_days:int
 * }
 */
final class AdherenceService
{
    public function __construct(
        private readonly ScheduleRepositoryInterface $schedules,
        private readonly IntakeRepositoryInterface $intakes,
        private readonly ?PauseRepository $pauses = null,
    ) {}

    /**
     * @return AdherenceReport
     */
    public function calculateAdherence(
        int $scheduleId,
        string $startDate,
        string $endDate,
    ): array {
        $windowDays = self::inclusiveDayCount($startDate, $endDate);

        if ($startDate > $endDate) {
            return self::empty(0);
        }

        $schedule = $this->schedules->getScheduleById($scheduleId);
        if ($schedule === null) {
            return self::empty($windowDays);
        }

        // PRN (as-needed) schedules have no expected cadence -- excluding
        // them from adherence keeps the percentage honest. Returning an
        // empty report with coverage_days=0 matches the "schedule wasn't
        // active in this window" convention, which callers already handle
        // as "N/A".
        $frequency = $schedule['frequency'];
        if ($schedule['is_prn'] || $frequency === null) {
            return self::empty($windowDays);
        }

        $schedStart = $schedule['start_date'];
        $schedEnd = $schedule['end_date'];
        $effectiveStart = $startDate > $schedStart ? $startDate : $schedStart;
        $effectiveEnd = $endDate;
        if ($schedEnd !== null && $schedEnd < $endDate) {
            $effectiveEnd = $schedEnd;
        }

        $expected = 0;
        $actual = 0;
        $coverageDays = 0;
        if ($effectiveStart <= $effectiveEnd) {
            // HC-121: cycle-aware day count. When the schedule has a
            // cycle (on_days/off_days), only on-days produce expected
            // doses. Continuous schedules return the full day span.
            $cycleOn = $schedule['cycle_on_days'] ?? null;
            $cycleOff = $schedule['cycle_off_days'] ?? null;
            $coverageDays = ScheduleCalculator::countOnDaysInRange(
                $schedStart,
                $cycleOn,
                $cycleOff,
                $effectiveStart,
                $effectiveEnd,
            );

            // HC-124: subtract paused days so expected count doesn't
            // penalise the caregiver for days the schedule was on hold.
            if ($coverageDays > 0 && $this->pauses !== null) {
                $pausedDays = $this->pauses->countPausedDaysInRange(
                    $scheduleId,
                    $effectiveStart,
                    $effectiveEnd,
                );
                $coverageDays = max(0, $coverageDays - $pausedDays);
            }

            if ($coverageDays > 0) {
                // HC-123: wall-clock times override frequency-based math.
                $wallClock = $schedule['wall_clock_times'] ?? null;
                $wallClockDpd = ScheduleCalculator::dosesPerDayFromWallClock($wallClock);
                if ($wallClockDpd > 0) {
                    $dosesPerDay = $wallClockDpd;
                } else {
                    $secondsPerDose = ScheduleCalculator::frequencyToSeconds($frequency);
                    $dosesPerDay = 86400 / $secondsPerDose;
                }
                $expected = (int) round($coverageDays * $dosesPerDay);
            }

            $actual = $this->intakes->countIntakesBetween(
                $scheduleId,
                $effectiveStart . ' 00:00:00',
                $effectiveEnd . ' 23:59:59',
            );
        }

        $percentage = $expected > 0
            ? round(($actual / $expected) * 100, 1)
            : 0.0;

        return [
            'expected' => $expected,
            'actual' => $actual,
            'percentage' => $percentage,
            'coverage_days' => $coverageDays,
            'window_days' => $windowDays,
        ];
    }

    /**
     * @return AdherenceReport
     */
    private static function empty(int $windowDays): array
    {
        return [
            'expected' => 0,
            'actual' => 0,
            'percentage' => 0.0,
            'coverage_days' => 0,
            'window_days' => $windowDays,
        ];
    }

    /**
     * Inclusive date diff in whole days. Returns 0 when $start > $end.
     */
    private static function inclusiveDayCount(string $start, string $end): int
    {
        if ($start > $end) {
            return 0;
        }
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) {
            return 0;
        }

        return (int) floor(($endTs - $startTs) / 86400) + 1;
    }
}
