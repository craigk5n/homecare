<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\PatientAccess;
use HomeCare\Repository\UserPatientRepository;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * HC-237: {@see PatientAccess::create()} wired against the real schema,
 * including the legacy is_admin='Y' promotion.
 */
final class PatientAccessIntegrationTest extends DatabaseTestCase
{
    private int $daisy;

    private int $fozzie;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->daisy = (new PatientFactory($db))->create(['name' => 'Daisy'])['id'];
        $this->fozzie = (new PatientFactory($db))->create(['name' => 'Fozzie'])['id'];

        foreach ([
            ['root', 'admin', 'N'],
            ['legacy', 'caregiver', 'Y'],
            ['carol', 'caregiver', 'N'],
            ['vera', 'viewer', 'N'],
        ] as [$login, $role, $isAdmin]) {
            $db->execute(
                'INSERT INTO hc_user (login, passwd, role, is_admin) VALUES (?, ?, ?, ?)',
                [$login, 'x', $role, $isAdmin],
            );
        }
    }

    public function testEveryoneSeesEverythingWhileNoAssignmentsExist(): void
    {
        $access = PatientAccess::create($this->getDb());

        foreach (['root', 'legacy', 'carol', 'vera'] as $login) {
            $this->assertNull($access->allowedPatientIds($login), $login);
        }
    }

    public function testAssignedCaregiverIsLimitedAndAdminsAreNot(): void
    {
        $repo = new UserPatientRepository($this->getDb());
        $repo->replacePatientIds('carol', [$this->daisy]);
        $repo->replacePatientIds('root', [$this->daisy]);
        $repo->replacePatientIds('legacy', [$this->daisy]);

        $access = PatientAccess::create($this->getDb());

        $this->assertSame([$this->daisy], $access->allowedPatientIds('carol'));
        $this->assertFalse($access->canAccess('carol', $this->fozzie));
        $this->assertNull($access->allowedPatientIds('root'));
        $this->assertNull($access->allowedPatientIds('legacy'), 'is_admin=Y promotes');
        $this->assertNull($access->allowedPatientIds('vera'));
        $this->assertSame([], $access->allowedPatientIds('nobody'));
    }

    public function testIndirectIdsAgainstRealRows(): void
    {
        $db = $this->getDb();
        $med = (new MedicineFactory($db))->create(['name' => 'Sildenafil'])['id'];
        $fozzieSchedule = (new ScheduleFactory($db))->create(['patient_id' => $this->fozzie, 'medicine_id' => $med])['id'];
        $intake = (new IntakeFactory($db))->create(['schedule_id' => $fozzieSchedule])['id'];
        (new UserPatientRepository($db))->replacePatientIds('carol', [$this->daisy]);
        (new UserPatientRepository($db))->replacePatientIds('vera', [$this->fozzie]);

        $access = PatientAccess::create($db);

        $this->assertFalse($access->canAccessSchedule('carol', $fozzieSchedule));
        $this->assertFalse($access->canAccessIntake('carol', $intake));
        $this->assertFalse($access->canAccessMedicine('carol', $med));
        $this->assertTrue($access->canAccessSchedule('vera', $fozzieSchedule));
        $this->assertTrue($access->canAccessIntake('vera', $intake));
        $this->assertTrue($access->canAccessMedicine('vera', $med));
    }
}
