<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Audit\AuditLogger;
use HomeCare\Repository\AuditRepository;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class AuditRepositoryTest extends DatabaseTestCase
{
    private AuditRepository $repo;
    private PatientFactory $patientFactory;
    private MedicineFactory $medicineFactory;
    private ScheduleFactory $scheduleFactory;
    private IntakeFactory $intakeFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new AuditRepository($this->getDb());
        $this->patientFactory = new PatientFactory($this->getDb());
        $this->medicineFactory = new MedicineFactory($this->getDb());
        $this->scheduleFactory = new ScheduleFactory($this->getDb());
        $this->intakeFactory = new IntakeFactory($this->getDb());
    }

    public function testSearchReturnsRowsNewestFirst(): void
    {
        $logger = new AuditLogger(
            $this->getDb(),
            static fn (): string => 'admin',
            static fn (): string => '10.0.0.1',
            static fn (): string => '2026-04-13 10:00:00',
        );
        $logger->log('user.login', 'user', null);
        $logger2 = new AuditLogger(
            $this->getDb(),
            static fn (): string => 'admin',
            static fn (): string => '10.0.0.1',
            static fn (): string => '2026-04-13 11:00:00',
        );
        $logger2->log('user.logout', 'user', null);

        $rows = $this->repo->search([]);
        $this->assertCount(2, $rows);
        $this->assertSame('user.logout', $rows[0]['action']);
        $this->assertSame('user.login', $rows[1]['action']);
    }

    public function testFilterByUserLogin(): void
    {
        $this->seedLog('admin', 'user.login', 'user');
        $this->seedLog('carol', 'user.login', 'user');

        $rows = $this->repo->search(['user_login' => 'admin']);
        $this->assertCount(1, $rows);
        $this->assertSame('admin', $rows[0]['user_login']);
    }

    public function testFilterByAction(): void
    {
        $this->seedLog('admin', 'intake.recorded', 'intake');
        $this->seedLog('admin', 'user.login', 'user');

        $rows = $this->repo->search(['action' => 'intake.recorded']);
        $this->assertCount(1, $rows);
        $this->assertSame('intake.recorded', $rows[0]['action']);
    }

    public function testFilterByEntityType(): void
    {
        $this->seedLog('admin', 'user.login', 'user');
        $this->seedLog('admin', 'schedule.created', 'schedule');

        $rows = $this->repo->search(['entity_type' => 'schedule']);
        $this->assertCount(1, $rows);
        $this->assertSame('schedule', $rows[0]['entity_type']);
    }

    public function testFilterByDateRange(): void
    {
        $this->seedLogAt('admin', 'user.login', 'user', null, '2026-04-10 09:00:00');
        $this->seedLogAt('admin', 'user.logout', 'user', null, '2026-04-13 09:00:00');
        $this->seedLogAt('admin', 'user.login', 'user', null, '2026-04-15 09:00:00');

        $rows = $this->repo->search(['date_from' => '2026-04-12', 'date_to' => '2026-04-14']);
        $this->assertCount(1, $rows);
        $this->assertSame('user.logout', $rows[0]['action']);
    }

    public function testCombinedFilters(): void
    {
        $this->seedLogAt('admin', 'intake.recorded', 'intake', 1, '2026-04-13 10:00:00');
        $this->seedLogAt('admin', 'user.login', 'user', null, '2026-04-13 11:00:00');
        $this->seedLogAt('carol', 'intake.recorded', 'intake', 2, '2026-04-13 12:00:00');

        $rows = $this->repo->search(['user_login' => 'admin', 'action' => 'intake.recorded']);
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['entity_id']);
    }

    public function testCountWithFilters(): void
    {
        $this->seedLog('admin', 'intake.recorded', 'intake');
        $this->seedLog('admin', 'user.login', 'user');
        $this->seedLog('carol', 'intake.recorded', 'intake');

        $this->assertSame(3, $this->repo->count([]));
        $this->assertSame(2, $this->repo->count(['user_login' => 'admin']));
        $this->assertSame(2, $this->repo->count(['action' => 'intake.recorded']));
        $this->assertSame(1, $this->repo->count(['user_login' => 'admin', 'action' => 'intake.recorded']));
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedLogAt('admin', 'intake.recorded', 'intake', $i, "2026-04-13 0{$i}:00:00");
        }

        $page1 = $this->repo->search([], 1, 2);
        $this->assertCount(2, $page1);
        $this->assertSame(5, $page1[0]['entity_id']);
        $this->assertSame(4, $page1[1]['entity_id']);

        $page3 = $this->repo->search([], 3, 2);
        $this->assertCount(1, $page3);
        $this->assertSame(1, $page3[0]['entity_id']);
    }

    public function testGetDistinctValues(): void
    {
        $this->seedLog('admin', 'user.login', 'user');
        $this->seedLog('carol', 'intake.recorded', 'intake');
        $this->seedLog('admin', 'user.logout', 'user');

        $this->assertSame(['admin', 'carol'], $this->repo->getDistinctValues('user_login'));
        $this->assertSame(['intake.recorded', 'user.login', 'user.logout'], $this->repo->getDistinctValues('action'));
        $this->assertSame(['intake', 'user'], $this->repo->getDistinctValues('entity_type'));
    }

    public function testGetDistinctValuesRejectsUnknownColumn(): void
    {
        $this->assertSame([], $this->repo->getDistinctValues('id'));
    }

    public function testPatientAndMedicineNamesResolvedForScheduleEntity(): void
    {
        $patient = $this->patientFactory->create(['name' => 'Daisy']);
        $medicine = $this->medicineFactory->create(['name' => 'Tobramycin']);
        $schedule = $this->scheduleFactory->create([
            'patient_id' => $patient['id'],
            'medicine_id' => $medicine['id'],
        ]);

        $this->seedLog('admin', 'schedule.created', 'schedule', $schedule['id']);

        $rows = $this->repo->search([]);
        $this->assertCount(1, $rows);
        $this->assertSame('Daisy', $rows[0]['patient_name']);
        $this->assertSame('Tobramycin', $rows[0]['medicine_name']);
    }

    public function testPatientAndMedicineNamesResolvedForIntakeEntity(): void
    {
        $patient = $this->patientFactory->create(['name' => 'Fozzie']);
        $medicine = $this->medicineFactory->create(['name' => 'Prednisone']);
        $schedule = $this->scheduleFactory->create([
            'patient_id' => $patient['id'],
            'medicine_id' => $medicine['id'],
        ]);
        $intake = $this->intakeFactory->create(['schedule_id' => $schedule['id']]);

        $this->seedLog('admin', 'intake.recorded', 'intake', $intake['id']);

        $rows = $this->repo->search([]);
        $this->assertCount(1, $rows);
        $this->assertSame('Fozzie', $rows[0]['patient_name']);
        $this->assertSame('Prednisone', $rows[0]['medicine_name']);
    }

    public function testMedicineNameResolvedForMedicineEntity(): void
    {
        $medicine = $this->medicineFactory->create(['name' => 'Amlodipine']);
        $this->seedLog('admin', 'medicine.created', 'medicine', $medicine['id']);

        $rows = $this->repo->search([]);
        $this->assertSame('Amlodipine', $rows[0]['medicine_name']);
        $this->assertNull($rows[0]['patient_name']);
    }

    public function testPatientNameResolvedForPatientEntity(): void
    {
        $patient = $this->patientFactory->create(['name' => 'Kermit']);
        $this->seedLog('admin', 'export.intake_csv', 'patient', $patient['id']);

        $rows = $this->repo->search([]);
        $this->assertSame('Kermit', $rows[0]['patient_name']);
        $this->assertNull($rows[0]['medicine_name']);
    }

    public function testNoFiltersReturnsAllRows(): void
    {
        $this->seedLog('admin', 'user.login', 'user');
        $this->seedLog('carol', 'intake.recorded', 'intake', 5);

        $this->assertSame(2, $this->repo->count([]));
        $this->assertCount(2, $this->repo->search([]));
    }

    public function testEmptyTableReturnsEmptyResults(): void
    {
        $this->assertSame(0, $this->repo->count([]));
        $this->assertSame([], $this->repo->search([]));
        $this->assertSame([], $this->repo->getDistinctValues('user_login'));
    }

    public function testDateFromOnlyFilter(): void
    {
        $this->seedLogAt('admin', 'user.login', 'user', null, '2026-04-10 09:00:00');
        $this->seedLogAt('admin', 'user.logout', 'user', null, '2026-04-15 09:00:00');

        $rows = $this->repo->search(['date_from' => '2026-04-13']);
        $this->assertCount(1, $rows);
        $this->assertSame('user.logout', $rows[0]['action']);
    }

    public function testDateToOnlyFilter(): void
    {
        $this->seedLogAt('admin', 'user.login', 'user', null, '2026-04-10 09:00:00');
        $this->seedLogAt('admin', 'user.logout', 'user', null, '2026-04-15 09:00:00');

        $rows = $this->repo->search(['date_to' => '2026-04-12']);
        $this->assertCount(1, $rows);
        $this->assertSame('user.login', $rows[0]['action']);
    }

    private function seedLog(
        string $login,
        string $action,
        string $entityType,
        ?int $entityId = null,
    ): void {
        $logger = new AuditLogger(
            $this->getDb(),
            static fn (): string => $login,
            static fn (): string => '10.0.0.1',
        );
        $logger->log($action, $entityType, $entityId);
    }

    private function seedLogAt(
        string $login,
        string $action,
        string $entityType,
        ?int $entityId,
        string $createdAt,
    ): void {
        $logger = new AuditLogger(
            $this->getDb(),
            static fn (): string => $login,
            static fn (): string => '10.0.0.1',
            static fn (): string => $createdAt,
        );
        $logger->log($action, $entityType, $entityId);
    }
}
