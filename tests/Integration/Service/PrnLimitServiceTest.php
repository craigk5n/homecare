<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\PrnLimitService;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class PrnLimitServiceTest extends DatabaseTestCase
{
    private PrnLimitService $service;
    private IntakeRepository $intakes;
    private int $patientId;
    private int $medicineId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->intakes = new IntakeRepository($db);
        $this->service = new PrnLimitService(new ScheduleRepository($db), $this->intakes);
        $this->patientId = (new PatientFactory($db))->create()['id'];
        $this->medicineId = (new MedicineFactory($db))->create()['id'];
    }

    /**
     * @param array{max_doses_per_24h?:?int,min_hours_between?:?float,is_prn?:bool} $overrides
     */
    private function schedule(array $overrides): int
    {
        return (new ScheduleFactory($this->getDb()))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'is_prn' => true,
            ...$overrides,
        ])['id'];
    }

    public function testReturnsNullForPrnWithoutLimits(): void
    {
        $id = $this->schedule([]);

        $this->assertNull($this->service->statusFor($id, '2026-04-16 12:00:00'));
    }

    public function testReturnsNullForFixedCadenceSchedule(): void
    {
        $id = $this->schedule(['is_prn' => false, 'max_doses_per_24h' => 2]);

        $this->assertNull($this->service->statusFor($id, '2026-04-16 12:00:00'));
    }

    public function testReturnsNullForUnknownScheduleOrBadTime(): void
    {
        $id = $this->schedule(['max_doses_per_24h' => 2]);

        $this->assertNull($this->service->statusFor(99999, '2026-04-16 12:00:00'));
        $this->assertNull($this->service->statusFor($id, 'not a time'));
    }

    public function testCountsOnlyIntakesInsideRollingWindow(): void
    {
        $id = $this->schedule(['max_doses_per_24h' => 3]);
        $this->intakes->recordIntake($id, '2026-04-15 11:00:00'); // > 24h ago
        $this->intakes->recordIntake($id, '2026-04-15 12:00:00'); // exactly 24h — aged out
        $this->intakes->recordIntake($id, '2026-04-15 12:00:01'); // just inside
        $this->intakes->recordIntake($id, '2026-04-16 09:00:00');
        $this->intakes->recordIntake($id, '2026-04-16 13:00:00'); // after proposed time

        $status = $this->service->statusFor($id, '2026-04-16 12:00:00');

        $this->assertNotNull($status);
        $this->assertSame(2, $status->dosesInWindow);
        $this->assertSame(1, $status->remaining());
        $this->assertFalse($status->isWarning());
    }

    public function testIgnoresOtherSchedulesIntakes(): void
    {
        $id = $this->schedule(['max_doses_per_24h' => 1]);
        $other = $this->schedule(['max_doses_per_24h' => 1]);
        $this->intakes->recordIntake($other, '2026-04-16 10:00:00');

        $status = $this->service->statusFor($id, '2026-04-16 12:00:00');

        $this->assertNotNull($status);
        $this->assertSame(0, $status->dosesInWindow);
    }

    public function testAcceptsDatetimeLocalFormat(): void
    {
        $id = $this->schedule(['max_doses_per_24h' => 1]);
        $this->intakes->recordIntake($id, '2026-04-16 10:00:00');

        $status = $this->service->statusFor($id, '2026-04-16T12:00');

        $this->assertNotNull($status);
        $this->assertTrue($status->wouldExceedMax());
        $this->assertSame('2026-04-17 10:00:00', $status->nextAllowedAt);
        $this->assertSame('2026-04-16 12:00:00', $status->evaluatedAt);
    }

    public function testMinIntervalLongerThanWindowLooksBackFarEnough(): void
    {
        $id = $this->schedule(['min_hours_between' => 36.0]);
        $this->intakes->recordIntake($id, '2026-04-15 06:00:00'); // 30h before

        $status = $this->service->statusFor($id, '2026-04-16 12:00:00');

        $this->assertNotNull($status);
        $this->assertTrue($status->tooSoon);
        $this->assertSame('2026-04-16 18:00:00', $status->nextAllowedAt);
    }

    public function testAfterRecordingTheLimitingDoseLevelIsReached(): void
    {
        $id = $this->schedule(['max_doses_per_24h' => 2]);
        $this->intakes->recordIntake($id, '2026-04-16 08:00:00');
        $this->intakes->recordIntake($id, '2026-04-16 12:00:00');

        $status = $this->service->statusFor($id, '2026-04-16 12:00:00');

        $this->assertNotNull($status);
        $this->assertSame('reached', $status->limitLevel());
    }
}
