<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\PauseRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class PauseRepositoryTest extends DatabaseTestCase
{
    private PauseRepository $repo;
    private int $scheduleId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->repo = new PauseRepository($db);

        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $patientId = $db->lastInsertId();
        $db->execute('INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)', ['Aspirin', '81mg']);
        $medicineId = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$patientId, $medicineId, '2026-01-01', '1d', 1.0],
        );
        $this->scheduleId = $db->lastInsertId();
    }

    public function testCreateAndRetrieve(): void
    {
        $id = $this->repo->create($this->scheduleId, '2026-04-10', '2026-04-12', 'Groomer visit');

        $this->assertGreaterThan(0, $id);

        $pauses = $this->repo->getForSchedule($this->scheduleId);
        $this->assertCount(1, $pauses);
        $this->assertSame($this->scheduleId, $pauses[0]['schedule_id']);
        $this->assertSame('2026-04-10', $pauses[0]['start_date']);
        $this->assertSame('2026-04-12', $pauses[0]['end_date']);
        $this->assertSame('Groomer visit', $pauses[0]['reason']);
    }

    public function testCreateOpenEndedPause(): void
    {
        $this->repo->create($this->scheduleId, '2026-04-15', null, 'Vacation');

        $pauses = $this->repo->getForSchedule($this->scheduleId);
        $this->assertCount(1, $pauses);
        $this->assertNull($pauses[0]['end_date']);
    }

    public function testIsPausedOnReturnsTrueWithinRange(): void
    {
        $this->repo->create($this->scheduleId, '2026-04-10', '2026-04-12', null);

        $this->assertTrue($this->repo->isPausedOn($this->scheduleId, '2026-04-10'));
        $this->assertTrue($this->repo->isPausedOn($this->scheduleId, '2026-04-11'));
        $this->assertTrue($this->repo->isPausedOn($this->scheduleId, '2026-04-12'));
    }

    public function testIsPausedOnReturnsFalseOutsideRange(): void
    {
        $this->repo->create($this->scheduleId, '2026-04-10', '2026-04-12', null);

        $this->assertFalse($this->repo->isPausedOn($this->scheduleId, '2026-04-09'));
        $this->assertFalse($this->repo->isPausedOn($this->scheduleId, '2026-04-13'));
    }

    public function testIsPausedOnOpenEndedPauseCoversAnyFutureDate(): void
    {
        $this->repo->create($this->scheduleId, '2026-04-10', null, null);

        $this->assertTrue($this->repo->isPausedOn($this->scheduleId, '2026-04-10'));
        $this->assertTrue($this->repo->isPausedOn($this->scheduleId, '2026-12-31'));
        $this->assertFalse($this->repo->isPausedOn($this->scheduleId, '2026-04-09'));
    }

    public function testCountPausedDaysSimpleRange(): void
    {
        // 3-day pause within a 7-day window
        $this->repo->create($this->scheduleId, '2026-04-10', '2026-04-12', null);

        $days = $this->repo->countPausedDaysInRange($this->scheduleId, '2026-04-08', '2026-04-14');
        $this->assertSame(3, $days);
    }

    public function testCountPausedDaysClampsToRange(): void
    {
        // Pause extends beyond the query range on both sides
        $this->repo->create($this->scheduleId, '2026-04-01', '2026-04-30', null);

        $days = $this->repo->countPausedDaysInRange($this->scheduleId, '2026-04-10', '2026-04-14');
        $this->assertSame(5, $days);
    }

    public function testCountPausedDaysOverlappingPausesNoDuplicates(): void
    {
        // Two overlapping pauses: 10-14 and 12-16. The union is 10-16 = 7 days.
        $this->repo->create($this->scheduleId, '2026-04-10', '2026-04-14', null);
        $this->repo->create($this->scheduleId, '2026-04-12', '2026-04-16', null);

        $days = $this->repo->countPausedDaysInRange($this->scheduleId, '2026-04-01', '2026-04-30');
        $this->assertSame(7, $days);
    }

    public function testCountPausedDaysOpenEndedPause(): void
    {
        $this->repo->create($this->scheduleId, '2026-04-10', null, null);

        // Open-ended pause within [Apr 8, Apr 14]: covers Apr 10-14 = 5 days.
        $days = $this->repo->countPausedDaysInRange($this->scheduleId, '2026-04-08', '2026-04-14');
        $this->assertSame(5, $days);
    }

    public function testCountPausedDaysNoPauses(): void
    {
        $days = $this->repo->countPausedDaysInRange($this->scheduleId, '2026-04-01', '2026-04-30');
        $this->assertSame(0, $days);
    }

    public function testResumeScheduleClosesActivePauses(): void
    {
        $this->repo->create($this->scheduleId, '2026-04-10', null, 'Vacation');
        $this->repo->create($this->scheduleId, '2026-04-12', null, 'Vet hold');

        $closed = $this->repo->resumeSchedule($this->scheduleId, '2026-04-15');
        $this->assertSame(2, $closed);

        $pauses = $this->repo->getForSchedule($this->scheduleId);
        foreach ($pauses as $p) {
            $this->assertSame('2026-04-15', $p['end_date']);
        }

        $this->assertFalse($this->repo->isPausedOn($this->scheduleId, '2026-04-16'));
    }

    public function testDeletePause(): void
    {
        $id = $this->repo->create($this->scheduleId, '2026-04-10', '2026-04-12', null);
        $this->assertTrue($this->repo->delete($id));
        $this->assertSame([], $this->repo->getForSchedule($this->scheduleId));
    }
}
