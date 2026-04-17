<?php

declare(strict_types=1);

namespace HomeCare\Domain;

use DateInterval;
use DateTime;
use InvalidArgumentException;

/**
 * Pure, side-effect-free math for the HomeCare medication schedule.
 *
 * Frequencies are short strings: `Nd` (days), `Nh` (hours), `Nm` (minutes).
 * These helpers are the canonical parsers and throw on unknown units so
 * callers fail fast instead of silently computing the wrong due time.
 */
final class ScheduleCalculator
{
    /**
     * Convert a frequency string ("8h", "1d", "30m") to whole seconds.
     *
     * @throws InvalidArgumentException If the unit is not d/h/m or the amount is not positive.
     */
    public static function frequencyToSeconds(string $frequency): int
    {
        [$amount, $unit] = self::parseFrequency($frequency);

        return match ($unit) {
            'd' => $amount * 86400,
            'h' => $amount * 3600,
            'm' => $amount * 60,
        };
    }


    /**
     * Seconds from "now" until the next dose after $lastTaken is due.
     *
     * When the due time is already in the past, returns 0 unless $showNegative
     * is true -- in which case the negative offset is returned so callers can
     * display how overdue a dose is.
     *
     * @throws InvalidArgumentException If $lastTaken is not parseable or frequency is invalid.
     */
    public static function calculateSecondsUntilDue(
        string $lastTaken,
        string $frequency,
        bool $showNegative = false
    ): int {
        $lastTakenTimestamp = strtotime($lastTaken);
        if ($lastTakenTimestamp === false) {
            throw new InvalidArgumentException("Invalid lastTaken timestamp: {$lastTaken}");
        }

        $nextDueTimestamp = $lastTakenTimestamp + self::frequencyToSeconds($frequency);
        $delta = $nextDueTimestamp - time();

        if ($delta > 0 || $showNegative) {
            return $delta;
        }

        return 0;
    }

    /**
     * ISO date (Y-m-d H:i) of the next due dose, or the string "Frequency error"
     * when the unit has no DateInterval spec (e.g. minutes). The sentinel is
     * preserved from the legacy behavior so existing pages render identically.
     *
     * @throws InvalidArgumentException If $lastTaken is not parseable or frequency amount is invalid.
     */
    public static function calculateNextDueDate(string $lastTaken, string $frequency): string
    {
        $intervalSpec = self::getIntervalSpecFromFrequency($frequency);
        if ($intervalSpec === null) {
            return 'Frequency error';
        }

        try {
            $date = new DateTime($lastTaken);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("Invalid lastTaken timestamp: {$lastTaken}", 0, $e);
        }

        $date->add(new DateInterval($intervalSpec));

        return $date->format('Y-m-d H:i');
    }

    /**
     * Translate a frequency string to a DateInterval spec.
     *
     * Returns null for minute-scale frequencies (no 'PT30M'-style path in the
     * legacy code); callers treat that as "no ISO next-due date is possible".
     *
     * @throws InvalidArgumentException If the frequency amount is not positive or unit is unknown.
     */
    public static function getIntervalSpecFromFrequency(string $frequency): ?string
    {
        [$amount, $unit] = self::parseFrequency($frequency);

        return match ($unit) {
            'h' => 'PT' . $amount . 'H',
            'd' => 'P' . $amount . 'D',
            'm' => null,
        };
    }

    /**
     * PRN-aware wrapper for {@see calculateSecondsUntilDue()}.
     *
     * Returns null when $frequency is null (the PRN case — see HC-120): a
     * PRN schedule has no expected cadence, so "seconds until next dose"
     * is not a meaningful question and callers must treat the row as
     * "no timer".
     */
    public static function calculateSecondsUntilDueOrNull(
        string $lastTaken,
        ?string $frequency,
        bool $showNegative = false
    ): ?int {
        if ($frequency === null) {
            return null;
        }

        return self::calculateSecondsUntilDue($lastTaken, $frequency, $showNegative);
    }

    /**
     * PRN-aware wrapper for {@see calculateNextDueDate()}.
     *
     * Returns null when $frequency is null (the PRN case — see HC-120).
     */
    public static function calculateNextDueDateOrNull(string $lastTaken, ?string $frequency): ?string
    {
        if ($frequency === null) {
            return null;
        }

        return self::calculateNextDueDate($lastTaken, $frequency);
    }

