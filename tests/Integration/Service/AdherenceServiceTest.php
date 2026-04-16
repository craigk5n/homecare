<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\PauseRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\AdherenceService;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * End-to-end: real repositories, real SQL, real SQLite. Complements the
 * unit tests (mocked repos) by proving the SQL actually counts what the
 * pure math expects.
 */
final class AdherenceServiceTest extends DatabaseTestCase
{
    private AdherenceService $service;
    private int $scheduleId;
    private IntakeFactory $intakes;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->service = new AdherenceService(
            new ScheduleRepository($db),
            new IntakeRepository($db),
        );

        $patient = (new PatientFactory($db))->create();
        $medicine = (new MedicineFactory($db))->create();
        $this->scheduleId = (new ScheduleFactory($db))->create([
            'patient_id' => $patient['id'],
            'medicine_id' => $medicine['id'],
            'start_date' => '2026-04-01',
            'frequency' => '8h',
            'unit_per_dose' => 1.0,
        ])['id'];

        $this->intakes = new IntakeFactory($db);
    }

    public function testFullAdherenceOverOneWeek(): void
    {
        // 7 days * 3 doses/day = 21 expected. Record all 21.
        $day = new \DateTime('2026-04-01 00:00:00');
        for ($d = 0; $d < 7; $d++) {
            foreach (['08:00:00', '16:00:00', '23:59:00'] as $t) {
                $this->intakes->create([
                    'schedule_id' => $this->scheduleId,
                    'taken_time' => $day->format('Y-m-d') . ' ' . $t,
                ]);
            }
            $day->modify('+1 day');
        }

        $result = $this->service->calculateAdherence(
            $this->scheduleId,
            '2026-04-01',
            '2026-04-07'
        );

        $this->assertSame(21, $result['expected']);
        $this->assertSame(21, $result['actual']);
        $this->assertSame(100.0, $result['percentage']);
    }

    public function testPartialAdherenceAndPercentageRounding(): void
    {
        // 21 expected, 13 actual → 61.904...% → 61.9%
        for ($i = 0; $i < 13; $i++) {
            $this->intakes->create([
                'schedule_id' => $this->scheduleId,
                'taken_time' => '2026-04-0' . (($i % 7) + 1) . ' 08:00:0' . ($i % 10),
            ]);
        }

        $result = $this->service->calculateAdherence(
            $this->scheduleId,
            '2026-04-01',
            '2026-04-07'
        );

        $this->assertSame(21, $result['expected']);
        $this->assertSame(13, $result['actual']);
        $this->assertSame(61.9, $result['percentage']);
    }

    public function testIntakesOutsideWindowIgnored(): void
    {
        // One valid dose inside, one outside on each side.
        $this->intakes->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-03-31 23:00:00', // before window
        ]);
        $this->intakes->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-04-01 08:00:00', // inside
        ]);
        $this->intakes->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-04-02 00:00:01', // after end of Apr 1
        ]);

        $result = $this->service->calculateAdherence(
            $this->scheduleId,
            '2026-04-01',
            '2026-04-01'
        );

        $this->assertSame(3, $result['expected']); // 1 day * 3 doses
        $this->assertSame(1, $result['actual']);
    }

    public function testIntakesForOtherSchedulesIgnored(): void
    {
        $db = $this->getDb();
        $otherSched = (new ScheduleFactory($db))->create([
            'patient_id' => 1,
            'medicine_id' => 1,
            'start_date' => '2026-04-01',
            'frequency' => '1d',
        ])['id'];

        $this->intakes->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-04-01 08:00:00',
        ]);
        $this->intakes->create([
            'schedule_id' => $otherSched,
            'taken_time' => '2026-04-01 09:00:00',
        ]);

        $result = $this->service->calculateAdherence(
            $this->scheduleId,
            '2026-04-01',
            '2026-04-01'
        );
        $this->assertSame(1, $result['actual']);
    }

    public function testNoIntakesReturnsZeroPercent(): void
    {
        $result = $this->service->calculateAdherence(
            $this->scheduleId,
            '2026-04-01',
            '2026-04-07'
        );
        $this->assertSame(21, $result['expected']);
        $this->assertSame(0, $result['actual']);
        $this->assertSame(0.0, $result['percentage']);
    }

    public function testIntakesAfterScheduleEndDoNotInflateActual(): void
    {
        // Mirror of the "before start" case: a schedule that ended mid-
        // query must not count intakes logged after its end_date.
        $db = $this->getDb();
        $endedSched = (new ScheduleFactory($db))->create([
            'patient_id' => 1,
            'medicine_id' => 1,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-03',
            'frequency' => '1d',
            'unit_per_dose' => 1.0,
        ])['id'];

        foreach (['2026-04-01 08:00:00', '2026-04-02 08:00:00', '2026-04-03 08:00:00'] as $t) {
            $this->intakes->create(['schedule_id' => $endedSched, 'taken_time' => $t]);
        }
        // Rogue backdated intakes after end_date — must be ignored.
        foreach (['2026-04-05 08:00:00', '2026-04-06 08:00:00'] as $t) {
            $this->intakes->create(['schedule_id' => $endedSched, 'taken_time' => $t]);
        }

        $result = $this->service->calculateAdherence($endedSched, '2026-04-01', '2026-04-07');

        $this->assertSame(3, $result['expected']);
        $this->assertSame(3, $result['actual']);
        $this->assertSame(100.0, $result['percentage']);
    }

    public function testIntakesBeforeScheduleStartDoNotInflateActual(): void
    {
        // Regression guard: "Tobramycin shows 200% adherence a day after
        // starting." A schedule that begins mid-query-window must not
        // credit itself with intakes logged against the row before the
        // schedule's start_date -- that produced >100% figures when
        // caregivers backdated prior-prescription doses into the new row.
        $db = $this->getDb();
        $newSched = (new ScheduleFactory($db))->create([
            'patient_id' => 1,
            'medicine_id' => 1,
            'start_date' => '2026-04-12', // started 2 days before query end
            'frequency' => '12h',          // 2/day
            'unit_per_dose' => 1.0,
        ])['id'];

        // 4 intakes inside the effective window (2026-04-12 .. 2026-04-14
        // = 3 days * 2 doses = 6 expected).
        foreach (['2026-04-12 08:00:00', '2026-04-12 20:00:00',
                  '2026-04-13 08:00:00', '2026-04-13 20:00:00'] as $t) {
            $this->intakes->create(['schedule_id' => $newSched, 'taken_time' => $t]);
        }
        // 4 intakes recorded BEFORE the schedule started -- must be ignored.
        foreach (['2026-04-08 08:00:00', '2026-04-09 08:00:00',
                  '2026-04-10 08:00:00', '2026-04-11 08:00:00'] as $t) {
            $this->intakes->create(['schedule_id' => $newSched, 'taken_time' => $t]);
        }

        $result = $this->service->calculateAdherence($newSched, '2026-04-08', '2026-04-14');

        $this->assertSame(6, $result['expected'], 'expected clamped to effective window');
        $this->assertSame(4, $result['actual'], 'actual must exclude intakes before schedule start');
        $this->assertSame(66.7, $result['percentage']);
    }

    public function testPrnScheduleIsExcludedFromAdherence(): void
    {
        // HC-120: PRN schedules have no expected cadence, so adherence is
        // not a meaningful metric for them. The service must report zeros
        // (with coverage_days=0 so the UI renders "N/A") regardless of
        // how many intakes were recorded.
        $db = $this->getDb();
        $prnSched = (new ScheduleFactory($db))->create([
            'patient_id' => 1,
            'medicine_id' => 1,
            'start_date' => '2026-04-01',
            'is_prn' => true,
            'unit_per_dose' => 0.5,
        ])['id'];

        foreach (['2026-04-02 10:00:00', '2026-04-05 14:00:00', '2026-04-06 20:00:00'] as $t) {
            $this->intakes->create(['schedule_id' => $prnSched, 'taken_time' => $t]);
        }

        $result = $this->service->calculateAdherence($prnSched, '2026-04-01', '2026-04-07');

        $this->assertSame(0, $result['expected']);
        $this->assertSame(0, $result['actual']);
        $this->assertSame(0.0, $result['percentage']);
        $this->assertSame(0, $result['coverage_days']);
        $this->assertSame(7, $result['window_days']);
    }

    public function testPausedDaysSubtractedFromExpectedCount(): void
    {
        // HC-124: 7-day window at 8h (3 doses/day). Pause covers Apr 3-5
        // (3 days). Effective active days = 7 - 3 = 4. Expected = 4 * 3 = 12.
        $db = $this->getDb();
        $pauseRepo = new PauseRepository($db);
        $pauseRepo->create($this->scheduleId, '2026-04-03', '2026-04-05', 'Vacation');

        // Build a pause-aware service.
        $svc = new AdherenceService(
            new ScheduleRepository($db),
            new IntakeRepository($db),
            $pauseRepo,
        );

        // Record 12 intakes on the 4 active days (3/day).
        foreach (['01', '02', '06', '07'] as $day) {
            foreach (['08:00:00', '16:00:00', '23:59:00'] as $t) {
                $this->intakes->create([
                    'schedule_id' => $this->scheduleId,
                    'taken_time' => "2026-04-{$day} {$t}",
                ]);
            }
        }

        $result = $svc->calculateAdherence($this->scheduleId, '2026-04-01', '2026-04-07');

        $this->assertSame(12, $result['expected']);
        $this->assertSame(12, $result['actual']);
        $this->assertSame(100.0, $result['percentage']);
    }

    public function testOpenEndedPauseSubtractsFromExpected(): void
    {
        // HC-124: open-ended pause starting Apr 5 within a 7-day window.
        // Active days = Apr 1-4 = 4. Expected = 4 * 3 = 12.
        $db = $this->getDb();
        $pauseRepo = new PauseRepository($db);
        $pauseRepo->create($this->scheduleId, '2026-04-05', null, null);

        $svc = new AdherenceService(
            new ScheduleRepository($db),
            new IntakeRepository($db),
            $pauseRepo,
        );

        $result = $svc->calculateAdherence($this->scheduleId, '2026-04-01', '2026-04-07');

        $this->assertSame(12, $result['expected']);
    }
}
