<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\InventoryService;
use HomeCare\Service\SupplyAlertLog;
use HomeCare\Service\SupplyAlertService;
use HomeCare\Tests\Factory\InventoryFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class SupplyAlertServiceTest extends DatabaseTestCase
{
    private SupplyAlertService $service;
    private SupplyAlertLog $log;
    private int $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->log = new SupplyAlertLog($db);
        $this->service = new SupplyAlertService(
            $db,
            new InventoryService(new InventoryRepository($db), new ScheduleRepository($db)),
            $this->log,
            static fn(): string => '2026-04-14 12:00:00',
        );

        $this->patientId = (new PatientFactory($db))->create()['id'];
    }

    public function testMedicineWithAmpleStockProducesNoAlert(): void
    {
        $med = $this->seedMedicineWithInventory('Ample', 100.0, '1d');

        $alerts = $this->service->findPendingAlerts(thresholdDays: 7);

        $this->assertSame([], $alerts);
        $this->assertNull($this->log->lastSentAt($med));
    }

    public function testMedicineBelowThresholdProducesAlert(): void
    {
        $med = $this->seedMedicineWithInventory('Running Low', 3.0, '1d'); // 3 days left

        $alerts = $this->service->findPendingAlerts(thresholdDays: 7);

        $this->assertCount(1, $alerts);
        $this->assertSame($med, $alerts[0]->medicineId);
        $this->assertSame('Running Low', $alerts[0]->medicineName);
        $this->assertSame(3, $alerts[0]->remainingDays);
        $this->assertSame('2026-04-17', $alerts[0]->projectedDepletion);
        $this->assertStringContainsString('Low supply', $alerts[0]->message());
    }

    public function testMedicineWithNoInventoryRecordedIsSkipped(): void
    {
        // Schedule exists but no hc_medicine_inventory row was ever recorded.
        // There's nothing to project from, so no alert.
        $db = $this->getDb();
        $med = (new MedicineFactory($db))->create(['name' => 'Never Tracked'])['id'];
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $med,
            'start_date' => '2026-01-01',
            'frequency' => '1d',
            'unit_per_dose' => 1.0,
        ]);

        $this->assertSame([], $this->service->findPendingAlerts());
    }

    public function testAlertSkippedWhenRecentlySentForSameMedicine(): void
    {
        $med = $this->seedMedicineWithInventory('Already Alerted', 3.0, '1d');
        $this->log->markSent($med, '2026-04-14 08:00:00'); // 4 hours ago

        $this->assertSame([], $this->service->findPendingAlerts());
    }

    public function testAlertFiresAgainAfterThrottleExpires(): void
    {
        $med = $this->seedMedicineWithInventory('Recurring', 3.0, '1d');
        $this->log->markSent($med, '2026-04-13 10:00:00'); // 26 hours ago

        $alerts = $this->service->findPendingAlerts();
        $this->assertCount(1, $alerts);
    }

    public function testRecordSentStampsCurrentClock(): void
    {
        $med = $this->seedMedicineWithInventory('Stamp', 3.0, '1d');

        $this->service->recordSent($med);

        $this->assertSame('2026-04-14 12:00:00', $this->log->lastSentAt($med));
    }

    public function testEndedSchedulesAreExcluded(): void
    {
        $db = $this->getDb();
        $med = (new MedicineFactory($db))->create(['name' => 'Ended'])['id'];
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $med,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01', // already ended
            'frequency' => '1d',
            'unit_per_dose' => 1.0,
        ]);
        (new InventoryFactory($db))->create([
            'medicine_id' => $med,
            'current_stock' => 2.0,
        ]);

        $this->assertSame([], $this->service->findPendingAlerts());
    }

    public function testMultipleActiveMedicinesReturnMultipleAlerts(): void
    {
        $this->seedMedicineWithInventory('A-Low', 2.0, '1d');
        $this->seedMedicineWithInventory('B-Also-Low', 4.0, '1d');
        $this->seedMedicineWithInventory('C-Plenty', 50.0, '1d');

        $alerts = $this->service->findPendingAlerts(thresholdDays: 7);

        $this->assertCount(2, $alerts);
        $names = array_map(static fn(\HomeCare\Service\SupplyAlert $a): string => $a->medicineName, $alerts);
        $this->assertSame(['A-Low', 'B-Also-Low'], $names);
    }

    private function seedMedicineWithInventory(
        string $name,
        float $currentStock,
        string $frequency,
    ): int {
        $db = $this->getDb();
        $med = (new MedicineFactory($db))->create(['name' => $name])['id'];
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $med,
            'start_date' => '2026-01-01',
            'frequency' => $frequency,
            'unit_per_dose' => 1.0,
        ]);
        (new InventoryFactory($db))->create([
            'medicine_id' => $med,
            'current_stock' => $currentStock,
            'recorded_at' => '2026-04-14 00:00:00',
        ]);

        return $med;
    }
}
