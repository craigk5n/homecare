<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\PatientRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Repository\StepRepository;
use HomeCare\Service\InventoryService;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class StepInventoryTest extends DatabaseTestCase
{
    private InventoryService $service;
    private StepRepository $steps;
    private int $patientId;
    private int $medicineId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->steps = new StepRepository($db);
        $this->service = new InventoryService(
            new InventoryRepository($db),
            new ScheduleRepository($db),
            new PatientRepository($db),
            $this->steps,
        );

        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $this->patientId = $db->lastInsertId();
        $db->execute('INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)', ['Prednisone', '5mg']);
        $this->medicineId = $db->lastInsertId();
    }

    public function testZeroStepsUsesScheduleUnitPerDose(): void
    {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$this->patientId, $this->medicineId, '2026-01-01', '1d', 1.0],
        );
        $schedId = $db->lastInsertId();

        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)',
            [$this->medicineId, 30.0, 30.0],
        );

        $report = $this->service->calculateRemaining($this->medicineId, $schedId);

        $this->assertSame(1.0, $report['unitPerDose']);
        $this->assertSame(30.0, $report['remainingDoses']);
    }

    public function testStepOverridesScheduleUnitPerDose(): void
    {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$this->patientId, $this->medicineId, '2026-01-01', '1d', 1.0],
        );
        $schedId = $db->lastInsertId();

        // Step: from Jan 8 onward, dose increases to 2.0
        $this->steps->create($schedId, '2026-01-08', 2.0, 'Week 2: increase');

        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)',
            [$this->medicineId, 20.0, 20.0],
        );

        // Today is after Jan 8, so the step's unit_per_dose (2.0) applies.
        $report = $this->service->calculateRemaining($this->medicineId, $schedId);

        $this->assertSame(2.0, $report['unitPerDose']);
        $this->assertSame(10.0, $report['remainingDoses']);
    }

    public function testMultipleStepsUsesLatestEffective(): void
    {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$this->patientId, $this->medicineId, '2026-01-01', '1d', 1.0],
        );
        $schedId = $db->lastInsertId();

        $this->steps->create($schedId, '2026-01-08', 2.0, 'Week 2');
        $this->steps->create($schedId, '2026-01-15', 4.0, 'Week 3');
        // Future step (shouldn't be effective today)
        $this->steps->create($schedId, '2099-01-01', 8.0, 'Far future');

        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)',
            [$this->medicineId, 40.0, 40.0],
        );

        $report = $this->service->calculateRemaining($this->medicineId, $schedId);

        // Today is after 2026-01-15 but before 2099-01-01, so 4.0 applies.
        $this->assertSame(4.0, $report['unitPerDose']);
        $this->assertSame(10.0, $report['remainingDoses']);
    }

    public function testStepBeforeScheduleStartFallsBackToBase(): void
    {
        $db = $this->getDb();
        // Schedule starts far in the future
        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$this->patientId, $this->medicineId, '2099-01-01', '1d', 5.0],
        );
        $schedId = $db->lastInsertId();

        // Step also far in the future, but after schedule start
        $this->steps->create($schedId, '2099-06-01', 10.0, null);

        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)',
            [$this->medicineId, 50.0, 50.0],
        );

        $report = $this->service->calculateRemaining($this->medicineId, $schedId);

        // No step is effective today → base schedule unit_per_dose (5.0) applies.
        $this->assertSame(5.0, $report['unitPerDose']);
    }

    public function testStepCombinesWithPerKgDosing(): void
    {
        $db = $this->getDb();
        // Patient weighs 4.5 kg
        $db->execute('UPDATE hc_patients SET weight_kg = ? WHERE id = ?', [4.5, $this->patientId]);

        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose, dose_basis)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$this->patientId, $this->medicineId, '2026-01-01', '1d', 1.0, 'per_kg'],
        );
        $schedId = $db->lastInsertId();

        // Step changes to 2.0 mg/kg
        $this->steps->create($schedId, '2026-01-08', 2.0, 'Increase to 2mg/kg');

        $db->execute(
            'INSERT INTO hc_medicine_inventory (medicine_id, quantity, current_stock) VALUES (?, ?, ?)',
            [$this->medicineId, 90.0, 90.0],
        );

        $report = $this->service->calculateRemaining($this->medicineId, $schedId);

        // Step gives 2.0 mg/kg * 4.5 kg = 9.0 effective per dose.
        $this->assertSame(9.0, $report['unitPerDose']);
        $this->assertSame(10.0, $report['remainingDoses']);
    }
}
