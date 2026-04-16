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
}
