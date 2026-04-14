<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Api;

use HomeCare\Api\InventoryApi;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Tests\Factory\InventoryFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class InventoryApiTest extends DatabaseTestCase
{
    private InventoryApi $api;
    private int $patientId;
    private int $medicineId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->api = new InventoryApi(
            $db,
            new InventoryRepository($db),
            static fn (): string => '2026-04-14',
        );

        $this->patientId = (new PatientFactory($db))->create()['id'];
        $this->medicineId = (new MedicineFactory($db))
            ->create(['name' => 'Sildenafil', 'dosage' => '20mg'])['id'];
    }

    public function testMissingMedicineIdReturns400(): void
    {
        $this->assertSame(400, $this->api->handle([])->httpStatus);
    }

    public function testUnknownMedicineReturns404(): void
    {
        $this->assertSame(404, $this->api->handle(['medicine_id' => '9999'])->httpStatus);
    }

    public function testNoStockNoSchedulesStillSucceeds(): void
    {
        $resp = $this->api->handle(['medicine_id' => (string) $this->medicineId]);
        $this->assertSame(200, $resp->httpStatus);
        $data = $resp->data;
        $this->assertIsArray($data);
        $this->assertSame($this->medicineId, $data['medicine_id']);
        $this->assertSame('Sildenafil', $data['medicine_name']);
        $this->assertNull($data['current_stock']);
        $this->assertSame([], $data['active_schedules']);
        $this->assertSame(0.0, $data['total_daily_consumption']);
        $this->assertNull($data['projected_days_supply']);
    }

    public function testProjectionFromStockAndActiveSchedules(): void
    {
        $db = $this->getDb();
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'start_date' => '2026-01-01',
            'frequency' => '8h', // 3 doses/day
            'unit_per_dose' => 1.0,
        ]);
        (new InventoryFactory($db))->create([
            'medicine_id' => $this->medicineId,
            'current_stock' => 30.0,
            'recorded_at' => '2026-04-01 00:00:00',
        ]);

        $resp = $this->api->handle(['medicine_id' => (string) $this->medicineId]);
        $data = $resp->data;
        $this->assertIsArray($data);

        $this->assertSame(30.0, $data['current_stock']);
        $this->assertSame(3.0, $data['total_daily_consumption']);
        $this->assertSame(10, $data['projected_days_supply']);
        $this->assertArrayHasKey('active_schedules', $data);
        $scheds = $data['active_schedules'];
        $this->assertIsArray($scheds);
        $this->assertCount(1, $scheds);
        $first = $scheds[0];
        $this->assertIsArray($first);
        $this->assertSame(3.0, $first['daily_consumption']);
    }

    public function testEndedScheduleExcludedFromProjection(): void
    {
        $db = $this->getDb();
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-01', // already ended by 2026-04-14
            'frequency' => '8h',
            'unit_per_dose' => 1.0,
        ]);
        (new InventoryFactory($db))->create([
            'medicine_id' => $this->medicineId,
            'current_stock' => 30.0,
        ]);

        $resp = $this->api->handle(['medicine_id' => (string) $this->medicineId]);
        $data = $resp->data;
        $this->assertIsArray($data);
        $this->assertSame([], $data['active_schedules']);
        $this->assertSame(0.0, $data['total_daily_consumption']);
        $this->assertNull($data['projected_days_supply']);
    }
}
