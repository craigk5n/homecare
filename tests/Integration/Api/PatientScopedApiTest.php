<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Api;

use HomeCare\Api\IntakesApi;
use HomeCare\Api\InventoryApi;
use HomeCare\Api\PatientsApi;
use HomeCare\Api\SchedulesApi;
use HomeCare\Auth\PatientScope;
use HomeCare\Repository\IntakeRepository;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\PatientRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Factory\InventoryFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * HC-237: v1 API endpoints honour a per-patient {@see PatientScope}.
 *
 * Daisy and Fozzie share Sildenafil; Fozzie alone takes Furosemide; a
 * third medicine is in the catalog but not scheduled for anyone. The
 * restricted scope allows Daisy only. Out-of-scope single resources
 * answer 404, the same as a missing row, so ids can't be probed.
 */
final class PatientScopedApiTest extends DatabaseTestCase
{
    private const TODAY = '2026-04-14';

    private int $daisy;

    private int $fozzie;

    private int $shared;

    private int $fozzieOnly;

    private int $unlinked;

    private int $daisySchedule;

    private int $fozzieSchedule;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->daisy = (new PatientFactory($db))->create(['name' => 'Daisy'])['id'];
        $this->fozzie = (new PatientFactory($db))->create(['name' => 'Fozzie'])['id'];
        $this->shared = (new MedicineFactory($db))->create(['name' => 'Sildenafil'])['id'];
        $this->fozzieOnly = (new MedicineFactory($db))->create(['name' => 'Furosemide'])['id'];
        $this->unlinked = (new MedicineFactory($db))->create(['name' => 'Unused'])['id'];

        $schedules = new ScheduleFactory($db);
        $this->daisySchedule = $schedules->create([
            'patient_id' => $this->daisy, 'medicine_id' => $this->shared, 'start_date' => '2026-01-01', 'frequency' => '12h',
        ])['id'];
        $this->fozzieSchedule = $schedules->create([
            'patient_id' => $this->fozzie, 'medicine_id' => $this->shared, 'start_date' => '2026-01-01', 'frequency' => '1d',
        ])['id'];
        $schedules->create([
            'patient_id' => $this->fozzie, 'medicine_id' => $this->fozzieOnly, 'start_date' => '2026-01-01', 'frequency' => '1d',
        ]);
        (new InventoryFactory($db))->create(['medicine_id' => $this->shared, 'current_stock' => 30.0, 'recorded_at' => '2026-04-01 08:00:00']);
        (new IntakeFactory($db))->create(['schedule_id' => $this->fozzieSchedule, 'taken_time' => '2026-04-13 08:00:00']);
    }

    private function daisyOnly(): PatientScope
    {
        return PatientScope::only([$this->daisy]);
    }

    // ---- patients -------------------------------------------------------

    public function testPatientsListIsFilteredToScope(): void
    {
        $api = new PatientsApi(new PatientRepository($this->getDb()), $this->daisyOnly());

        $data = $api->handle([])->data;
        $this->assertIsArray($data);
        $this->assertSame(['Daisy'], array_column($data, 'name'));
    }

    public function testPatientsListUnrestrictedByDefault(): void
    {
        $api = new PatientsApi(new PatientRepository($this->getDb()));

        $data = $api->handle([])->data;
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
    }

    // ---- schedules ------------------------------------------------------

    public function testSchedulesForAllowedPatient(): void
    {
        $resp = $this->schedulesApi($this->daisyOnly())->handle(['patient_id' => (string) $this->daisy]);

        $this->assertSame(200, $resp->httpStatus);
        $this->assertIsArray($resp->data);
        $this->assertCount(1, $resp->data);
    }

    public function testSchedulesForOutOfScopePatientIs404(): void
    {
        $resp = $this->schedulesApi($this->daisyOnly())->handle(['patient_id' => (string) $this->fozzie]);

        $this->assertSame(404, $resp->httpStatus);
    }

    // ---- intakes --------------------------------------------------------

    public function testIntakeHistoryForOutOfScopeScheduleIs404(): void
    {
        $resp = $this->intakesApi($this->daisyOnly())->handle(['schedule_id' => (string) $this->fozzieSchedule]);

        $this->assertSame(404, $resp->httpStatus);
    }

    public function testIntakeHistoryForAllowedSchedule(): void
    {
        $resp = $this->intakesApi(PatientScope::only([$this->fozzie]))->handle(['schedule_id' => (string) $this->fozzieSchedule]);

        $this->assertSame(200, $resp->httpStatus);
        $this->assertIsArray($resp->data);
        $this->assertIsArray($resp->data['intakes']);
        $this->assertCount(1, $resp->data['intakes']);
    }

    public function testRecordingIntakeOnOutOfScopeScheduleIs404AndWritesNothing(): void
    {
        $resp = $this->intakesApi($this->daisyOnly())->record(['schedule_id' => $this->fozzieSchedule], 'caregiver');

        $this->assertSame(404, $resp->httpStatus);
        $rows = $this->getDb()->query('SELECT COUNT(*) AS n FROM hc_medicine_intake');
        $this->assertSame(1, (int) $rows[0]['n']);
    }

    public function testRecordingIntakeOnAllowedSchedule(): void
    {
        $resp = $this->intakesApi($this->daisyOnly())->record(['schedule_id' => $this->daisySchedule], 'caregiver');

        $this->assertSame(201, $resp->httpStatus);
    }

    // ---- inventory ------------------------------------------------------

    public function testInventoryForSharedMedicineHidesOtherPatientsSchedules(): void
    {
        $resp = $this->inventoryApi($this->daisyOnly())->handle(['medicine_id' => (string) $this->shared]);

        $this->assertSame(200, $resp->httpStatus);
        $this->assertIsArray($resp->data);
        $this->assertIsArray($resp->data['active_schedules']);
        $this->assertSame([$this->daisy], array_column($resp->data['active_schedules'], 'patient_id'));
        // Stock is shared, so the projection still counts every consumer:
        // 12h (2/day) for Daisy + 1d (1/day) for Fozzie.
        $this->assertEqualsWithDelta(3.0, $resp->data['total_daily_consumption'], 0.0001);
    }

    public function testInventoryForOtherPatientsMedicineIs404(): void
    {
        $resp = $this->inventoryApi($this->daisyOnly())->handle(['medicine_id' => (string) $this->fozzieOnly]);

        $this->assertSame(404, $resp->httpStatus);
    }

    public function testInventoryForUnlinkedMedicineStaysVisible(): void
    {
        $resp = $this->inventoryApi($this->daisyOnly())->handle(['medicine_id' => (string) $this->unlinked]);

        $this->assertSame(200, $resp->httpStatus);
    }

    public function testInventoryUnrestrictedShowsEverySchedule(): void
    {
        $resp = $this->inventoryApi(null)->handle(['medicine_id' => (string) $this->shared]);

        $this->assertIsArray($resp->data);
        $this->assertIsArray($resp->data['active_schedules']);
        $this->assertCount(2, $resp->data['active_schedules']);
    }

    private function schedulesApi(?PatientScope $scope): SchedulesApi
    {
        return new SchedulesApi($this->getDb(), static fn(): string => self::TODAY, $scope);
    }

    private function intakesApi(?PatientScope $scope): IntakesApi
    {
        $db = $this->getDb();

        return new IntakesApi(new ScheduleRepository($db), new IntakeRepository($db), static fn(): int => 1776470400, $scope);
    }

    private function inventoryApi(?PatientScope $scope): InventoryApi
    {
        $db = $this->getDb();

        return new InventoryApi($db, new InventoryRepository($db), static fn(): string => self::TODAY, $scope);
    }
}
