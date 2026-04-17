<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Service;

use HomeCare\Domain\ScheduleCalculator;
use HomeCare\Repository\IntakeRepositoryInterface;
use HomeCare\Repository\ScheduleRepositoryInterface;
use HomeCare\Service\CadenceCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CadenceCheck::class)]
final class CadenceCheckTest extends TestCase
{
    private CadenceCheck $check;

    /** @var IntakeRepositoryInterface&MockObject */
    private IntakeRepositoryInterface $intakeRepo;

    /** @var ScheduleRepositoryInterface&MockObject */
    private ScheduleRepositoryInterface $scheduleRepo;

    protected function setUp(): void
    {
        $this->intakeRepo = $this->createMock(IntakeRepositoryInterface::class);
        $this->scheduleRepo = $this->createMock(ScheduleRepositoryInterface::class);
        $this->check = new CadenceCheck($this->intakeRepo, $this->scheduleRepo, new ScheduleCalculator());
    }

    public function testDivergenceReturnsNullWithTooFewIntakes(): void
    {
        $scheduleId = 1;
        $this->scheduleRepo->method('getScheduleById')->with($scheduleId)->willReturn(['frequency' => '12h']);
        $this->intakeRepo->method('getIntakesSince')->willReturn([]);

        $divergence = $this->check->divergence($scheduleId, 5);

        $this->assertNull($divergence);
    }

    public function testDivergenceCalculatesRatioWithinTolerance(): void
    {
        $scheduleId = 1;
        $schedule = ['frequency' => '12h', 'start_date' => '2026-04-10'];
        $intakes = [
            ['taken_time' => '2026-04-15 08:00:00'],
            ['taken_time' => '2026-04-15 20:00:00'],
            ['taken_time' => '2026-04-16 08:00:00'],
            ['taken_time' => '2026-04-16 20:00:00'],
            ['taken_time' => '2026-04-17 08:00:00'],
            ['taken_time' => '2026-04-17 20:00:00'],
        ];
        $this->scheduleRepo->method('getScheduleById')->with($scheduleId)->willReturn($schedule);
        $this->intakeRepo->method('getIntakesSince')->with($scheduleId, '2026-04-10')->willReturn($intakes);

        $divergence = $this->check->divergence($scheduleId, 5);

        $this->assertIsFloat($divergence);
        $this->assertGreaterThanOrEqual(0.95, $divergence);
        $this->assertLessThanOrEqual(1.05, $divergence);
    }

    public function testDivergenceDetectsMismatchAboveThreshold(): void
    {
        $scheduleId = 1;
        $schedule = ['frequency' => '2d', 'start_date' => '2026-04-10'];
        $intakes = [
            ['taken_time' => '2026-04-15 09:00:00'],
            ['taken_time' => '2026-04-15 21:00:00'],
            ['taken_time' => '2026-04-16 09:00:00'],
            ['taken_time' => '2026-04-16 21:00:00'],
            ['taken_time' => '2026-04-17 09:00:00'],
            ['taken_time' => '2026-04-17 21:00:00'],
        ];
        $this->scheduleRepo->method('getScheduleById')->with($scheduleId)->willReturn($schedule);
        $this->intakeRepo->method('getIntakesSince')->with($scheduleId, '2026-04-10')->willReturn($intakes);

        $divergence = $this->check->divergence($scheduleId, 5);

        $this->assertIsFloat($divergence);
        $this->assertLessThan(0.5, $divergence);
    }

    public function testNoWarningWhenExactlyOneIntake(): void
    {
        $scheduleId = 1;
        $schedule = ['frequency' => '12h', 'start_date' => '2026-04-10'];
        $intakes = [
            ['taken_time' => '2026-04-15 08:00:00'],
        ];
        $this->scheduleRepo->method('getScheduleById')->with($scheduleId)->willReturn($schedule);
        $this->intakeRepo->method('getIntakesSince')->with($scheduleId, '2026-04-10')->willReturn($intakes);

        $divergence = $this->check->divergence($scheduleId, 2);

        $this->assertNull($divergence);
    }

    public function testDivergenceIgnoresOutlierIntakes(): void
    {
        $scheduleId = 1;
        $schedule = ['frequency' => '12h', 'start_date' => '2026-04-10'];
        $intakes = [
            ['taken_time' => '2026-04-16 08:00:00'],
            ['taken_time' => '2026-04-15 20:00:00'],
            ['taken_time' => '2026-04-15 08:00:00'],
            ['taken_time' => '2026-04-14 20:00:00'],
            ['taken_time' => '2026-04-14 08:00:00'],
            ['taken_time' => '2026-04-13 20:00:00'],
        ];
        $this->scheduleRepo->method('getScheduleById')->with($scheduleId)->willReturn($schedule);
        $this->intakeRepo->method('getIntakesSince')->with($scheduleId, '2026-04-10')->willReturn($intakes);

        $divergence = $this->check->divergence($scheduleId, 5);

        $this->assertIsFloat($divergence);
        $this->assertGreaterThanOrEqual(0.95, $divergence);
        $this->assertLessThanOrEqual(1.05, $divergence);
    }

    public function testGetWarningTextReturnsNullWhenNoWarning(): void
    {
        $scheduleId = 1;
        $this->scheduleRepo->method('getScheduleById')->with($scheduleId)->willReturn(['frequency' => '12h', 'start_date' => '2026-04-10']);
        $this->intakeRepo->method('getIntakesSince')->willReturn([
            ['taken_time' => '2026-04-17 20:00:00'],
        ]);

        $warning = $this->check->getWarningText($scheduleId);

        $this->assertNull($warning);
    }

    public function testGetWarningTextReturnsMessageWhenMismatch(): void
    {
        $scheduleId = 1;
        $schedule = ['frequency' => '2d', 'start_date' => '2026-04-10'];
        $intakes = [
            ['taken_time' => '2026-04-15 09:00:00'],
            ['taken_time' => '2026-04-15 21:00:00'],
            ['taken_time' => '2026-04-16 09:00:00'],
            ['taken_time' => '2026-04-16 21:00:00'],
            ['taken_time' => '2026-04-17 09:00:00'],
            ['taken_time' => '2026-04-17 21:00:00'],
        ];
        $this->scheduleRepo->method('getScheduleById')->with($scheduleId)->willReturn($schedule);
        $this->intakeRepo->method('getIntakesSince')->with($scheduleId, '2026-04-10')->willReturn($intakes);

        $warning = $this->check->getWarningText($scheduleId);

        $this->assertIsString($warning);
        $this->assertStringContainsString('Recent doses average every', $warning);
    }
}
