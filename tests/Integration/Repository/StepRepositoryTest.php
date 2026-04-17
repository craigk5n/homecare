<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\StepRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class StepRepositoryTest extends DatabaseTestCase
{
    private StepRepository $repo;
    private int $scheduleId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->repo = new StepRepository($db);

        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $patientId = $db->lastInsertId();
        $db->execute("INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)", ['Prednisone', '5mg']);
        $medicineId = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$patientId, $medicineId, '2026-01-01', '1d', 1.0]
        );
        $this->scheduleId = $db->lastInsertId();
    }

    public function testCreateAndRetrieve(): void
    {
        $id = $this->repo->create($this->scheduleId, '2026-01-08', 2.0, 'Week 2: increase to 10mg');

        $steps = $this->repo->getForSchedule($this->scheduleId);
        $this->assertCount(1, $steps);
        $this->assertSame($id, $steps[0]['id']);
        $this->assertSame('2026-01-08', $steps[0]['start_date']);
        $this->assertSame(2.0, $steps[0]['unit_per_dose']);
        $this->assertSame('Week 2: increase to 10mg', $steps[0]['note']);
    }

    public function testGetEffectiveStepReturnsLatestBeforeDate(): void
    {
        $this->repo->create($this->scheduleId, '2026-01-08', 2.0, 'Week 2');
        $this->repo->create($this->scheduleId, '2026-01-15', 4.0, 'Week 3');
        $this->repo->create($this->scheduleId, '2026-01-22', 2.0, 'Week 4: taper down');

        $step = $this->repo->getEffectiveStep($this->scheduleId, '2026-01-18');
        $this->assertNotNull($step);
        $this->assertSame(4.0, $step['unit_per_dose']);
        $this->assertSame('2026-01-15', $step['start_date']);
    }

    public function testGetEffectiveStepReturnsNullWhenNoSteps(): void
    {
        $this->assertNull($this->repo->getEffectiveStep($this->scheduleId, '2026-04-01'));
    }

    public function testGetEffectiveStepReturnsNullBeforeFirstStep(): void
    {
        $this->repo->create($this->scheduleId, '2026-02-01', 3.0, null);

        $this->assertNull($this->repo->getEffectiveStep($this->scheduleId, '2026-01-31'));
    }

    public function testGetEffectiveStepOnExactStartDate(): void
    {
        $this->repo->create($this->scheduleId, '2026-01-15', 4.0, 'Step 2');

        $step = $this->repo->getEffectiveStep($this->scheduleId, '2026-01-15');
        $this->assertNotNull($step);
        $this->assertSame(4.0, $step['unit_per_dose']);
    }

    public function testHasOverlapDetectsDuplicate(): void
    {
        $this->repo->create($this->scheduleId, '2026-01-08', 2.0, null);

        $this->assertTrue($this->repo->hasOverlap($this->scheduleId, '2026-01-08'));
        $this->assertFalse($this->repo->hasOverlap($this->scheduleId, '2026-01-09'));
    }

    public function testHasOverlapExcludesId(): void
    {
        $id = $this->repo->create($this->scheduleId, '2026-01-08', 2.0, null);

        $this->assertFalse($this->repo->hasOverlap($this->scheduleId, '2026-01-08', $id));
    }

    public function testDeleteRemovesStep(): void
    {
        $id = $this->repo->create($this->scheduleId, '2026-01-08', 2.0, null);
        $this->assertTrue($this->repo->delete($id));
        $this->assertSame([], $this->repo->getForSchedule($this->scheduleId));
    }

    public function testStepsOrderedByStartDateAsc(): void
    {
        $this->repo->create($this->scheduleId, '2026-01-22', 1.0, 'C');
        $this->repo->create($this->scheduleId, '2026-01-08', 3.0, 'A');
        $this->repo->create($this->scheduleId, '2026-01-15', 2.0, 'B');

        $steps = $this->repo->getForSchedule($this->scheduleId);
        $this->assertCount(3, $steps);
        $this->assertSame('2026-01-08', $steps[0]['start_date']);
        $this->assertSame('2026-01-15', $steps[1]['start_date']);
        $this->assertSame('2026-01-22', $steps[2]['start_date']);
    }
}
