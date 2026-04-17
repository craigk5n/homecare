<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Domain;

use HomeCare\Domain\ScheduleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScheduleCalculatorTest extends TestCase
{
    // frequencyToSeconds -------------------------------------------------

    public function testFrequencyToSecondsConvertsDays(): void
    {
        $this->assertSame(86400, ScheduleCalculator::frequencyToSeconds('1d'));
        $this->assertSame(2 * 86400, ScheduleCalculator::frequencyToSeconds('2d'));
    }

    public function testFrequencyToSecondsConvertsHours(): void
    {
        $this->assertSame(8 * 3600, ScheduleCalculator::frequencyToSeconds('8h'));
        $this->assertSame(12 * 3600, ScheduleCalculator::frequencyToSeconds('12h'));
    }

    public function testFrequencyToSecondsConvertsMinutes(): void
    {
        $this->assertSame(30 * 60, ScheduleCalculator::frequencyToSeconds('30m'));
    }

    public function testFrequencyToSecondsThrowsOnInvalidUnit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScheduleCalculator::frequencyToSeconds('5y');
    }

    public function testFrequencyToSecondsThrowsOnEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScheduleCalculator::frequencyToSeconds('');
    }

    public function testFrequencyToSecondsThrowsOnNonPositiveAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScheduleCalculator::frequencyToSeconds('0h');
    }

    // calculateSecondsUntilDue -------------------------------------------

    public function testCalculateSecondsUntilDueReturnsPositiveForFutureDose(): void
    {
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        // Next dose due at lastTaken + 8h → 7h from now ≈ 25200 s
        $seconds = ScheduleCalculator::calculateSecondsUntilDue($oneHourAgo, '8h');
        $this->assertGreaterThan(25000, $seconds);
        $this->assertLessThan(25400, $seconds);
    }

    public function testCalculateSecondsUntilDueReturnsZeroForPastDose(): void
    {
        $twoDaysAgo = date('Y-m-d H:i:s', time() - 2 * 86400);
        $seconds = ScheduleCalculator::calculateSecondsUntilDue($twoDaysAgo, '1d');
        $this->assertSame(0, $seconds);
    }

    public function testCalculateSecondsUntilDueReturnsNegativeWhenShowNegativeTrue(): void
    {
        $twoDaysAgo = date('Y-m-d H:i:s', time() - 2 * 86400);
        $seconds = ScheduleCalculator::calculateSecondsUntilDue($twoDaysAgo, '1d', true);
        $this->assertLessThan(0, $seconds);
    }

    public function testCalculateSecondsUntilDueAtExactDueTime(): void
    {
        // lastTaken exactly 8h ago → due right now → nonNegative case returns 0
        $eightHoursAgo = date('Y-m-d H:i:s', time() - 8 * 3600);
        $seconds = ScheduleCalculator::calculateSecondsUntilDue($eightHoursAgo, '8h');
        $this->assertSame(0, $seconds);
    }

    public function testCalculateSecondsUntilDueRejectsInvalidFrequency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScheduleCalculator::calculateSecondsUntilDue('2026-04-13 12:00:00', 'bogus');
    }

    // calculateNextDueDate -----------------------------------------------

    public function testCalculateNextDueDateForHours(): void
    {
        $result = ScheduleCalculator::calculateNextDueDate('2026-04-13 10:00:00', '8h');
        $this->assertSame('2026-04-13 18:00', $result);
    }

    public function testCalculateNextDueDateForDays(): void
    {
        $result = ScheduleCalculator::calculateNextDueDate('2026-04-13 10:00:00', '2d');
        $this->assertSame('2026-04-15 10:00', $result);
    }

    public function testCalculateNextDueDateForMinutesReturnsNullSentinel(): void
    {
        // Original behavior: 'm' unit returns null from getIntervalSpecFromFrequency,
        // so calculateNextDueDate returns a "Frequency error" sentinel.
        $result = ScheduleCalculator::calculateNextDueDate('2026-04-13 10:00:00', '30m');
        $this->assertSame('Frequency error', $result);
    }

    // getIntervalSpecFromFrequency ---------------------------------------

    public function testGetIntervalSpecFromFrequencyHours(): void
    {
        $this->assertSame('PT8H', ScheduleCalculator::getIntervalSpecFromFrequency('8h'));
    }

    public function testGetIntervalSpecFromFrequencyDays(): void
    {
        $this->assertSame('P2D', ScheduleCalculator::getIntervalSpecFromFrequency('2d'));
    }

    public function testGetIntervalSpecFromFrequencyMinutesReturnsNull(): void
    {
        $this->assertNull(ScheduleCalculator::getIntervalSpecFromFrequency('30m'));
    }

    // PRN (as-needed) handling — HC-120 ----------------------------------

    public function testCalculateSecondsUntilDueOrNullReturnsNullForNullFrequency(): void
    {
        $this->assertNull(
            ScheduleCalculator::calculateSecondsUntilDueOrNull(
                date('Y-m-d H:i:s', time() - 3600),
                null,
            )
        );
    }

    public function testCalculateSecondsUntilDueOrNullDelegatesForFixedFrequency(): void
    {
        $eightHoursAgo = date('Y-m-d H:i:s', time() - 8 * 3600);
        $this->assertSame(0, ScheduleCalculator::calculateSecondsUntilDueOrNull($eightHoursAgo, '8h'));
    }

    public function testCalculateNextDueDateOrNullReturnsNullForNullFrequency(): void
    {
        $this->assertNull(
            ScheduleCalculator::calculateNextDueDateOrNull('2026-04-13 10:00:00', null)
        );
    }

    public function testCalculateNextDueDateOrNullDelegatesForFixedFrequency(): void
    {
        $this->assertSame(
            '2026-04-13 18:00',
            ScheduleCalculator::calculateNextDueDateOrNull('2026-04-13 10:00:00', '8h')
        );
    }

    // Cycle dosing — HC-121 -----------------------------------------------

    public function testIsOnDayReturnsTrueDuringOnPeriod(): void
    {
        // 3 weeks on (21d), 1 week off (7d). Schedule started 2026-01-01.
        // Day 0 (Jan 1) = on, Day 20 (Jan 21) = on, Day 21 (Jan 22) = off.
        $this->assertTrue(ScheduleCalculator::isOnDay('2026-01-01', 21, 7, '2026-01-01'));
        $this->assertTrue(ScheduleCalculator::isOnDay('2026-01-01', 21, 7, '2026-01-10'));
        $this->assertTrue(ScheduleCalculator::isOnDay('2026-01-01', 21, 7, '2026-01-21'));
    }

    public function testIsOnDayReturnsFalseDuringOffPeriod(): void
    {
        // Day 21 (Jan 22) through Day 27 (Jan 28) = off.
        $this->assertFalse(ScheduleCalculator::isOnDay('2026-01-01', 21, 7, '2026-01-22'));
        $this->assertFalse(ScheduleCalculator::isOnDay('2026-01-01', 21, 7, '2026-01-28'));
    }

    public function testIsOnDaySecondCycleStartsOn(): void
    {
        // Day 28 (Jan 29) = start of second cycle → on again.
        $this->assertTrue(ScheduleCalculator::isOnDay('2026-01-01', 21, 7, '2026-01-29'));
    }

    public function testIsOnDayWithNullCycleAlwaysReturnsTrue(): void
    {
        $this->assertTrue(ScheduleCalculator::isOnDay('2026-01-01', null, null, '2026-06-15'));
    }

    public function testIsOnDayBeforeStartDateReturnsFalse(): void
    {
        $this->assertFalse(ScheduleCalculator::isOnDay('2026-03-01', 21, 7, '2026-02-15'));
    }

    public function testCountOnDaysInRangeFullCycle(): void
    {
        // 21 on + 7 off = 28-day cycle. Range: exactly one full cycle.
        $this->assertSame(
            21,
            ScheduleCalculator::countOnDaysInRange('2026-01-01', 21, 7, '2026-01-01', '2026-01-28')
        );
    }

    public function testCountOnDaysInRangePartialOnOnly(): void
    {
        // Range covers only the first 10 on-days.
        $this->assertSame(
            10,
            ScheduleCalculator::countOnDaysInRange('2026-01-01', 21, 7, '2026-01-01', '2026-01-10')
        );
    }

    public function testCountOnDaysInRangeOffPeriodOnly(): void
    {
        // Range falls entirely within the off-period.
        $this->assertSame(
            0,
            ScheduleCalculator::countOnDaysInRange('2026-01-01', 21, 7, '2026-01-22', '2026-01-28')
        );
    }

    public function testCountOnDaysInRangeSpanningOnOffBoundary(): void
    {
        // Day 20 (Jan 21) on, Day 21 (Jan 22) off → 1 on-day in 2-day range.
        $this->assertSame(
            1,
            ScheduleCalculator::countOnDaysInRange('2026-01-01', 21, 7, '2026-01-21', '2026-01-22')
        );
    }

    public function testCountOnDaysInRangeNullCycleCountsAllDays(): void
    {
        $this->assertSame(
            7,
            ScheduleCalculator::countOnDaysInRange('2026-01-01', null, null, '2026-04-01', '2026-04-07')
        );
    }

    public function testCountOnDaysInRangeMultipleCycles(): void
    {
        // 7 on, 3 off = 10-day cycle. Range: 30 days = 3 full cycles → 21 on-days.
        $this->assertSame(
            21,
            ScheduleCalculator::countOnDaysInRange('2026-01-01', 7, 3, '2026-01-01', '2026-01-30')
        );
    }
}
