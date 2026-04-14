<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Api;

use HomeCare\Api\IntakesApi;
use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class IntakesApiTest extends DatabaseTestCase
{
    private IntakesApi $api;
    private int $scheduleId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();

        // Fixed "now" = 2026-04-14 12:00 UTC (1744632000) so days-window
        // calculations are deterministic.
        $this->api = new IntakesApi(
            new ScheduleRepository($db),
            new IntakeRepository($db),
            // Fixed "now" = 2026-04-14 12:00:00 UTC so the days-window math
            // is deterministic across test environments.
            static fn (): int => 1776470400,
        );

        $patient = (new PatientFactory($db))->create();
        $med = (new MedicineFactory($db))->create();
        $this->scheduleId = (new ScheduleFactory($db))->create([
            'patient_id' => $patient['id'],
            'medicine_id' => $med['id'],
            'start_date' => '2026-01-01',
            'frequency' => '1d',
        ])['id'];
    }

    public function testMissingScheduleIdReturns400(): void
    {
        $this->assertSame(400, $this->api->handle([])->httpStatus);
    }

    public function testUnknownScheduleReturns404(): void
    {
        $this->assertSame(404, $this->api->handle(['schedule_id' => '999'])->httpStatus);
    }

    public function testReturnsIntakesForSchedule(): void
    {
        $intakes = new IntakeFactory($this->getDb());
        $intakes->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-04-10 08:00:00']);
        $intakes->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-04-13 08:00:00']);
        // Outside default 30-day window:
        $intakes->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-03-01 08:00:00']);

        $resp = $this->api->handle(['schedule_id' => (string) $this->scheduleId]);
        $this->assertSame(200, $resp->httpStatus);
        $data = $resp->data;
        $this->assertIsArray($data);
        $this->assertSame($this->scheduleId, $data['schedule_id']);
        $this->assertSame(30, $data['days']);
        $this->assertArrayHasKey('intakes', $data);
        $this->assertIsArray($data['intakes']);
        $this->assertCount(2, $data['intakes']);
    }

    public function testCustomDaysWindow(): void
    {
        $intakes = new IntakeFactory($this->getDb());
        $intakes->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-04-13 08:00:00']);
        $intakes->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-04-05 08:00:00']);

        $resp = $this->api->handle([
            'schedule_id' => (string) $this->scheduleId,
            'days' => '7',
        ]);
        $data = $resp->data;
        $this->assertIsArray($data);
        $this->assertSame(7, $data['days']);
        $this->assertArrayHasKey('intakes', $data);
        $this->assertIsArray($data['intakes']);
        $this->assertCount(1, $data['intakes']);
    }

    public function testDaysCappedAtMax(): void
    {
        $resp = $this->api->handle([
            'schedule_id' => (string) $this->scheduleId,
            'days' => '999',
        ]);
        $data = $resp->data;
        $this->assertIsArray($data);
        $this->assertSame(IntakesApi::MAX_DAYS, $data['days']);
    }
}
