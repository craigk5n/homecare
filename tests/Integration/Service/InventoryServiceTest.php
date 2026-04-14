<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\InventoryService;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Exercises the full stack: real repositories, real SQLite, real math.
 *
 * Complements the unit tests in tests/Unit/Service/InventoryServiceTest.php
 * by proving the SQL assumptions match the pure-math assumptions.
 */
final class InventoryServiceTest extends DatabaseTestCase
{
    private InventoryService $service;
    private int $medicineId;
    private int $scheduleId;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->getDb();
        $this->service = new InventoryService(
            new InventoryRepository($db),
            new ScheduleRepository($db),
        );

        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $patientId = $db->lastInsertId();
        $db->execute('INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)', ['Sildenafil', '20mg']);
        $this->medicineId = $db->lastInsertId();

        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$patientId, $this->medicineId, '2026-01-01', '8h', 1.0]
        );
        $this->scheduleId = $db->lastInsertId();
    }

    public function testEndToEndStockMathAgainstRealIntakes(): void
    {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock, recorded_at)
             VALUES (?, ?, ?, ?)',
            [$this->medicineId, 30, 30, '2026-04-01 00:00:00']
        );
        // Three intakes after the inventory snapshot, each consuming unit_per_dose 1.0.
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$this->scheduleId, '2026-04-05 08:00:00']
        );
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$this->scheduleId, '2026-04-06 08:00:00']
        );
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$this->scheduleId, '2026-04-07 08:00:00']
        );

        $report = $this->service->calculateRemaining($this->medicineId, $this->scheduleId);

        $this->assertSame('Sildenafil', $report['medicineName']);
        $this->assertSame(30.0, $report['lastInventory']);
        $this->assertSame(3.0, $report['quantityTakenSince']);
        $this->assertSame(27.0, $report['remainingDoses']); // 30 - 3 = 27, at 1 unit/dose
        $this->assertSame(9, $report['remainingDays']);     // 27 doses / (3 doses/day)
    }

    public function testReturnsEmptyShapeWhenMedicineAndScheduleAreMissing(): void
    {
        $report = $this->service->calculateRemaining(9999, 9999);

        $this->assertSame('', $report['medicineName']);
        $this->assertNull($report['lastInventory']);
        $this->assertSame(0.0, $report['remainingDoses']);
        $this->assertSame(0, $report['remainingDays']);
    }

    public function testConsumptionScopedToSingleMedicineAcrossSchedules(): void
    {
        $db = $this->getDb();

        // A second schedule for the same medicine with a different unit_per_dose.
        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [1, $this->medicineId, '2026-04-05', '12h', 2.0]
        );
        $secondSchedule = $db->lastInsertId();

        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock, recorded_at)
             VALUES (?, ?, ?, ?)',
            [$this->medicineId, 20, 20, '2026-04-01 00:00:00']
        );

        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$this->scheduleId, '2026-04-03 08:00:00']    // 1 unit
        );
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$secondSchedule, '2026-04-06 08:00:00']      // 2 units
        );

        $report = $this->service->calculateRemaining($this->medicineId, $this->scheduleId);

        // Both schedules consume off the same medicine stock. 1 + 2 = 3 units taken.
        $this->assertSame(3.0, $report['quantityTakenSince']);
        $this->assertSame(17.0, $report['remainingDoses']); // 20 - 3 = 17, at 1 unit/dose (primary schedule)
    }
}
