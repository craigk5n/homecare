<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Service\EventStreamService;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class EventStreamServiceTest extends DatabaseTestCase
{
    private EventStreamService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EventStreamService($this->getDb());
    }

    public function testPollReturnsNewEventsAfterSinceId(): void
    {
        $id1 = $this->seedAuditEvent('intake.recorded', 'schedule', 1);
        $id2 = $this->seedAuditEvent('schedule.created', 'schedule', 2);

        $events = $this->service->poll(0);

        $this->assertCount(2, $events);
        $this->assertSame($id1, $events[0]['id']);
        $this->assertSame($id2, $events[1]['id']);
    }

    public function testPollRespecsSinceId(): void
    {
        $id1 = $this->seedAuditEvent('intake.recorded', 'schedule', 1);
        $id2 = $this->seedAuditEvent('schedule.created', 'schedule', 2);

        $events = $this->service->poll($id1);

        $this->assertCount(1, $events);
        $this->assertSame($id2, $events[0]['id']);
    }

    public function testPollReturnsEmptyWhenNoNewEvents(): void
    {
        $id = $this->seedAuditEvent('intake.recorded', 'schedule', 1);

        $events = $this->service->poll($id);

        $this->assertSame([], $events);
    }

    public function testPollFiltersToPatientSchedules(): void
    {
        $patientId = $this->seedPatient('Daisy');
        $otherPatientId = $this->seedPatient('Fozzie');
        $medId = $this->seedMedicine();
        $scheduleId = $this->seedSchedule($patientId, $medId);
        $otherScheduleId = $this->seedSchedule($otherPatientId, $medId);

        $this->seedAuditEvent('schedule.updated', 'schedule', $scheduleId);
        $this->seedAuditEvent('schedule.updated', 'schedule', $otherScheduleId);

        $events = $this->service->poll(0, $patientId);

        $this->assertCount(1, $events);
        $this->assertSame($scheduleId, $events[0]['entity_id']);
    }

    public function testPollIncludesPatientEntityForCorrectPatient(): void
    {
        $patientId = $this->seedPatient('Daisy');

        $this->seedAuditEvent('patient.updated', 'patient', $patientId);
        $this->seedAuditEvent('patient.updated', 'patient', 9999);

        $events = $this->service->poll(0, $patientId);

        $this->assertCount(1, $events);
        $this->assertSame($patientId, $events[0]['entity_id']);
    }

    public function testPollIncludesIntakeNoteInventoryForAnyPatient(): void
    {
        $patientId = $this->seedPatient('Daisy');

        $this->seedAuditEvent('intake.recorded', 'intake', 100);
        $this->seedAuditEvent('note.created', 'note', 200);
        $this->seedAuditEvent('inventory.refilled', 'inventory', 300);

        $events = $this->service->poll(0, $patientId);

        $this->assertCount(3, $events);
    }

    public function testPollExcludesUnrelatedEntityTypes(): void
    {
        $patientId = $this->seedPatient('Daisy');

        $this->seedAuditEvent('user.logged_in', 'user', 1);
        $this->seedAuditEvent('config.changed', 'config', 1);

        $events = $this->service->poll(0, $patientId);

        $this->assertSame([], $events);
    }

    public function testPollRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedAuditEvent('intake.recorded', 'schedule', $i + 1);
        }

        $events = $this->service->poll(0, null, 3);

        $this->assertCount(3, $events);
    }

    public function testEventFormat(): void
    {
        $this->seedAuditEvent('intake.recorded', 'schedule', 42, 'alice');

        $events = $this->service->poll(0);

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertGreaterThan(0, $event['id']);
        $this->assertSame('intake.recorded', $event['action']);
        $this->assertSame('schedule', $event['entity_type']);
        $this->assertSame(42, $event['entity_id']);
        $this->assertSame('alice', $event['user_login']);
        $this->assertNotEmpty($event['created_at']);
    }

    // Concurrent caregivers scenario
    public function testConcurrentCaregiversSeeEachOthersEvents(): void
    {
        $patientId = $this->seedPatient('Daisy');
        $medId = $this->seedMedicine();
        $scheduleId = $this->seedSchedule($patientId, $medId);

        // Caregiver A records an intake
        $eventA = $this->seedAuditEvent('intake.recorded', 'intake', $scheduleId, 'caregiverA');

        // Caregiver B polls and should see A's event
        $eventsB = $this->service->poll(0, $patientId);
        $this->assertCount(1, $eventsB);
        $this->assertSame('caregiverA', $eventsB[0]['user_login']);

        // Caregiver B records an intake
        $eventB = $this->seedAuditEvent('intake.recorded', 'intake', $scheduleId, 'caregiverB');

        // Caregiver A polls from after their own event and sees B's
        $eventsA = $this->service->poll($eventA, $patientId);
        $this->assertCount(1, $eventsA);
        $this->assertSame('caregiverB', $eventsA[0]['user_login']);
    }

    // -- Seed helpers --

    private function seedAuditEvent(string $action, string $entityType, ?int $entityId, string $user = 'test'): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_audit_log (user_login, action, entity_type, entity_id, created_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'))',
            [$user, $action, $entityType, $entityId],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedPatient(string $name): int
    {
        $this->getDb()->execute('INSERT INTO hc_patients (name) VALUES (?)', [$name]);

        return $this->getDb()->lastInsertId();
    }

    private function seedMedicine(): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)',
            ['TestMed', '10mg'],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedSchedule(int $patientId, int $medicineId): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$patientId, $medicineId, '2026-01-01', '8h', 1.0],
        );

        return $this->getDb()->lastInsertId();
    }
}
