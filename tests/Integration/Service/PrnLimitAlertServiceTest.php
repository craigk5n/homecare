<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\PrnLimitAlertLog;
use HomeCare\Service\PrnLimitAlertService;
use HomeCare\Service\PrnLimitService;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class PrnLimitAlertServiceTest extends DatabaseTestCase
{
    private IntakeRepository $intakes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->intakes = new IntakeRepository($this->getDb());
    }

    private function service(string $now): PrnLimitAlertService
    {
        $db = $this->getDb();

        return new PrnLimitAlertService(
            $db,
            new PrnLimitService(new ScheduleRepository($db), $this->intakes),
            new PrnLimitAlertLog($db),
            static fn(): string => $now,
        );
    }

    /** Distinct dosage per seeded medicine: (name, dosage) is unique (migration 035). */
    private int $medicineSeq = 0;

    private function seedPrn(?int $max, ?string $endDate = null, string $isPrn = 'Y'): int
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $patientId = $db->lastInsertId();
        $db->execute('INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)', ['Gabapentin', (100 + $this->medicineSeq++) . 'mg']);
        $medicineId = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose, is_prn, max_doses_per_24h)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientId, $medicineId, '2026-01-01', $endDate, $isPrn === 'Y' ? null : '8h', 1.0, $isPrn, $max],
        );

        return $db->lastInsertId();
    }

    public function testNoAlertWhileUnderLimit(): void
    {
        $id = $this->seedPrn(3);
        $this->intakes->recordIntake($id, '2026-04-16 08:00:00');
        $this->intakes->recordIntake($id, '2026-04-16 12:00:00');

        $this->assertSame([], $this->service('2026-04-16 12:01:00')->findPendingAlerts());
    }

    public function testAlertsWhenDoseReachesLimit(): void
    {
        $id = $this->seedPrn(2);
        $this->intakes->recordIntake($id, '2026-04-16 08:00:00');
        $this->intakes->recordIntake($id, '2026-04-16 12:00:00');

        $alerts = $this->service('2026-04-16 12:01:00')->findPendingAlerts();

        $this->assertCount(1, $alerts);
        $a = $alerts[0];
        $this->assertSame($id, $a->scheduleId);
        $this->assertSame('reached', $a->level);
        $this->assertSame('Gabapentin', $a->medicineName);
        $this->assertSame('Daisy', $a->patientName);
        $this->assertSame(2, $a->dosesInWindow);
        $this->assertSame(2, $a->maxDoses);
        $this->assertSame('2026-04-16 12:00:00', $a->lastDoseAt);
        $this->assertSame('2026-04-17 08:00:00', $a->nextAllowedAt);
    }

    public function testRecordSentSuppressesReplayThenEscalationReArms(): void
    {
        $id = $this->seedPrn(2);
        $this->intakes->recordIntake($id, '2026-04-16 08:00:00');
        $this->intakes->recordIntake($id, '2026-04-16 12:00:00');

        $svc = $this->service('2026-04-16 12:01:00');
        $alerts = $svc->findPendingAlerts();
        $this->assertCount(1, $alerts);
        $svc->recordSent($alerts[0]);

        // Next cron tick: same window, nothing new.
        $this->assertSame([], $this->service('2026-04-16 12:02:00')->findPendingAlerts());

        // An over-limit dose escalates to "exceeded".
        $this->intakes->recordIntake($id, '2026-04-16 13:00:00');
        $alerts = $this->service('2026-04-16 13:01:00')->findPendingAlerts();
        $this->assertCount(1, $alerts);
        $this->assertSame('exceeded', $alerts[0]->level);
        $this->assertSame(3, $alerts[0]->dosesInWindow);
    }

    public function testUnsentAlertKeepsFiring(): void
    {
        // Caller only records after a successful dispatch; a failed
        // push must retry on the next tick.
        $id = $this->seedPrn(1);
        $this->intakes->recordIntake($id, '2026-04-16 12:00:00');

        $this->assertCount(1, $this->service('2026-04-16 12:01:00')->findPendingAlerts());
        $this->assertCount(1, $this->service('2026-04-16 12:02:00')->findPendingAlerts());
    }

    public function testNoAlertOnceDosesAgeOutOfWindow(): void
    {
        // Cron was down; by the time it runs the window has cleared.
        $id = $this->seedPrn(1);
        $this->intakes->recordIntake($id, '2026-04-15 12:00:00');

        $this->assertSame([], $this->service('2026-04-16 12:00:00')->findPendingAlerts());
    }

    public function testSkipsUnlimitedEndedAndFixedCadenceSchedules(): void
    {
        $unlimited = $this->seedPrn(null);
        $ended = $this->seedPrn(1, '2026-04-01');
        $fixed = $this->seedPrn(1, null, 'N');
        foreach ([$unlimited, $ended, $fixed] as $id) {
            $this->intakes->recordIntake($id, '2026-04-16 12:00:00');
            $this->intakes->recordIntake($id, '2026-04-16 12:00:00');
        }

        $this->assertSame([], $this->service('2026-04-16 12:01:00')->findPendingAlerts());
    }

    public function testFutureDatedIntakesDoNotTriggerEarly(): void
    {
        $id = $this->seedPrn(1);
        $this->intakes->recordIntake($id, '2026-04-16 18:00:00');

        $this->assertSame([], $this->service('2026-04-16 12:00:00')->findPendingAlerts());
    }

    public function testNewEpisodeNextDayAlertsAgain(): void
    {
        $id = $this->seedPrn(1);
        $this->intakes->recordIntake($id, '2026-04-16 08:00:00');
        $svc = $this->service('2026-04-16 08:01:00');
        $alerts = $svc->findPendingAlerts();
        $this->assertCount(1, $alerts);
        $svc->recordSent($alerts[0]);

        $this->intakes->recordIntake($id, '2026-04-17 09:00:00');
        $alerts = $this->service('2026-04-17 09:01:00')->findPendingAlerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('reached', $alerts[0]->level);
        $this->assertSame('2026-04-17 09:00:00', $alerts[0]->lastDoseAt);
    }

    public function testLogUpsertsOneRowPerSchedule(): void
    {
        $log = new PrnLimitAlertLog($this->getDb());
        $this->assertNull($log->lastAlert(5));

        $log->markSent(5, '2026-04-16 08:00:00', 'reached', '2026-04-16 08:01:00');
        $log->markSent(5, '2026-04-16 09:00:00', 'exceeded', '2026-04-16 09:01:00');

        $this->assertSame(
            ['dose_at' => '2026-04-16 09:00:00', 'level' => 'exceeded'],
            $log->lastAlert(5),
        );
        $rows = $this->getDb()->query('SELECT COUNT(*) AS n FROM hc_prn_limit_alert_log', []);
        $this->assertSame(1, (int) $rows[0]['n']);
    }
}
