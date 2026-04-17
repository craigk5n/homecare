<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\PatientRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\InventoryService;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class PerKgInventoryTest extends DatabaseTestCase
{
    private InventoryService $service;
    private int $patientId;
    private int $medicineId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->service = new InventoryService(
            new InventoryRepository($db),
            new ScheduleRepository($db),
            new PatientRepository($db),
        );

        $db->execute(
            "INSERT INTO hc_patients (name, species, weight_kg, weight_as_of) VALUES (?, ?, ?, ?)",
            ['Daisy', 'cat', 4.5, '2026-04-01']
        );
        $this->patientId = $db->lastInsertId();

        $db->execute(
            "INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)",
            ['Tobramycin', '2 drops']
        );
        $this->medicineId = $db->lastInsertId();
    }

    public function testPerKgDoseMultipliesUnitPerDoseByWeight(): void
    {
        $db = $this->getDb();

        // Schedule: 2 mg/kg twice daily. Patient weighs 4.5 kg.
        // Effective per-dose = 2 * 4.5 = 9.0 units.
        $db->execute(
            "INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, frequency, unit_per_dose, dose_basis)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$this->patientId, $this->medicineId, '2026-01-01', '12h', 2.0, 'per_kg']
        );
        $scheduleId = $db->lastInsertId();

        // Stock: 90 units.
        $db->execute(
            "INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)",
            [$this->medicineId, 90.0, 90.0]
        );

        $report = $this->service->calculateRemaining($this->medicineId, $scheduleId);

        // 9.0 units/dose, 90 stock, 0 consumed = 90/9 = 10 doses.
        // 12h frequency = 2 doses/day → 5 days.
        $this->assertSame(9.0, $report['unitPerDose']);
        $this->assertSame(10.0, $report['remainingDoses']);
        $this->assertSame(5, $report['remainingDays']);
        $this->assertNull($report['warning']);
    }

    public function testPerKgDoseAtDifferentWeight(): void
    {
        $db = $this->getDb();
        // Update patient to 10 kg (large cat or small dog).
        $db->execute('UPDATE hc_patients SET weight_kg = ? WHERE id = ?', [10.0, $this->patientId]);

        $db->execute(
            "INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, frequency, unit_per_dose, dose_basis)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$this->patientId, $this->medicineId, '2026-01-01', '1d', 5.0, 'per_kg']
        );
        $scheduleId = $db->lastInsertId();

        $db->execute(
            "INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)",
            [$this->medicineId, 200.0, 200.0]
        );

        $report = $this->service->calculateRemaining($this->medicineId, $scheduleId);

        // 5 mg/kg * 10 kg = 50 units/dose. 200/50 = 4 doses. 1d freq = 4 days.
        $this->assertSame(50.0, $report['unitPerDose']);
        $this->assertSame(4.0, $report['remainingDoses']);
        $this->assertSame(4, $report['remainingDays']);
    }

    public function testPerKgWithMissingWeightWarns(): void
    {
        $db = $this->getDb();
        // Patient with no weight.
        $db->execute("INSERT INTO hc_patients (name, species) VALUES (?, ?)", ['Ghost', 'cat']);
        $noWeightPatient = $db->lastInsertId();

        $db->execute(
            "INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, frequency, unit_per_dose, dose_basis)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$noWeightPatient, $this->medicineId, '2026-01-01', '8h', 3.0, 'per_kg']
        );
        $scheduleId = $db->lastInsertId();

        $db->execute(
            "INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)",
            [$this->medicineId, 30.0, 30.0]
        );

        $report = $this->service->calculateRemaining($this->medicineId, $scheduleId);

        // Falls back to raw unit_per_dose (3.0) since no weight.
        $this->assertSame(3.0, $report['unitPerDose']);
        $this->assertNotNull($report['warning']);
        $this->assertStringContainsString('weight', $report['warning']);
    }

    public function testPerKgWithZeroWeightWarns(): void
    {
        $db = $this->getDb();
        $db->execute(
            "INSERT INTO hc_patients (name, species, weight_kg) VALUES (?, ?, ?)",
            ['Tiny', 'bird', 0.0]
        );
        $zeroWeightPatient = $db->lastInsertId();

        $db->execute(
            "INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, frequency, unit_per_dose, dose_basis)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$zeroWeightPatient, $this->medicineId, '2026-01-01', '1d', 1.0, 'per_kg']
        );
        $scheduleId = $db->lastInsertId();

        $db->execute(
            "INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)",
            [$this->medicineId, 10.0, 10.0]
        );

        $report = $this->service->calculateRemaining($this->medicineId, $scheduleId);

        $this->assertSame(1.0, $report['unitPerDose']);
        $this->assertNotNull($report['warning']);
    }

    public function testFixedDoseIgnoresPatientWeight(): void
    {
        $db = $this->getDb();

        // dose_basis='fixed' should NOT multiply by weight.
        $db->execute(
            "INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, frequency, unit_per_dose, dose_basis)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$this->patientId, $this->medicineId, '2026-01-01', '8h', 1.0, 'fixed']
        );
        $scheduleId = $db->lastInsertId();

        $db->execute(
            "INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)",
            [$this->medicineId, 30.0, 30.0]
        );

        $report = $this->service->calculateRemaining($this->medicineId, $scheduleId);

        // Fixed: 1.0 units/dose regardless of weight. 30/1 = 30 doses. 8h = 3/day → 10 days.
        $this->assertSame(1.0, $report['unitPerDose']);
        $this->assertSame(30.0, $report['remainingDoses']);
        $this->assertSame(10, $report['remainingDays']);
        $this->assertNull($report['warning']);
    }
}
