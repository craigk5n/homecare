<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Service;

use HomeCare\Repository\InventoryRepositoryInterface;
use HomeCare\Repository\ScheduleRepositoryInterface;
use HomeCare\Service\InventoryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class InventoryServiceTest extends TestCase
{
    /** @var InventoryRepositoryInterface&MockObject */
    private InventoryRepositoryInterface $inventory;

    /** @var ScheduleRepositoryInterface&MockObject */
    private ScheduleRepositoryInterface $schedules;

    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventory = $this->createMock(InventoryRepositoryInterface::class);
        $this->schedules = $this->createMock(ScheduleRepositoryInterface::class);
        $this->service = new InventoryService($this->inventory, $this->schedules);
    }

    public function testReturnsZeroRemainingWhenNoInventory(): void
    {
        $this->inventory->method('getMedicineName')->willReturn('Sildenafil');
        $this->inventory->method('getLatestStock')->willReturn(null);
        $this->schedules->method('getScheduleById')->willReturn(self::schedule(['unit_per_dose' => 1.5]));

        $report = $this->service->calculateRemaining(1, 10);

        $this->assertSame(0, $report['remainingDays']);
        $this->assertSame(0.0, $report['remainingDoses']);
        $this->assertNull($report['lastInventory']);
        $this->assertSame(0.0, $report['quantityTakenSince']);
        $this->assertSame(1.5, $report['unitPerDose']);
        $this->assertSame('Sildenafil', $report['medicineName']);
    }

    public function testNormalCaseSubtractsConsumedFromStock(): void
    {
        $this->inventory->method('getMedicineName')->willReturn('Sildenafil');
        $this->inventory->method('getLatestStock')->willReturn([
            'id' => 1, 'medicine_id' => 1, 'quantity' => 60.0,
            'current_stock' => 60.0, 'recorded_at' => '2026-04-01 00:00:00', 'note' => null,
        ]);
        $this->inventory->method('getTotalConsumedSince')->willReturn(12.0);
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(['unit_per_dose' => 1.0, 'frequency' => '8h'])
        );

        $report = $this->service->calculateRemaining(1, 10);

        // 60 - 12 = 48 units. unit_per_dose 1.0 -> 48 doses.
        $this->assertSame(48.0, $report['remainingDoses']);
        // 8h frequency = 3 doses/day -> 16 days.
        $this->assertSame(16, $report['remainingDays']);
        $this->assertSame(60.0, $report['lastInventory']);
        $this->assertSame(12.0, $report['quantityTakenSince']);
    }

    public function testAssumedPastIntakeSubtractsAdditionalUnits(): void
    {
        $this->inventory->method('getMedicineName')->willReturn('Sildenafil');
        $this->inventory->method('getLatestStock')->willReturn([
            'id' => 1, 'medicine_id' => 1, 'quantity' => 100.0,
            'current_stock' => 100.0, 'recorded_at' => '2026-04-01 00:00:00', 'note' => null,
        ]);
        $this->inventory->method('getTotalConsumedSince')->willReturn(0.0);
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(['unit_per_dose' => 2.0, 'frequency' => '1d', 'end_date' => null])
        );

        // 10 days from startDate to yesterday, inclusive, at 1 dose/day * 2 units/dose = 20 units assumed.
        $tenDaysAgo = (new \DateTimeImmutable('-10 days'))->format('Y-m-d');
        $report = $this->service->calculateRemaining(1, 10, true, $tenDaysAgo, '1d');

        // 100 stock - 0 actual - 20 assumed = 80 units left.
        // 80 / 2.0 unit_per_dose = 40 doses, 1 dose/day = 40 days.
        $this->assertSame(40.0, $report['remainingDoses']);
        $this->assertSame(40, $report['remainingDays']);
    }

    public function testZeroUnitPerDoseDoesNotDivideByZero(): void
    {
        $this->inventory->method('getMedicineName')->willReturn('Sildenafil');
        $this->inventory->method('getLatestStock')->willReturn([
            'id' => 1, 'medicine_id' => 1, 'quantity' => 30.0,
            'current_stock' => 30.0, 'recorded_at' => '2026-04-01 00:00:00', 'note' => null,
        ]);
        $this->inventory->method('getTotalConsumedSince')->willReturn(5.0);
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(['unit_per_dose' => 0.0, 'frequency' => '8h'])
        );

        $report = $this->service->calculateRemaining(1, 10);

        $this->assertSame(30.0, $report['lastInventory']);
        $this->assertSame(5.0, $report['quantityTakenSince']);
        // We still report stock, but cannot project doses/days without a positive unit_per_dose.
        $this->assertSame(0.0, $report['remainingDoses']);
        $this->assertSame(0, $report['remainingDays']);
    }

    public function testScheduleLevelUnitPerDoseOverridesMedicineDefault(): void
    {
        // Whatever unit_per_dose the schedule exposes is what we use; the
        // service never falls back to a medicine-level default. This guards
        // the migration that moved unit_per_dose off hc_medicines.
        $this->inventory->method('getMedicineName')->willReturn('Sildenafil');
        $this->inventory->method('getLatestStock')->willReturn([
            'id' => 1, 'medicine_id' => 1, 'quantity' => 20.0,
            'current_stock' => 20.0, 'recorded_at' => '2026-04-01 00:00:00', 'note' => null,
        ]);
        $this->inventory->method('getTotalConsumedSince')->willReturn(0.0);
        $this->schedules->method('getScheduleById')->willReturn(
            self::schedule(['unit_per_dose' => 4.0, 'frequency' => '1d'])
        );

        $report = $this->service->calculateRemaining(1, 10);

        $this->assertSame(4.0, $report['unitPerDose']);
        $this->assertSame(5.0, $report['remainingDoses']); // 20 / 4
        $this->assertSame(5, $report['remainingDays']); // 5 doses at 1/day
    }

    public function testMissingMedicineNameFallsBackToEmptyString(): void
    {
        $this->inventory->method('getMedicineName')->willReturn(null);
        $this->inventory->method('getLatestStock')->willReturn(null);
        $this->schedules->method('getScheduleById')->willReturn(null);

        $report = $this->service->calculateRemaining(1, 10);

        $this->assertSame('', $report['medicineName']);
        $this->assertSame(0.0, $report['unitPerDose']);
    }

    /**
     * @param array{
     *     id?:int,
     *     patient_id?:int,
     *     medicine_id?:int,
     *     start_date?:string,
     *     end_date?:?string,
     *     frequency?:string,
     *     unit_per_dose?:float,
     *     created_at?:?string
     * } $overrides
     *
     * @return array{id:int,patient_id:int,medicine_id:int,start_date:string,end_date:?string,frequency:string,unit_per_dose:float,created_at:?string}
     */
    private static function schedule(array $overrides = []): array
    {
        return [
            'id' => $overrides['id'] ?? 10,
            'patient_id' => $overrides['patient_id'] ?? 1,
            'medicine_id' => $overrides['medicine_id'] ?? 1,
            'start_date' => $overrides['start_date'] ?? '2026-01-01',
            'end_date' => $overrides['end_date'] ?? null,
            'frequency' => $overrides['frequency'] ?? '8h',
            'unit_per_dose' => $overrides['unit_per_dose'] ?? 1.0,
            'created_at' => $overrides['created_at'] ?? '2026-01-01 00:00:00',
        ];
    }
}
