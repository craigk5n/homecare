<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Api;

use HomeCare\Api\ApiResponse;
use HomeCare\Api\SchedulesApi;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Response format + data correctness for GET /api/v1/schedules.php.
 */
final class ScheduleApiTest extends DatabaseTestCase
{
    private SchedulesApi $api;
    private int $patientId;
    private int $medicineId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->api = new SchedulesApi($db, static fn(): string => '2026-04-14');

        $this->patientId = (new PatientFactory($db))->create(['name' => 'Daisy'])['id'];
        $this->medicineId = (new MedicineFactory($db))
            ->create(['name' => 'Sildenafil', 'dosage' => '20mg'])['id'];
    }

    public function testMissingPatientIdReturns400(): void
    {
        $resp = $this->api->handle([]);
        $this->assertSame(ApiResponse::STATUS_ERROR, $resp->status);
        $this->assertSame(400, $resp->httpStatus);
        $this->assertStringContainsString('patient_id', (string) $resp->message);
    }

    public function testNonPositivePatientIdReturns400(): void
    {
        $this->assertSame(400, $this->api->handle(['patient_id' => '0'])->httpStatus);
        $this->assertSame(400, $this->api->handle(['patient_id' => 'abc'])->httpStatus);
        $this->assertSame(400, $this->api->handle(['patient_id' => '-1'])->httpStatus);
    }

    public function testUnknownPatientReturns404(): void
    {
        $resp = $this->api->handle(['patient_id' => '9999']);
        $this->assertSame(ApiResponse::STATUS_ERROR, $resp->status);
        $this->assertSame(404, $resp->httpStatus);
    }

    public function testEmptyScheduleListForActivePatient(): void
    {
        $resp = $this->api->handle(['patient_id' => (string) $this->patientId]);

        $this->assertSame(ApiResponse::STATUS_OK, $resp->status);
        $this->assertSame(200, $resp->httpStatus);
        $this->assertSame([], $resp->data);
    }

    public function testActiveScheduleAppearsInResponse(): void
    {
        $db = $this->getDb();
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'start_date' => '2026-01-01',
            'frequency' => '8h',
            'unit_per_dose' => 1.5,
        ]);

        $resp = $this->api->handle(['patient_id' => (string) $this->patientId]);

        $this->assertSame(200, $resp->httpStatus);
        $data = $resp->data;
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $row = $data[0];
        $this->assertIsArray($row);
        $this->assertSame($this->patientId, $row['patient_id']);
        $this->assertSame($this->medicineId, $row['medicine_id']);
        $this->assertSame('Sildenafil', $row['medicine_name']);
        $this->assertSame('20mg', $row['medicine_dosage']);
        $this->assertSame('8h', $row['frequency']);
        $this->assertSame(1.5, $row['unit_per_dose']);
        $this->assertSame('2026-01-01', $row['start_date']);
        $this->assertNull($row['end_date']);
    }

    public function testEndedScheduleExcluded(): void
    {
        $db = $this->getDb();
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31', // before today=2026-04-14
            'frequency' => '12h',
        ]);
        $resp = $this->api->handle(['patient_id' => (string) $this->patientId]);
        $this->assertSame([], $resp->data);
    }

    public function testFutureScheduleExcluded(): void
    {
        $db = $this->getDb();
        (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'start_date' => '2026-05-01', // future
            'frequency' => '1d',
        ]);
        $resp = $this->api->handle(['patient_id' => (string) $this->patientId]);
        $this->assertSame([], $resp->data);
    }

    public function testJsonEnvelopeShape(): void
    {
        $resp = $this->api->handle(['patient_id' => (string) $this->patientId]);
        $json = $resp->toJson();

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('data', $decoded);
    }

    public function testErrorEnvelopeShape(): void
    {
        $resp = $this->api->handle([]);
        $decoded = json_decode($resp->toJson(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['status']);
        $this->assertArrayHasKey('message', $decoded);
    }
}
