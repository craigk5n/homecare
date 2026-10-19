<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Service;

use HomeCare\Service\PrnLimitService;
use HomeCare\Service\PrnLimitStatus;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function coverage for the HC-238 rolling-window math.
 *
 * The window is the 24 hours ENDING at the proposed dose, open at the
 * old end: a dose taken exactly 24h earlier has aged out.
 */
final class PrnLimitServiceTest extends TestCase
{
    private static function ts(string $s): int
    {
        $t = strtotime($s);
        self::assertNotFalse($t);

        return $t;
    }

    /**
     * @param list<string> $taken
     */
    private static function eval(?int $max, ?float $minHours, array $taken, string $at): PrnLimitStatus
    {
        return PrnLimitService::evaluate($max, $minHours, $taken, self::ts($at));
    }

    public function testNoDosesYetLeavesFullAllowance(): void
    {
        $s = self::eval(3, null, [], '2026-04-16 12:00:00');

        $this->assertSame(0, $s->dosesInWindow);
        $this->assertSame(3, $s->remaining());
        $this->assertNull($s->nextAllowedAt);
        $this->assertFalse($s->wouldExceedMax());
        $this->assertFalse($s->isWarning());
        $this->assertNull($s->lastTakenAt);
    }

    public function testUnderLimitAllowsDoseNow(): void
    {
        $s = self::eval(3, null, ['2026-04-16 06:00:00', '2026-04-16 10:00:00'], '2026-04-16 12:00:00');

        $this->assertSame(2, $s->dosesInWindow);
        $this->assertSame(1, $s->remaining());
        $this->assertNull($s->nextAllowedAt);
        $this->assertFalse($s->isWarning());
        $this->assertSame('2026-04-16 10:00:00', $s->lastTakenAt);
    }

    public function testAtLimitWarnsAndReportsWhenOldestDoseAgesOut(): void
    {
        $s = self::eval(
            3,
            null,
            ['2026-04-16 06:00:00', '2026-04-16 08:00:00', '2026-04-16 10:00:00'],
            '2026-04-16 12:00:00',
        );

        $this->assertSame(3, $s->dosesInWindow);
        $this->assertSame(0, $s->remaining());
        $this->assertTrue($s->wouldExceedMax());
        $this->assertTrue($s->isWarning());
        // Oldest of the three leaves the window 24h after it was taken.
        $this->assertSame('2026-04-17 06:00:00', $s->nextAllowedAt);
    }

    public function testOverLimitNextAllowedWaitsForEnoughDosesToAgeOut(): void
    {
        // 4 doses already against a max of 2: two must age out before
        // another is within limits, so the 2nd-oldest decides.
        $s = self::eval(
            2,
            null,
            ['2026-04-16 01:00:00', '2026-04-16 02:00:00', '2026-04-16 03:00:00', '2026-04-16 04:00:00'],
            '2026-04-16 05:00:00',
        );

        $this->assertSame(4, $s->dosesInWindow);
        $this->assertSame(0, $s->remaining());
        $this->assertSame('2026-04-17 03:00:00', $s->nextAllowedAt);
    }

    public function testDoseExactly24hEarlierHasAgedOut(): void
    {
        $s = self::eval(1, null, ['2026-04-15 12:00:00'], '2026-04-16 12:00:00');

        $this->assertSame(0, $s->dosesInWindow);
        $this->assertFalse($s->wouldExceedMax());
    }

    public function testDoseOneSecondInsideWindowStillCounts(): void
    {
        $s = self::eval(1, null, ['2026-04-15 12:00:01'], '2026-04-16 12:00:00');

        $this->assertSame(1, $s->dosesInWindow);
        $this->assertTrue($s->wouldExceedMax());
        $this->assertSame('2026-04-16 12:00:01', $s->nextAllowedAt);
    }

    public function testDoseAtProposedInstantCounts(): void
    {
        $s = self::eval(1, null, ['2026-04-16 12:00:00'], '2026-04-16 12:00:00');

        $this->assertSame(1, $s->dosesInWindow);
    }

    public function testDosesAfterProposedTimeAreIgnored(): void
    {
        // Back-dating a dose: later intakes don't count toward the
        // window that ends at the proposed time.
        $s = self::eval(1, null, ['2026-04-16 13:00:00'], '2026-04-16 12:00:00');

        $this->assertSame(0, $s->dosesInWindow);
    }

    public function testUnparseableTimestampsAreSkipped(): void
    {
        $s = self::eval(2, null, ['garbage', '2026-04-16 10:00:00'], '2026-04-16 12:00:00');

        $this->assertSame(1, $s->dosesInWindow);
    }

    public function testMinIntervalTooSoon(): void
    {
        $s = self::eval(null, 4.0, ['2026-04-16 10:00:00'], '2026-04-16 12:00:00');

        $this->assertNull($s->remaining());
        $this->assertFalse($s->wouldExceedMax());
        $this->assertTrue($s->tooSoon);
        $this->assertTrue($s->isWarning());
        $this->assertSame('2026-04-16 14:00:00', $s->nextAllowedAt);
    }

    public function testMinIntervalExactlyElapsedIsAllowed(): void
    {
        $s = self::eval(null, 4.0, ['2026-04-16 08:00:00'], '2026-04-16 12:00:00');

        $this->assertFalse($s->tooSoon);
        $this->assertNull($s->nextAllowedAt);
    }

    public function testFractionalMinInterval(): void
    {
        $s = self::eval(null, 1.5, ['2026-04-16 11:00:00'], '2026-04-16 12:00:00');

        $this->assertTrue($s->tooSoon);
        $this->assertSame('2026-04-16 12:30:00', $s->nextAllowedAt);
    }

