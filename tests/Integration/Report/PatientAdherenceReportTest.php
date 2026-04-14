<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Report;

use HomeCare\Report\PatientAdherenceReport;
use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\AdherenceService;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class PatientAdherenceReportTest extends DatabaseTestCase
{
    private PatientAdherenceReport $report;
    private int $patientId;
    private int $medicineId;
    private int $scheduleId;
    private IntakeFactory $intakes;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();

        $this->report = new PatientAdherenceReport(
            $db,
            new AdherenceService(
                new ScheduleRepository($db),
                new IntakeRepository($db),
            ),
        );

        $this->patientId = (new PatientFactory($db))->create(['name' => 'Daisy'])['id'];
        $this->medicineId = (new MedicineFactory($db))
            ->create(['name' => 'Sildenafil', 'dosage' => '20mg'])['id'];
        $this->scheduleId = (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'start_date' => '2026-01-01',
            'frequency' => '1d',
            'unit_per_dose' => 1.0,
        ])['id'];

        $this->intakes = new IntakeFactory($db);
    }

    public function testEmptyPatientProducesEmptyList(): void
    {
        $empty = (new PatientFactory($this->getDb()))->create(['name' => 'Ghost'])['id'];
        $this->assertSame([], $this->report->build($empty, '2026-04-14'));
    }

    public function testExcludesSchedulesThatEndedBeforeTheDefaultNinetyDayWindow(): void
    {
        $db = $this->getDb();
        $m = (new MedicineFactory($db))->create(['name' => 'Ancient'])['id'];
        // Ended ~10 months before today's 2026-04-14; even the 90-day
        // default filter doesn't reach back far enough to include it.
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $m,
            'start_date' => '2025-01-01',
            'end_date' => '2025-06-01',
            'frequency' => '1d',
        ]);

        $rows = $this->report->build($this->patientId, '2026-04-14');
        // Only the seeded Sildenafil schedule should appear.
        $this->assertCount(1, $rows);
        $this->assertSame('Sildenafil', $rows[0]['medicine_name']);
    }

    public function testCustomFilterWindowIncludesDiscontinuedSchedulesThatOverlap(): void
    {
        // Regression: the 4-week Tobramycin course from autumn 2025
        // used to be filtered out even when the caller explicitly asked
        // about that window, because the SQL filter required active-today.
        $db = $this->getDb();
        $m = (new MedicineFactory($db))->create(['name' => 'Tobramycin'])['id'];
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $m,
            'start_date' => '2025-10-01',
            'end_date' => '2025-10-28',
            'frequency' => '12h',
        ]);

        $rows = $this->report->build(
            $this->patientId,
            '2026-04-14',
            '2025-09-01',
            '2025-12-31',
        );

        $names = array_map(static fn (array $r): string => $r['medicine_name'], $rows);
        $this->assertContains('Tobramycin', $names);
    }

    public function testDefaultRowsReportZeroCoverageForRecentlyDiscontinuedScheduleOutsideSubWindows(): void
    {
        // A schedule that ended ~60 days ago is inside the 90-day
        // default filter (so it appears in the rows), but its coverage
        // against the 7-day and 30-day sub-windows is zero. The UI uses
        // coverage_days == 0 to render "N/A" instead of a misleading 0%.
        $db = $this->getDb();
        $m = (new MedicineFactory($db))->create(['name' => 'Finished'])['id'];
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $m,
            'start_date' => '2026-01-01',
            'end_date' => '2026-02-13', // 60 days before 2026-04-14
            'frequency' => '1d',
        ]);

        $rows = $this->report->build($this->patientId, '2026-04-14');
        $finished = null;
        foreach ($rows as $r) {
            if ($r['medicine_name'] === 'Finished') {
                $finished = $r;
                break;
            }
        }
        $this->assertNotNull($finished, 'Finished schedule should appear in the 90-day default filter.');
        $this->assertSame(0, $finished['adherence_7d']['coverage_days']);
        $this->assertSame(0, $finished['adherence_30d']['coverage_days']);
        $this->assertGreaterThan(0, $finished['adherence_90d']['coverage_days']);
    }

    public function testSingleActiveScheduleReportsAllThreeWindows(): void
    {
        // 1d frequency → 1 expected/day.
        // Target: 7-day = 7/7 (100%), 30-day = 21/30 (70%), 90-day = 45/90 (50%).
        //
        // Windows (from today backwards):
        //   7d window  = days 0..6 ago (7 days)
        //   30d window = days 0..29 ago (30 days)
        //   90d window = days 0..89 ago (90 days)
        $today = '2026-04-14';

        // 7 intakes in days 0..6 ago → inside 7d (and 30d and 90d).
        for ($i = 0; $i < 7; $i++) {
            $this->intakes->create([
                'schedule_id' => $this->scheduleId,
                'taken_time' => date('Y-m-d', strtotime("{$today} -{$i} days")) . ' 08:00:00',
            ]);
        }
        // 14 more in days 7..20 ago → inside 30d but outside 7d. Running total 21 in 30d.
        for ($i = 7; $i < 21; $i++) {
            $this->intakes->create([
                'schedule_id' => $this->scheduleId,
                'taken_time' => date('Y-m-d', strtotime("{$today} -{$i} days")) . ' 08:00:00',
            ]);
        }
        // 24 more in days 30..53 ago → inside 90d but outside 30d. Running total 45 in 90d.
        for ($i = 30; $i < 54; $i++) {
            $this->intakes->create([
                'schedule_id' => $this->scheduleId,
                'taken_time' => date('Y-m-d', strtotime("{$today} -{$i} days")) . ' 08:00:00',
            ]);
        }

        $rows = $this->report->build($this->patientId, $today);
        $this->assertCount(1, $rows);

        $r = $rows[0];
        $this->assertSame(7, $r['adherence_7d']['expected']);
        $this->assertSame(7, $r['adherence_7d']['actual']);
        $this->assertSame(100.0, $r['adherence_7d']['percentage']);

        $this->assertSame(30, $r['adherence_30d']['expected']);
        $this->assertSame(21, $r['adherence_30d']['actual']);
        $this->assertSame(70.0, $r['adherence_30d']['percentage']);

        $this->assertSame(90, $r['adherence_90d']['expected']);
        $this->assertSame(45, $r['adherence_90d']['actual']);
        $this->assertSame(50.0, $r['adherence_90d']['percentage']);
    }

    public function testOrdersByMedicineNameThenScheduleId(): void
    {
        $db = $this->getDb();
        foreach (['Zelda', 'Apple', 'Mango'] as $name) {
            $m = (new MedicineFactory($db))->create(['name' => $name])['id'];
            (new ScheduleFactory($db))->create([
                'patient_id' => $this->patientId,
                'medicine_id' => $m,
                'start_date' => '2026-01-01',
                'frequency' => '1d',
            ]);
        }

        $rows = $this->report->build($this->patientId, '2026-04-14');
        $names = array_map(static fn (array $r): string => $r['medicine_name'], $rows);
        // Seeded Sildenafil + three new meds; sorted.
        $this->assertSame(['Apple', 'Mango', 'Sildenafil', 'Zelda'], $names);
    }

    public function testCalculateCustomDelegatesToAdherenceService(): void
    {
        $this->intakes->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-02-15 08:00:00',
        ]);
        $result = $this->report->calculateCustom($this->scheduleId, '2026-02-15', '2026-02-15');
        $this->assertSame(1, $result['expected']);
        $this->assertSame(1, $result['actual']);
        $this->assertSame(100.0, $result['percentage']);
    }
}
