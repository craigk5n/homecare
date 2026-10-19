<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Repository\IntakeRepositoryInterface;
use HomeCare\Repository\ScheduleRepositoryInterface;

/**
 * "May this PRN dose be given now?" (HC-238).
 *
 * A PRN schedule may carry two advisory limits:
 *   - `max_doses_per_24h` -- ceiling over a ROLLING 24h window (not
 *     the calendar day; labels say "no more than 4 in 24 hours").
 *   - `min_hours_between` -- minimum spacing between doses.
 *
 * Limits count DOSES, not units. hc_medicine_intake has no quantity
 * column -- every row is one dose of the schedule's `unit_per_dose`
 * -- so for a given schedule "N doses" and "N x unit_per_dose units"
 * are the same ceiling. Prescribers phrase PRN limits per dose too.
 *
 * Window boundaries: the window ends at the evaluated instant
 * (inclusive) and starts 24h earlier (exclusive), so a dose taken
 * exactly 24h ago has aged out. Intakes recorded AFTER the evaluated
 * instant (back-dating a dose) are ignored.
 *
 * Two public surfaces, mirroring {@see LateDoseAlertService}:
 *   - {@see evaluate()} is pure -- unit tests drive every branch.
 *   - {@see statusFor()} loads the schedule + intakes and delegates.
 */
final class PrnLimitService
{
    public const WINDOW_SECONDS = 86400;

    /** Sanity caps for form input (typos, not dosing instructions). */
    public const MAX_DOSES_CAP = 99;
    public const MAX_MIN_HOURS = 168.0;

    public function __construct(
        private readonly ScheduleRepositoryInterface $schedules,
        private readonly IntakeRepositoryInterface $intakes,
    ) {}

    /**
     * Allowance for a dose at $at (any strtotime-parseable string,
     * including the `Y-m-d\TH:i` a datetime-local input submits).
     *
     * Returns null when there is nothing to enforce: unknown schedule,
     * not PRN, no limits configured, or an unparseable $at.
     */
    public function statusFor(int $scheduleId, string $at): ?PrnLimitStatus
    {
        $schedule = $this->schedules->getScheduleById($scheduleId);
        if ($schedule === null || !$schedule['is_prn']) {
            return null;
        }
        $max = $schedule['max_doses_per_24h'];
        $minHours = $schedule['min_hours_between'];
        if ($max === null && $minHours === null) {
            return null;
        }

        $atTs = strtotime($at);
        if ($atTs === false) {
            return null;
        }

        // Look back far enough for both rules; min spacing may exceed 24h.
        $lookback = max(self::WINDOW_SECONDS, (int) ceil(($minHours ?? 0.0) * 3600));
        $since = date('Y-m-d H:i:s', $atTs - $lookback);

        $taken = [];
        foreach ($this->intakes->getIntakesSince($scheduleId, $since) as $intake) {
            if ($intake['taken_time'] !== null) {
                $taken[] = $intake['taken_time'];
            }
        }

        return self::evaluate($max, $minHours, $taken, $atTs);
    }

    /**
     * Pure rolling-window evaluation.
     *
     * @param list<string> $takenTimes intake timestamps (any order;
     *                                 unparseable ones are skipped)
     */
    public static function evaluate(
        ?int $maxDosesPer24h,
        ?float $minHoursBetween,
        array $takenTimes,
        int $atTs,
    ): PrnLimitStatus {
        $windowStart = $atTs - self::WINDOW_SECONDS;

        $inWindow = [];
        $lastTs = null;
        foreach ($takenTimes as $t) {
            $ts = strtotime($t);
            if ($ts === false || $ts > $atTs) {
                continue;
            }
            if ($lastTs === null || $ts > $lastTs) {
                $lastTs = $ts;
            }
            if ($ts > $windowStart) {
                $inWindow[] = $ts;
            }
        }
        sort($inWindow);
        $count = count($inWindow);

        $nextAllowedTs = null;

        // Over the max: the window must shed (count - max + 1) doses, so
        // the dose at index (count - max) decides when it's clear.
        if ($maxDosesPer24h !== null && $count >= $maxDosesPer24h) {
            $nextAllowedTs = $inWindow[$count - $maxDosesPer24h] + self::WINDOW_SECONDS;
        }

        $tooSoon = false;
        if ($minHoursBetween !== null && $lastTs !== null) {
            $earliest = $lastTs + (int) round($minHoursBetween * 3600);
            if ($earliest > $atTs) {
                $tooSoon = true;
                $nextAllowedTs = max($nextAllowedTs ?? $earliest, $earliest);
            }
        }

        return new PrnLimitStatus(
            maxDosesPer24h: $maxDosesPer24h,
            minHoursBetween: $minHoursBetween,
            dosesInWindow: $count,
            lastTakenAt: $lastTs === null ? null : date('Y-m-d H:i:s', $lastTs),
            nextAllowedAt: $nextAllowedTs === null ? null : date('Y-m-d H:i:s', $nextAllowedTs),
            tooSoon: $tooSoon,
            evaluatedAt: date('Y-m-d H:i:s', $atTs),
        );
    }

    /**
     * Parse the schedule form's limit inputs. Blank / zero / negative /
     * non-numeric mean "no limit"; fixed-cadence schedules never carry
     * limits.
     *
     * @return array{max_doses_per_24h:?int, min_hours_between:?float}
     */
    public static function normaliseLimits(bool $isPrn, mixed $maxRaw, mixed $minHoursRaw): array
    {
        $none = ['max_doses_per_24h' => null, 'min_hours_between' => null];
        if (!$isPrn) {
            return $none;
        }

        $max = null;
        if (is_scalar($maxRaw) && preg_match('/^\s*\d+\s*$/', (string) $maxRaw) === 1) {
            $n = (int) $maxRaw;
            if ($n > 0) {
                $max = min($n, self::MAX_DOSES_CAP);
            }
        }

        $minHours = null;
        if (is_scalar($minHoursRaw) && is_numeric($minHoursRaw)) {
            $h = (float) $minHoursRaw;
            if (is_finite($h) && $h > 0) {
                $minHours = min(round($h, 2), self::MAX_MIN_HOURS);
            }
        }

        return ['max_doses_per_24h' => $max, 'min_hours_between' => $minHours];
    }
}
