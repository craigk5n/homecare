<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Report;

use HomeCare\Report\MedicationSummaryReport;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\InventoryService;
use HomeCare\Tests\Factory\InventoryFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class MedicationSummaryTest extends DatabaseTestCase
{
    private MedicationSummaryReport $report;
    private int $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->report = new MedicationSummaryReport(
            $db,
            new InventoryService(
                new InventoryRepository($db),
                new ScheduleRepository($db),
            ),
        );
        $this->patientId = (new PatientFactory($db))->create(['name' => 'Daisy'])['id'];
    }

    public function testReturnsNullForMissingPatient(): void
    {
        $this->assertNull($this->report->build(99999, '2026-04-13'));
    }

    public function testEmptyPatientProducesEmptyLists(): void
    {
        $summary = $this->report->build($this->patientId, '2026-04-13');

        $this->assertNotNull($summary);
        $this->assertSame('Daisy', $summary['patient']['name']);
        $this->assertSame('2026-04-13', $summary['today']);
        $this->assertSame([], $summary['active']);
        $this->assertSame([], $summary['discontinued']);
    }

    public function testActiveSchedulesIncludeRemainingStock(): void
    {
        $db = $this->getDb();
        $med = (new MedicineFactory($db))->create(['name' => 'Sildenafil', 'dosage' => '20mg']);
        $sched = (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $med['id'],
            'start_date' => '2026-01-01',
            'frequency' => '8h',
            'unit_per_dose' => 1.0,
        ]);
        (new InventoryFactory($db))->create([
            'medicine_id' => $med['id'],
            'current_stock' => 30.0,
            'recorded_at' => '2026-04-01 00:00:00',
        ]);

        $summary = $this->report->build($this->patientId, '2026-04-13');

        $this->assertNotNull($summary);
        $this->assertCount(1, $summary['active']);
        $this->assertSame([], $summary['discontinued']);

        $row = $summary['active'][0];
        $this->assertSame('Sildenafil', $row['medicine_name']);
        $this->assertSame('20mg', $row['dosage']);
        $this->assertSame('8h', $row['frequency']);
        $this->assertSame('2026-01-01', $row['start_date']);
        $this->assertNull($row['end_date']);
        $this->assertSame(30.0, $row['remaining_doses']);
        $this->assertSame(10, $row['remaining_days']); // 30 doses at 3/day
        $this->assertSame(30.0, $row['last_inventory']);
        $this->assertSame($sched['id'], $row['schedule_id']);
    }

    public function testFutureStartDateExcludedFromActive(): void
    {
        $db = $this->getDb();
        $med = (new MedicineFactory($db))->create();
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $med['id'],
            'start_date' => '2026-05-01',
            'frequency' => '1d',
        ]);

        $summary = $this->report->build($this->patientId, '2026-04-13');
        $this->assertNotNull($summary);
        $this->assertSame([], $summary['active']);
    }

    public function testDiscontinuedInWindowAppearsInDiscontinued(): void
    {
        $db = $this->getDb();
        $med = (new MedicineFactory($db))->create(['name' => 'Carprofen', 'dosage' => '75mg']);
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $med['id'],
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31', // ended 13 days ago
            'frequency' => '12h',
        ]);

        $summary = $this->report->build($this->patientId, '2026-04-13');
        $this->assertNotNull($summary);
        $this->assertSame([], $summary['active']);
        $this->assertCount(1, $summary['discontinued']);
        $this->assertSame('Carprofen', $summary['discontinued'][0]['medicine_name']);
        $this->assertSame('2026-03-31', $summary['discontinued'][0]['end_date']);
    }

    public function testDiscontinuedOlderThanWindowExcluded(): void
    {
        $db = $this->getDb();
        $med = (new MedicineFactory($db))->create(['name' => 'OldMed']);
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $med['id'],
            'start_date' => '2025-01-01',
            'end_date' => '2025-06-01', // 10+ months ago
            'frequency' => '1d',
        ]);

        $summary = $this->report->build($this->patientId, '2026-04-13');
        $this->assertNotNull($summary);
        $this->assertSame([], $summary['discontinued']);
    }

    public function testCustomWindowOverridesDefault(): void
    {
        $db = $this->getDb();
        $med = (new MedicineFactory($db))->create(['name' => 'Ancient']);
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $med['id'],
            'start_date' => '2024-01-01',
            'end_date' => '2025-06-01',
            'frequency' => '1d',
        ]);

        // Default 90-day window excludes; 1000-day window includes.
        $default = $this->report->build($this->patientId, '2026-04-13');
        $wide = $this->report->build($this->patientId, '2026-04-13', 1000);

        $this->assertNotNull($default);
        $this->assertNotNull($wide);
        $this->assertSame([], $default['discontinued']);
        $this->assertCount(1, $wide['discontinued']);
    }

    public function testActiveAndDiscontinuedSortedUsefully(): void
    {
        $db = $this->getDb();
        // Active meds sorted alphabetically
        foreach (['Zebra', 'Apple', 'Mango'] as $name) {
            $m = (new MedicineFactory($db))->create(['name' => $name]);
            (new ScheduleFactory($db))->create([
                'patient_id' => $this->patientId,
                'medicine_id' => $m['id'],
                'start_date' => '2026-01-01',
                'frequency' => '1d',
            ]);
        }

        $summary = $this->report->build($this->patientId, '2026-04-13');
        $this->assertNotNull($summary);
        $names = array_map(static fn(array $r): string => $r['medicine_name'], $summary['active']);
        $this->assertSame(['Apple', 'Mango', 'Zebra'], $names);
    }
}
