<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\ScheduleRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class ScheduleRepositoryTest extends DatabaseTestCase
{
    private ScheduleRepository $repo;
    private int $patientId;
    private int $medicineId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ScheduleRepository($this->getDb());
        $this->patientId = $this->seedPatient();
        $this->medicineId = $this->seedMedicine();
    }

    public function testCreateScheduleInsertsAndReturnsId(): void
    {
        $id = $this->repo->createSchedule([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'start_date' => '2026-04-01',
            'frequency' => '8h',
            'unit_per_dose' => 1.0,
        ]);

        $this->assertGreaterThan(0, $id);

        $row = $this->repo->getScheduleById($id);
        $this->assertNotNull($row);
        $this->assertSame('8h', $row['frequency']);
        $this->assertSame(1.0, $row['unit_per_dose']);
        $this->assertNull($row['end_date']);
    }

    public function testCreateScheduleAllowsOptionalEndDate(): void
    {
        $id = $this->repo->createSchedule([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
            'frequency' => '1d',
            'unit_per_dose' => 2.5,
        ]);

        $row = $this->repo->getScheduleById($id);
        $this->assertNotNull($row);
        $this->assertSame('2026-06-30', $row['end_date']);
        $this->assertSame(2.5, $row['unit_per_dose']);
    }

    public function testGetScheduleByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->getScheduleById(9999));
    }

    public function testGetActiveSchedulesReturnsOpenAndFutureEndDates(): void
    {
        $this->seedSchedule('2026-03-01', null, '8h');              // active, no end
        $this->seedSchedule('2026-03-01', '2026-12-31', '12h');     // active, future end
        $this->seedSchedule('2025-01-01', '2025-06-01', '1d');      // ended -- excluded

        $rows = $this->repo->getActiveSchedules($this->patientId, '2026-04-13');

        $this->assertCount(2, $rows);
        $frequencies = array_map(static fn (array $r): string => $r['frequency'], $rows);
        sort($frequencies);
        $this->assertSame(['12h', '8h'], $frequencies);
    }

    public function testGetActiveSchedulesScopesToPatient(): void
    {
        $otherPatient = $this->seedPatient('Fozzie');
        $this->seedSchedule('2026-03-01', null, '8h');

        $this->getDb()->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$otherPatient, $this->medicineId, '2026-03-01', '12h', 1.0]
        );

        $rows = $this->repo->getActiveSchedules($this->patientId, '2026-04-13');
        $this->assertCount(1, $rows);
        $this->assertSame('8h', $rows[0]['frequency']);
    }

    public function testEndScheduleSetsEndDate(): void
    {
        $id = $this->seedSchedule('2026-03-01', null, '8h');

        $this->assertTrue($this->repo->endSchedule($id, '2026-04-13'));

        $row = $this->repo->getScheduleById($id);
        $this->assertNotNull($row);
        $this->assertSame('2026-04-13', $row['end_date']);
    }

    public function testEndScheduleReturnsTrueEvenForMissingRow(): void
    {
        // SQLite's UPDATE silently touches 0 rows; we still return true because
        // the statement executed without error. Higher layers check existence.
        $this->assertTrue($this->repo->endSchedule(9999, '2026-04-13'));
    }

    private function seedPatient(string $name = 'Daisy'): int
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', [$name]);

        return $db->lastInsertId();
    }

    private function seedMedicine(): int
    {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)',
            ['Sildenafil', '20mg']
        );

        return $db->lastInsertId();
    }

    private function seedSchedule(string $start, ?string $end, string $frequency): int
    {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$this->patientId, $this->medicineId, $start, $end, $frequency, 1.0]
        );

        return $db->lastInsertId();
    }
}
