<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Api;

use HomeCare\Api\IntakesApi;
use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\PrnLimitService;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * POST /api/v1/intakes.php with HC-238 PRN limits wired in.
 *
 * Over-limit doses are still recorded (201) -- the response gains a
 * `warning` string and a `prn_limit` block; existing fields are
 * unchanged so old clients keep working.
 */
final class PrnLimitIntakeApiTest extends DatabaseTestCase
{
    private IntakesApi $api;
    private IntakeRepository $intakes;
    private int $patientId;
    private int $medicineId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $schedules = new ScheduleRepository($db);
        $this->intakes = new IntakeRepository($db);
        $this->api = new IntakesApi(
            $schedules,
            $this->intakes,
            static fn(): int => (int) strtotime('2026-04-16 12:00:00'),
            new PrnLimitService($schedules, $this->intakes),
        );
        $this->patientId = (new PatientFactory($db))->create()['id'];
        $this->medicineId = (new MedicineFactory($db))->create()['id'];
    }

    /**
     * @param array{is_prn?:bool,max_doses_per_24h?:?int,min_hours_between?:?float} $overrides
     */
    private function schedule(array $overrides): int
    {
        return (new ScheduleFactory($this->getDb()))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            ...$overrides,
        ])['id'];
    }

    /**
     * @return array<string,mixed>
     */
    private function post(int $scheduleId, ?string $takenTime = '2026-04-16 12:00:00'): array
    {
        $body = ['schedule_id' => $scheduleId];
        if ($takenTime !== null) {
            $body['taken_time'] = $takenTime;
        }
        $resp = $this->api->record($body, 'caregiver');
        $this->assertSame(201, $resp->httpStatus);
        $this->assertIsArray($resp->data);

        /** @var array<string,mixed> */
        return $resp->data;
    }

    public function testWithinLimitHasNullWarningAndRemainingCount(): void
    {
        $id = $this->schedule(['is_prn' => true, 'max_doses_per_24h' => 3]);
        $this->intakes->recordIntake($id, '2026-04-16 08:00:00');

        $data = $this->post($id);

        $this->assertNull($data['warning']);
        $this->assertIsArray($data['prn_limit']);
        // Evaluated after this dose: 2 of 3 used, 1 left.
        $this->assertSame(2, $data['prn_limit']['doses_in_last_24h']);
        $this->assertSame(1, $data['prn_limit']['remaining']);
        $this->assertNull($data['prn_limit']['next_allowed_at']);
    }

    public function testOverLimitIsRecordedWithWarning(): void
    {
        $id = $this->schedule(['is_prn' => true, 'max_doses_per_24h' => 2]);
        $this->intakes->recordIntake($id, '2026-04-16 06:00:00');
        $this->intakes->recordIntake($id, '2026-04-16 10:00:00');

        $data = $this->post($id);

        $this->assertIsInt($data['id']);
        $this->assertSame($id, $data['schedule_id']);
        $this->assertIsString($data['warning']);
        $this->assertStringContainsString('2 of 2', $data['warning']);
        $this->assertIsArray($data['prn_limit']);
        $this->assertSame(3, $data['prn_limit']['doses_in_last_24h']);
        $this->assertSame(0, $data['prn_limit']['remaining']);
        $this->assertSame('2026-04-17 10:00:00', $data['prn_limit']['next_allowed_at']);
        $this->assertSame(3, $this->intakes->countIntakesSince($id, '2026-04-16 00:00:00'));
    }

    public function testTooSoonWarnsUsingClockWhenTakenTimeOmitted(): void
    {
        $id = $this->schedule(['is_prn' => true, 'min_hours_between' => 4.0]);
        $this->intakes->recordIntake($id, '2026-04-16 10:00:00');

        $data = $this->post($id, null);

        $this->assertIsString($data['warning']);
        $this->assertStringContainsString('4 hours', $data['warning']);
    }

    public function testUnlimitedScheduleHasNoPrnBlock(): void
    {
        $prn = $this->schedule(['is_prn' => true]);
        $fixed = $this->schedule(['frequency' => '8h']);

        foreach ([$prn, $fixed] as $id) {
            $data = $this->post($id);
            $this->assertArrayHasKey('warning', $data);
            $this->assertNull($data['warning']);
            $this->assertArrayNotHasKey('prn_limit', $data);
        }
    }

    public function testWithoutServiceResponseShapeIsUnchanged(): void
    {
        $db = $this->getDb();
        $api = new IntakesApi(new ScheduleRepository($db), new IntakeRepository($db));
        $id = $this->schedule(['is_prn' => true, 'max_doses_per_24h' => 1]);

        $resp = $api->record(['schedule_id' => $id, 'taken_time' => '2026-04-16 12:00:00'], 'caregiver');

        $this->assertIsArray($resp->data);
        $this->assertSame(['id', 'schedule_id', 'taken_time', 'note'], array_keys($resp->data));
    }
}
