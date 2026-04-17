<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\InventoryRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class InventoryRepositoryTest extends DatabaseTestCase
{
    private InventoryRepository $repo;
    private int $medicineId;
    private int $scheduleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new InventoryRepository($this->getDb());

        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $patientId = $db->lastInsertId();
        $db->execute('INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)', ['Sildenafil', '20mg']);
        $this->medicineId = $db->lastInsertId();

        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$patientId, $this->medicineId, '2026-01-01', '8h', 1.5],
        );
        $this->scheduleId = $db->lastInsertId();
    }

    public function testGetLatestStockReturnsNullWhenNoInventory(): void
    {
        $this->assertNull($this->repo->getLatestStock($this->medicineId));
    }

    public function testGetLatestStockReturnsMostRecentRow(): void
    {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock, recorded_at)
             VALUES (?, ?, ?, ?)',
            [$this->medicineId, 30, 30, '2026-03-01 10:00:00'],
        );
        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock, recorded_at)
             VALUES (?, ?, ?, ?)',
            [$this->medicineId, 60, 60, '2026-04-01 10:00:00'],
        );
        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock, recorded_at)
             VALUES (?, ?, ?, ?)',
            [$this->medicineId, 45, 45, '2026-03-15 10:00:00'],
        );

        $latest = $this->repo->getLatestStock($this->medicineId);

        $this->assertNotNull($latest);
        $this->assertSame(60.0, $latest['current_stock']);
        $this->assertSame('2026-04-01 10:00:00', $latest['recorded_at']);
    }

    public function testGetTotalConsumedSinceSumsScheduleUnitPerDose(): void
    {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$this->scheduleId, '2026-04-05 08:00:00'],
        );
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$this->scheduleId, '2026-04-06 08:00:00'],
        );
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$this->scheduleId, '2026-03-01 08:00:00'],
        );

        // 2 intakes after 2026-04-01 * unit_per_dose 1.5 = 3.0
        $this->assertSame(3.0, $this->repo->getTotalConsumedSince($this->medicineId, '2026-04-01 00:00:00'));
    }

    public function testGetTotalConsumedSinceReturnsZeroWhenNoIntakes(): void
    {
        $this->assertSame(0.0, $this->repo->getTotalConsumedSince($this->medicineId, '2026-04-01 00:00:00'));
    }

    public function testGetMedicineNameReturnsNameOrNull(): void
    {
        $this->assertSame('Sildenafil', $this->repo->getMedicineName($this->medicineId));
        $this->assertNull($this->repo->getMedicineName(99999));
    }

    public function testGetTotalConsumedSinceScopesToMedicine(): void
    {
        $db = $this->getDb();
        // A schedule for a different medicine
        $db->execute('INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)', ['Other', '10mg']);
        $otherMed = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [1, $otherMed, '2026-01-01', '8h', 99.0],
        );
        $otherSchedule = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$otherSchedule, '2026-04-10 08:00:00'],
        );

        $this->assertSame(0.0, $this->repo->getTotalConsumedSince($this->medicineId, '2026-04-01 00:00:00'));
        $this->assertSame(99.0, $this->repo->getTotalConsumedSince($otherMed, '2026-04-01 00:00:00'));
    }
}
