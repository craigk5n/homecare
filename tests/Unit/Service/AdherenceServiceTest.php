<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Service;

use HomeCare\Repository\IntakeRepositoryInterface;
use HomeCare\Repository\ScheduleRepositoryInterface;
use HomeCare\Service\AdherenceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdherenceServiceTest extends TestCase
{
    /** @var ScheduleRepositoryInterface&MockObject */
    private ScheduleRepositoryInterface $schedules;

    /** @var IntakeRepositoryInterface&MockObject */
    private IntakeRepositoryInterface $intakes;

    private AdherenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedules = $this->createMock(ScheduleRepositoryInterface::class);
        $this->intakes = $this->createMock(IntakeRepositoryInterface::class);
        $this->service = new AdherenceService($this->schedules, $this->intakes);
    }

    public function testPerfectAdherenceIsOneHundredPercent(): void
    {
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(start: '2026-04-01', end: null, frequency: '8h'),
        );
        // 10 days * 3 doses/day = 30 expected; 30 actual → 100%.
        $this->intakes->method('countIntakesBetween')->willReturn(30);

        $result = $this->service->calculateAdherence(1, '2026-04-01', '2026-04-10');

        $this->assertSame(30, $result['expected']);
        $this->assertSame(30, $result['actual']);
        $this->assertSame(100.0, $result['percentage']);
        $this->assertSame(10, $result['coverage_days']);
        $this->assertSame(10, $result['window_days']);
    }

    public function testHalfDosesMissedIsFiftyPercent(): void
    {
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(start: '2026-04-01', end: null, frequency: '12h'),
        );
        // 10 days * 2 doses/day = 20 expected; 10 actual → 50%.
        $this->intakes->method('countIntakesBetween')->willReturn(10);

        $result = $this->service->calculateAdherence(1, '2026-04-01', '2026-04-10');

        $this->assertSame(20, $result['expected']);
        $this->assertSame(10, $result['actual']);
        $this->assertSame(50.0, $result['percentage']);
    }

    public function testScheduleStartedMidPeriodShrinksExpectedCount(): void
    {
        // Query window: Apr 1 – Apr 10 (10 days).
        // Schedule started Apr 6, so only 5 effective days (Apr 6–10).
        // 5 * 1 dose/day = 5 expected.
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(start: '2026-04-06', end: null, frequency: '1d'),
        );
        $this->intakes->method('countIntakesBetween')->willReturn(5);

        $result = $this->service->calculateAdherence(1, '2026-04-01', '2026-04-10');

        $this->assertSame(5, $result['expected']);
        $this->assertSame(5, $result['actual']);
        $this->assertSame(100.0, $result['percentage']);
        // The schedule only overlapped 5 of the 10 window days.
        $this->assertSame(5, $result['coverage_days']);
        $this->assertSame(10, $result['window_days']);
    }

    public function testScheduleEndedMidPeriodShrinksExpectedCount(): void
    {
        // Query window: Apr 1 – Apr 10. Schedule ended Apr 5. Effective:
        // Apr 1–5 = 5 days * 2 doses/day (12h) = 10 expected.
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(start: '2026-01-01', end: '2026-04-05', frequency: '12h'),
        );
        $this->intakes->method('countIntakesBetween')->willReturn(10);

        $result = $this->service->calculateAdherence(1, '2026-04-01', '2026-04-10');

        $this->assertSame(10, $result['expected']);
    }

    public function testNoIntakesIsZeroPercent(): void
    {
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(start: '2026-04-01', end: null, frequency: '1d'),
        );
        $this->intakes->method('countIntakesBetween')->willReturn(0);

        $result = $this->service->calculateAdherence(1, '2026-04-01', '2026-04-10');

        $this->assertSame(10, $result['expected']);
        $this->assertSame(0, $result['actual']);
        $this->assertSame(0.0, $result['percentage']);
    }

    public function testPercentageRoundedToOneDecimalPlace(): void
    {
        // 10 days * 3 doses/day = 30 expected. Actual = 23 → 76.666...% → 76.7%
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(start: '2026-04-01', end: null, frequency: '8h'),
        );
        $this->intakes->method('countIntakesBetween')->willReturn(23);

        $result = $this->service->calculateAdherence(1, '2026-04-01', '2026-04-10');

        $this->assertSame(76.7, $result['percentage']);
    }

    public function testMissingScheduleReturnsZeros(): void
    {
        $this->schedules->method('getScheduleById')->willReturn(null);
        // The intake repo should not be consulted once we know the schedule's gone.
        $this->intakes->expects($this->never())->method('countIntakesBetween');

        $result = $this->service->calculateAdherence(9999, '2026-04-01', '2026-04-10');

        $this->assertSame(0, $result['expected']);
        $this->assertSame(0, $result['actual']);
        $this->assertSame(0.0, $result['percentage']);
        $this->assertSame(0, $result['coverage_days']);
        // Window is known even when the schedule isn't.
        $this->assertSame(10, $result['window_days']);
    }

    public function testReversedDateRangeReturnsZeros(): void
    {
        $this->schedules->expects($this->never())->method('getScheduleById');
        $result = $this->service->calculateAdherence(1, '2026-04-10', '2026-04-01');
        $this->assertSame(0, $result['expected']);
        $this->assertSame(0, $result['actual']);
        $this->assertSame(0.0, $result['percentage']);
        $this->assertSame(0, $result['coverage_days']);
        $this->assertSame(0, $result['window_days']);
    }

    public function testScheduleEntirelyOutsideWindowYieldsZeroExpectedAndZeroCoverage(): void
    {
        // Schedule ran Jan – Feb; asking about May. This is the "N/A"
        // case that the UI distinguishes from "active but 0%": coverage_days
        // must be zero so the report can gray the bar out.
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(start: '2026-01-01', end: '2026-02-28', frequency: '1d'),
        );
        $this->intakes->method('countIntakesBetween')->willReturn(0);

        $result = $this->service->calculateAdherence(1, '2026-05-01', '2026-05-10');

        $this->assertSame(0, $result['expected']);
        $this->assertSame(0.0, $result['percentage']);
        $this->assertSame(0, $result['coverage_days']);
        $this->assertSame(10, $result['window_days']);
    }

    public function testActiveButZeroIntakesIsDistinguishableFromNotActive(): void
    {
        // A schedule that covered the entire window but had no intakes
        // recorded is 0% adherence — but coverage_days > 0 so the UI
        // reports it as a real zero, not "N/A".
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(start: '2026-04-01', end: null, frequency: '1d'),
        );
        $this->intakes->method('countIntakesBetween')->willReturn(0);

        $result = $this->service->calculateAdherence(1, '2026-04-01', '2026-04-10');

        $this->assertSame(10, $result['expected']);
        $this->assertSame(0, $result['actual']);
        $this->assertSame(0.0, $result['percentage']);
        $this->assertSame(10, $result['coverage_days']);
        $this->assertSame(10, $result['window_days']);
    }

    public function testOverAdherenceReportsAboveOneHundred(): void
    {
        // Not capped -- makes accidental double-recording visible on reports.
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(start: '2026-04-01', end: null, frequency: '1d'),
        );
        $this->intakes->method('countIntakesBetween')->willReturn(15);

        $result = $this->service->calculateAdherence(1, '2026-04-01', '2026-04-10');

        $this->assertSame(10, $result['expected']);
        $this->assertSame(15, $result['actual']);
        $this->assertSame(150.0, $result['percentage']);
    }

    /**
     * @return array{id:int,patient_id:int,medicine_id:int,start_date:string,end_date:?string,frequency:string,unit_per_dose:float,created_at:?string}
     */
    private static function schedule(
        int $id = 1,
        string $start = '2026-01-01',
        ?string $end = null,
        string $frequency = '1d',
    ): array {
        return [
            'id' => $id,
            'patient_id' => 1,
            'medicine_id' => 1,
            'start_date' => $start,
            'end_date' => $end,
            'frequency' => $frequency,
            'unit_per_dose' => 1.0,
            'created_at' => '2026-01-01 00:00:00',
        ];
    }
}
