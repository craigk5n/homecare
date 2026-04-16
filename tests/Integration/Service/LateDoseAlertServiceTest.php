<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Service\LateDoseAlertLog;
use HomeCare\Service\LateDoseAlertService;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class LateDoseAlertServiceTest extends DatabaseTestCase
{
    /**
     * Seed a schedule with one recorded intake and return its id.
     */
    private function seedSchedule(
        int $patientId,
        int $medicineId,
        string $frequency,
        string $lastIntakeAt,
    ): int {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, NULL, ?, ?)',
            [$patientId, $medicineId, '2026-01-01', $frequency, 1.0]
        );
        $scheduleId = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time)
             VALUES (?, ?)',
            [$scheduleId, $lastIntakeAt]
        );

        return $scheduleId;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function seedPatientAndMedicine(): array
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $patientId = $db->lastInsertId();
        $db->execute(
            "INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)",
            ['Tobra', '2 drops']
        );
        $medicineId = $db->lastInsertId();

        return [$patientId, $medicineId];
    }

    private function service(string $now): LateDoseAlertService
    {
        return new LateDoseAlertService(
            $this->getDb(),
            new LateDoseAlertLog($this->getDb()),
            static fn (): string => $now,
        );
    }

    public function testFindsOneLateOutOfTwoSchedules(): void
    {
        [$p, $m] = $this->seedPatientAndMedicine();
        // Late: last intake 12h ago on an 8h schedule → 4h past due,
        // which is past the 60-min threshold.
        $lateId = $this->seedSchedule($p, $m, '8h', '2026-04-16 04:00:00');
        // On-time: last intake 3h ago on 8h → not even due yet.
        $onTimeId = $this->seedSchedule($p, $m, '8h', '2026-04-16 13:00:00');

        $svc = $this->service(now: '2026-04-16 16:00:00');
        $alerts = $svc->findPendingAlerts(thresholdMinutes: 60);

        $this->assertCount(1, $alerts);
        $this->assertSame($lateId, $alerts[0]->scheduleId);
        $this->assertSame('Tobra', $alerts[0]->medicineName);
        $this->assertSame('Daisy', $alerts[0]->patientName);
        $this->assertSame('2026-04-16 12:00:00', $alerts[0]->dueAt);
        // 16:00 minus 12:00 = 240 minutes late.
        $this->assertSame(240, $alerts[0]->minutesLate);
    }

    public function testRecordSentSuppressesReplayWithinSameWindow(): void
    {
        [$p, $m] = $this->seedPatientAndMedicine();
        $id = $this->seedSchedule($p, $m, '8h', '2026-04-16 04:00:00');

        $svc = $this->service(now: '2026-04-16 16:00:00');

        $alerts = $svc->findPendingAlerts(60);
        $this->assertCount(1, $alerts);
        $svc->recordSent($id, $alerts[0]->dueAt);

        // Same cron tick 30 min later → still same due instant, suppressed.
        $svc2 = $this->service(now: '2026-04-16 16:30:00');
        $this->assertSame([], $svc2->findPendingAlerts(60));
    }

    public function testReArmsAfterNextDoseLogged(): void
    {
        [$p, $m] = $this->seedPatientAndMedicine();
        $id = $this->seedSchedule($p, $m, '8h', '2026-04-16 04:00:00');
        $log = new LateDoseAlertLog($this->getDb());
        // Pretend the 12:00 miss was already alerted about.
        $log->markSent($id, '2026-04-16 12:00:00', '2026-04-16 13:00:00');

        // Caregiver logs the dose late at 16:00.
        $this->getDb()->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$id, '2026-04-16 16:00:00']
        );

        // Next cron tick: it's now 2026-04-17 01:30. The new due
        // instant is 00:00; a 60-min threshold means an alert is
        // due now. The previously-alerted 12:00 timestamp doesn't
        // suppress because it's a different due_at.
        $svc = $this->service(now: '2026-04-17 01:30:00');
        $alerts = $svc->findPendingAlerts(60);

        $this->assertCount(1, $alerts);
        $this->assertSame('2026-04-17 00:00:00', $alerts[0]->dueAt);
    }

    public function testFeatureOffReturnsNothing(): void
    {
        [$p, $m] = $this->seedPatientAndMedicine();
        $this->seedSchedule($p, $m, '8h', '2026-04-16 04:00:00');

        $svc = $this->service(now: '2026-04-16 18:00:00');

        $this->assertSame([], $svc->findPendingAlerts(0));
    }

    public function testSchedulesWithoutIntakesAreSkipped(): void
    {
        [$p, $m] = $this->seedPatientAndMedicine();
        $db = $this->getDb();
        // Schedule with NO intakes → we can't compute a due instant.
        $db->execute(
            'INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$p, $m, '2026-01-01', '8h', 1.0]
        );

        $svc = $this->service(now: '2026-04-16 18:00:00');

        $this->assertSame([], $svc->findPendingAlerts(60));
    }

    public function testInactiveSchedulesAreSkipped(): void
    {
        [$p, $m] = $this->seedPatientAndMedicine();
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$p, $m, '2024-01-01', '2024-06-01', '8h', 1.0]
        );
        $sid = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$sid, '2024-05-01 08:00:00']
        );

        $svc = $this->service(now: '2026-04-16 18:00:00');

        $this->assertSame([], $svc->findPendingAlerts(60));
    }

    public function testPrnSchedulesAreSkipped(): void
    {
        // HC-120: PRN rows have no cadence -- "late" is not a meaningful
        // state for them, so findPendingAlerts() must ignore them even
        // with intakes far in the past.
        [$p, $m] = $this->seedPatientAndMedicine();
        $db = $this->getDb();
        $db->execute(
            "INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, frequency, unit_per_dose, is_prn)
             VALUES (?, ?, ?, NULL, ?, 'Y')",
            [$p, $m, '2026-01-01', 0.5]
        );
        $sid = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$sid, '2026-04-01 08:00:00']
        );

        $svc = $this->service(now: '2026-04-16 18:00:00');

        $this->assertSame([], $svc->findPendingAlerts(60));
    }

    public function testPausedSchedulesAreSkipped(): void
    {
        // HC-124: a paused schedule should not trigger a late-dose alert
        // even if the last intake is far in the past.
        [$p, $m] = $this->seedPatientAndMedicine();
        $id = $this->seedSchedule($p, $m, '8h', '2026-04-16 04:00:00');

        $db = $this->getDb();
        $db->execute(
            "INSERT INTO hc_schedule_pauses (schedule_id, start_date, end_date, reason)
             VALUES (?, '2026-04-16', NULL, 'Vet visit')",
            [$id]
        );

        $svc = $this->service(now: '2026-04-16 18:00:00');

        $this->assertSame([], $svc->findPendingAlerts(60));
    }

    public function testLogMarksPersistAcrossCalls(): void
    {
        [$p, $m] = $this->seedPatientAndMedicine();
        $id = $this->seedSchedule($p, $m, '8h', '2026-04-16 04:00:00');

        $log = new LateDoseAlertLog($this->getDb());

        $log->markSent($id, '2026-04-16 12:00:00', '2026-04-16 13:00:00');
        $this->assertSame('2026-04-16 12:00:00', $log->lastDueAt($id));

        // Upsert: second call replaces.
        $log->markSent($id, '2026-04-17 00:00:00', '2026-04-17 01:00:00');
        $this->assertSame('2026-04-17 00:00:00', $log->lastDueAt($id));
    }
}