    /**
     * Is the given date within the "on" period of a cycle?
     *
     * Cycles repeat on a fixed calendar: `cycleOnDays` days of normal
     * dosing followed by `cycleOffDays` days off. The cycle resets from
     * `$startDate`. When either cycle parameter is null the schedule is
     * continuous (always on).
     *
     * Returns false if `$targetDate` is before `$startDate`.
     */
    public static function isOnDay(
        string $startDate,
        ?int $cycleOnDays,
        ?int $cycleOffDays,
        string $targetDate,
    ): bool {
        if ($cycleOnDays === null || $cycleOffDays === null) {
            return true;
        }

        $startTs = strtotime($startDate);
        $targetTs = strtotime($targetDate);
        if ($startTs === false || $targetTs === false || $targetTs < $startTs) {
            return false;
        }

        $daysSinceStart = (int) floor(($targetTs - $startTs) / 86400);
        $cycleLength = $cycleOnDays + $cycleOffDays;
        if ($cycleLength <= 0) {
            return true;
        }

        return ($daysSinceStart % $cycleLength) < $cycleOnDays;
    }

    /**
     * Count calendar days in [$rangeStart, $rangeEnd] that fall within
     * the "on" period of a cycle. Used by AdherenceService to compute
     * expected doses for cycled schedules.
     *
     * When cycle parameters are null (continuous), returns the full
     * inclusive day count of the range.
     */
    public static function countOnDaysInRange(
        string $startDate,
        ?int $cycleOnDays,
        ?int $cycleOffDays,
        string $rangeStart,
        string $rangeEnd,
    ): int {
        $rsTs = strtotime($rangeStart);
        $reTs = strtotime($rangeEnd);
        if ($rsTs === false || $reTs === false || $rsTs > $reTs) {
            return 0;
        }

        $totalDays = (int) floor(($reTs - $rsTs) / 86400) + 1;

        if ($cycleOnDays === null || $cycleOffDays === null) {
            return $totalDays;
        }

        $cycleLength = $cycleOnDays + $cycleOffDays;
        if ($cycleLength <= 0) {
            return $totalDays;
        }

        $startTs = strtotime($startDate);
        if ($startTs === false) {
            return $totalDays;
        }

        $count = 0;
        $dayTs = $rsTs;
        for ($i = 0; $i < $totalDays; $i++) {
            if ($dayTs >= $startTs) {
                $daysSinceStart = (int) floor(($dayTs - $startTs) / 86400);
                if (($daysSinceStart % $cycleLength) < $cycleOnDays) {
                    $count++;
                }
            }
            $dayTs += 86400;
        }

        return $count;
    }

    /**
     * Parse a wall_clock_times CSV string into a sorted list of HH:MM strings.
     *
     * @return list<string>
     */
    public static function parseWallClockTimes(?string $wallClockTimes): array
    {
        if ($wallClockTimes === null || $wallClockTimes === '') {
            return [];
        }

        $times = array_map('trim', explode(',', $wallClockTimes));
        $times = array_values(array_filter($times, static fn (string $t): bool => $t !== ''));
        sort($times);

        return $times;
    }

    /**
     * Number of expected doses per day from a wall_clock_times string.
     */
    public static function dosesPerDayFromWallClock(?string $wallClockTimes): int
    {
        return count(self::parseWallClockTimes($wallClockTimes));
    }

    /**
     * Seconds until the next wall-clock dose, and which time/date it is.
     *
     * Returns null when wall_clock_times is empty or null.
     *
     * @return array{seconds:int,next_time:string,next_date:string}|null
     */
    public static function secondsUntilNextWallClock(?string $wallClockTimes, string $now): ?array
    {
        $times = self::parseWallClockTimes($wallClockTimes);
        if ($times === []) {
            return null;
        }

        $nowTs = strtotime($now);
        if ($nowTs === false) {
            return null;
        }

        $todayDate = date('Y-m-d', $nowTs);
        $nowHhMm = date('H:i', $nowTs);

        foreach ($times as $t) {
            if ($t > $nowHhMm) {
                $targetTs = strtotime($todayDate . ' ' . $t . ':00');
                if ($targetTs !== false) {
                    return [
                        'seconds' => $targetTs - $nowTs,
                        'next_time' => $t,
                        'next_date' => $todayDate,
                    ];
                }
            }
        }

        $tomorrowDate = date('Y-m-d', $nowTs + 86400);
        $firstTime = $times[0];
        $targetTs = strtotime($tomorrowDate . ' ' . $firstTime . ':00');
        if ($targetTs === false) {
            return null;
        }

        return [
            'seconds' => $targetTs - $nowTs,
            'next_time' => $firstTime,
            'next_date' => $tomorrowDate,
        ];
    }

    /**
     * @return array{0:positive-int,1:'d'|'h'|'m'} [amount, unit]
     *
     * @throws InvalidArgumentException
     */
    private static function parseFrequency(string $frequency): array
    {
        if ($frequency === '') {
            throw new InvalidArgumentException('Frequency must not be empty');
        }

        $amount = (int) $frequency;
        $unit = substr($frequency, -1);

        if ($amount <= 0) {
            throw new InvalidArgumentException("Frequency amount must be positive: {$frequency}");
        }

        if ($unit !== 'd' && $unit !== 'h' && $unit !== 'm') {
            throw new InvalidArgumentException("Invalid frequency unit: {$unit}");
        }

        return [$amount, $unit];
    }
}