    public function testMinIntervalLongerThan24hUsesLastDoseOutsideWindow(): void
    {
        $s = self::eval(null, 48.0, ['2026-04-15 06:00:00'], '2026-04-16 12:00:00');

        $this->assertSame(0, $s->dosesInWindow);
        $this->assertTrue($s->tooSoon);
        $this->assertSame('2026-04-17 06:00:00', $s->nextAllowedAt);
    }

    public function testNextAllowedIsLaterOfBothConstraints(): void
    {
        // Max 2/24h: oldest ages out at 2026-04-17 01:00. Min 4h from
        // the 11:00 dose is 15:00 today — the 24h rule dominates.
        $s = self::eval(2, 4.0, ['2026-04-16 01:00:00', '2026-04-16 11:00:00'], '2026-04-16 12:00:00');
        $this->assertSame('2026-04-17 01:00:00', $s->nextAllowedAt);

        // Under the max, only the interval applies.
        $s = self::eval(3, 4.0, ['2026-04-16 01:00:00', '2026-04-16 11:00:00'], '2026-04-16 12:00:00');
        $this->assertFalse($s->wouldExceedMax());
        $this->assertSame('2026-04-16 15:00:00', $s->nextAllowedAt);
    }

    public function testLimitLevelCountsIncludingRecordedDose(): void
    {
        $taken = ['2026-04-16 06:00:00', '2026-04-16 08:00:00'];
        $this->assertNull(self::eval(3, null, $taken, '2026-04-16 12:00:00')->limitLevel());

        $taken[] = '2026-04-16 12:00:00';
        $this->assertSame(PrnLimitStatus::LEVEL_REACHED, self::eval(3, null, $taken, '2026-04-16 12:00:00')->limitLevel());

        $taken[] = '2026-04-16 12:00:00';
        $this->assertSame(PrnLimitStatus::LEVEL_EXCEEDED, self::eval(3, null, $taken, '2026-04-16 12:00:00')->limitLevel());
    }

    public function testLimitLevelNullWithoutMax(): void
    {
        $this->assertNull(self::eval(null, 4.0, ['2026-04-16 11:00:00'], '2026-04-16 12:00:00')->limitLevel());
    }

    public function testWarningMessageMentionsCountsAndNextTime(): void
    {
        $s = self::eval(2, null, ['2026-04-16 06:00:00', '2026-04-16 10:00:00'], '2026-04-16 12:00:00');

        $msg = $s->warningMessage();
        $this->assertNotNull($msg);
        $this->assertStringContainsString('2 of 2', $msg);
        $this->assertStringContainsString('2026-04-17 06:00', $msg);
    }

    public function testWarningMessageForTooSoon(): void
    {
        $s = self::eval(null, 4.0, ['2026-04-16 10:00:00'], '2026-04-16 12:00:00');

        $msg = $s->warningMessage();
        $this->assertNotNull($msg);
        $this->assertStringContainsString('4 hours', $msg);
    }

    public function testNoWarningMessageWhenWithinLimits(): void
    {
        $this->assertNull(self::eval(3, 4.0, [], '2026-04-16 12:00:00')->warningMessage());
    }

    public function testToArrayShape(): void
    {
        $s = self::eval(3, 4.0, ['2026-04-16 10:00:00'], '2026-04-16 12:00:00');

        $this->assertSame([
            'max_doses_per_24h' => 3,
            'min_hours_between' => 4.0,
            'doses_in_last_24h' => 1,
            'remaining' => 2,
            'last_taken_at' => '2026-04-16 10:00:00',
            'next_allowed_at' => '2026-04-16 14:00:00',
            'would_exceed_max' => false,
            'too_soon' => true,
        ], $s->toArray());
    }

    public function testNormaliseLimitsParsesFormInput(): void
    {
        $this->assertSame(
            ['max_doses_per_24h' => 4, 'min_hours_between' => 4.5],
            PrnLimitService::normaliseLimits(true, '4', '4.5'),
        );
    }

    public function testNormaliseLimitsTreatsBlankZeroAndJunkAsNoLimit(): void
    {
        $none = ['max_doses_per_24h' => null, 'min_hours_between' => null];
        $this->assertSame($none, PrnLimitService::normaliseLimits(true, '', ''));
        $this->assertSame($none, PrnLimitService::normaliseLimits(true, null, null));
        $this->assertSame($none, PrnLimitService::normaliseLimits(true, '0', '0'));
        $this->assertSame($none, PrnLimitService::normaliseLimits(true, '-2', '-1'));
        $this->assertSame($none, PrnLimitService::normaliseLimits(true, 'abc', 'x'));
        $this->assertSame($none, PrnLimitService::normaliseLimits(true, '2.5', 'NaN'));
    }

    public function testNormaliseLimitsDropsLimitsForFixedCadence(): void
    {
        $this->assertSame(
            ['max_doses_per_24h' => null, 'min_hours_between' => null],
            PrnLimitService::normaliseLimits(false, '4', '4'),
        );
    }

    public function testNormaliseLimitsCapsAbsurdValues(): void
    {
        // DECIMAL(5,2) tops out below 1000; anything past a week apart
        // is a typo rather than a dosing instruction.
        $out = PrnLimitService::normaliseLimits(true, '1000', '5000');
        $this->assertSame(PrnLimitService::MAX_DOSES_CAP, $out['max_doses_per_24h']);
        $this->assertSame(PrnLimitService::MAX_MIN_HOURS, $out['min_hours_between']);
    }
}
