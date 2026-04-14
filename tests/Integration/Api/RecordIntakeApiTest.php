<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Api;

use HomeCare\Api\IntakesApi;
use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * POST /api/v1/intakes.php — IntakesApi::record()
 *
 * Exercises all acceptance bullets: valid POST creates row + returns
 * 201 with id, missing schedule_id → 400, invalid schedule_id → 404,
 * viewer role → 403, response carries the created id.
 */
final class RecordIntakeApiTest extends DatabaseTestCase
{
    private IntakesApi $api;
    private int $scheduleId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->api = new IntakesApi(
            new ScheduleRepository($db),
            new IntakeRepository($db),
        );

        $patient = (new PatientFactory($db))->create();
        $med = (new MedicineFactory($db))->create();
        $this->scheduleId = (new ScheduleFactory($db))->create([
            'patient_id' => $patient['id'],
            'medicine_id' => $med['id'],
            'start_date' => '2026-01-01',
            'frequency' => '8h',
            'unit_per_dose' => 1.0,
        ])['id'];
    }

    public function testValidPostCreatesRecord(): void
    {
        $resp = $this->api->record(
            [
                'schedule_id' => $this->scheduleId,
                'taken_time' => '2026-04-14 08:00:00',
                'note' => 'with food',
            ],
            'caregiver',
        );

        $this->assertSame(201, $resp->httpStatus);
        $this->assertSame('ok', $resp->status);

        $data = $resp->data;
        $this->assertIsArray($data);
        $this->assertArrayHasKey('id', $data);
        $newId = $data['id'];
        $this->assertIsInt($newId);
        $this->assertGreaterThan(0, $newId);
        $this->assertSame($this->scheduleId, $data['schedule_id']);
        $this->assertSame('2026-04-14 08:00:00', $data['taken_time']);
        $this->assertSame('with food', $data['note']);

        // And the row actually exists in the DB.
        $rows = $this->getDb()->query(
            'SELECT id, schedule_id, taken_time, note FROM hc_medicine_intake WHERE id = ?',
            [$newId],
        );
        $this->assertCount(1, $rows);
        $this->assertSame('2026-04-14 08:00:00', $rows[0]['taken_time']);
        $this->assertSame('with food', $rows[0]['note']);
    }

    public function testOmittedTakenTimeDefaultsToNow(): void
    {
        $before = new \DateTimeImmutable();
        $resp = $this->api->record(
            ['schedule_id' => $this->scheduleId],
            'caregiver',
        );
        $after = new \DateTimeImmutable();

        $this->assertSame(201, $resp->httpStatus);
        $data = $resp->data;
        $this->assertIsArray($data);
        $newId = $data['id'];
        $this->assertIsInt($newId);

        // The stored taken_time comes from the DB default CURRENT_TIMESTAMP
        // since we passed null through to recordIntake. Fetch and verify
        // it falls within the test execution window.
        $rows = $this->getDb()->query(
            'SELECT taken_time FROM hc_medicine_intake WHERE id = ?',
            [$newId],
        );
        $this->assertCount(1, $rows);
        $taken = new \DateTimeImmutable((string) $rows[0]['taken_time']);
        $this->assertGreaterThanOrEqual($before->getTimestamp() - 1, $taken->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp() + 1, $taken->getTimestamp());
    }

    public function testMissingScheduleIdReturns400(): void
    {
        $resp = $this->api->record([], 'caregiver');
        $this->assertSame(400, $resp->httpStatus);
        $this->assertSame('error', $resp->status);
        $this->assertStringContainsString('schedule_id', (string) $resp->message);
    }

    public function testInvalidScheduleIdShapeReturns400(): void
    {
        $this->assertSame(400, $this->api->record(['schedule_id' => 'abc'], 'caregiver')->httpStatus);
        $this->assertSame(400, $this->api->record(['schedule_id' => 0], 'caregiver')->httpStatus);
        $this->assertSame(400, $this->api->record(['schedule_id' => -1], 'caregiver')->httpStatus);
    }

    public function testUnknownScheduleReturns404(): void
    {
        $resp = $this->api->record(['schedule_id' => 9999], 'caregiver');
        $this->assertSame(404, $resp->httpStatus);
        $this->assertStringContainsString('not found', (string) $resp->message);
    }

    public function testViewerRoleReturns403(): void
    {
        $resp = $this->api->record(
            ['schedule_id' => $this->scheduleId],
            'viewer',
        );

        $this->assertSame(403, $resp->httpStatus);
        $this->assertSame('error', $resp->status);
        $this->assertStringContainsString('caregiver', (string) $resp->message);

        // And no row should have been inserted.
        $rows = $this->getDb()->query(
            'SELECT COUNT(*) AS n FROM hc_medicine_intake WHERE schedule_id = ?',
            [$this->scheduleId],
        );
        $this->assertSame(0, (int) $rows[0]['n']);
    }

    public function testAdminCanRecord(): void
    {
        // Roles above caregiver also qualify.
        $resp = $this->api->record(
            ['schedule_id' => $this->scheduleId],
            'admin',
        );
        $this->assertSame(201, $resp->httpStatus);
    }

    public function testUnknownRoleReturns403(): void
    {
        $resp = $this->api->record(
            ['schedule_id' => $this->scheduleId],
            'superuser', // not a valid role string
        );
        $this->assertSame(403, $resp->httpStatus);
    }

    public function testInvalidTakenTimeFormatReturns400(): void
    {
        $resp = $this->api->record(
            [
                'schedule_id' => $this->scheduleId,
                'taken_time' => 'last tuesday',
            ],
            'caregiver',
        );
        $this->assertSame(400, $resp->httpStatus);
        $this->assertStringContainsString('YYYY-MM-DD', (string) $resp->message);
    }

    public function testResponseJsonEnvelopeShape(): void
    {
        $resp = $this->api->record(
            ['schedule_id' => $this->scheduleId],
            'caregiver',
        );

        $decoded = json_decode($resp->toJson(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertIsArray($decoded['data']);
        $this->assertArrayHasKey('id', $decoded['data']);
    }
}
