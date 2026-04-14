<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\IntakeRepository;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class IntakeRepositoryTest extends DatabaseTestCase
{
    private IntakeRepository $repo;
    private int $scheduleId;
    private int $otherScheduleId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->repo = new IntakeRepository($db);

        $patient = (new PatientFactory($db))->create();
        $medicine = (new MedicineFactory($db))->create();
        $schedules = new ScheduleFactory($db);

        $this->scheduleId = $schedules->create([
            'patient_id' => $patient['id'],
            'medicine_id' => $medicine['id'],
            'frequency' => '8h',
            'unit_per_dose' => 1.0,
        ])['id'];

        $this->otherScheduleId = $schedules->create([
            'patient_id' => $patient['id'],
            'medicine_id' => $medicine['id'],
            'start_date' => '2026-04-01',
            'frequency' => '12h',
            'unit_per_dose' => 2.0,
        ])['id'];
    }

    public function testRecordIntakeWithExplicitTakenTime(): void
    {
        $id = $this->repo->recordIntake($this->scheduleId, '2026-04-13 08:00:00', 'with food');

        $this->assertGreaterThan(0, $id);

        $rows = $this->repo->getIntakesSince($this->scheduleId, '2026-04-01');
        $this->assertCount(1, $rows);
        $this->assertSame('2026-04-13 08:00:00', $rows[0]['taken_time']);
        $this->assertSame('with food', $rows[0]['note']);
    }

    public function testRecordIntakeDefaultsTakenTimeToNow(): void
    {
        $id = $this->repo->recordIntake($this->scheduleId);
        $this->assertGreaterThan(0, $id);

        $rows = $this->repo->getIntakesSince($this->scheduleId, '1970-01-01');
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]['taken_time']);
    }

    public function testGetIntakesSinceFiltersByDate(): void
    {
        $this->repo->recordIntake($this->scheduleId, '2026-03-01 08:00:00');
        $this->repo->recordIntake($this->scheduleId, '2026-04-10 08:00:00');
        $this->repo->recordIntake($this->scheduleId, '2026-04-12 08:00:00');

        $rows = $this->repo->getIntakesSince($this->scheduleId, '2026-04-01');
        $this->assertCount(2, $rows);
    }

    public function testCountIntakesSinceMatchesRowCount(): void
    {
        $this->repo->recordIntake($this->scheduleId, '2026-04-10 08:00:00');
        $this->repo->recordIntake($this->scheduleId, '2026-04-11 08:00:00');
        $this->repo->recordIntake($this->scheduleId, '2026-04-12 08:00:00');

        $this->assertSame(3, $this->repo->countIntakesSince($this->scheduleId, '2026-04-01'));
        $this->assertSame(0, $this->repo->countIntakesSince($this->scheduleId, '2026-05-01'));
    }

    public function testReassignIntakesMovesRowsAndReturnsCount(): void
    {
        $this->repo->recordIntake($this->scheduleId, '2026-04-10 08:00:00');
        $this->repo->recordIntake($this->scheduleId, '2026-04-12 08:00:00');
        $this->repo->recordIntake($this->scheduleId, '2026-03-01 08:00:00'); // before cutoff

        $moved = $this->repo->reassignIntakes(
            $this->scheduleId,
            $this->otherScheduleId,
            '2026-04-01'
        );

        $this->assertSame(2, $moved);
        $this->assertSame(1, $this->repo->countIntakesSince($this->scheduleId, '1970-01-01'));
        $this->assertSame(2, $this->repo->countIntakesSince($this->otherScheduleId, '1970-01-01'));
    }

    public function testReassignIntakesReturnsZeroWhenNothingMatches(): void
    {
        $moved = $this->repo->reassignIntakes($this->scheduleId, $this->otherScheduleId, '2099-01-01');
        $this->assertSame(0, $moved);
    }
}
