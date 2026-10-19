<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\UserPatientRepository;
use HomeCare\Service\PatientAssignmentService;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;
use InvalidArgumentException;

/**
 * HC-237: the admin "assign patients to a user" save path.
 */
final class PatientAssignmentServiceTest extends DatabaseTestCase
{
    private PatientAssignmentService $service;

    private UserPatientRepository $repo;

    private int $daisy;

    private int $fozzie;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->repo = new UserPatientRepository($db);
        $this->service = new PatientAssignmentService($db, $this->repo);
        $this->daisy = (new PatientFactory($db))->create(['name' => 'Daisy'])['id'];
        $this->fozzie = (new PatientFactory($db))->create(['name' => 'Fozzie', 'is_active' => 0])['id'];
        foreach ([['root', 'admin', 'N'], ['legacy', 'caregiver', 'Y'], ['carol', 'caregiver', 'N']] as [$login, $role, $isAdmin]) {
            $db->execute(
                'INSERT INTO hc_user (login, passwd, role, is_admin) VALUES (?, ?, ?, ?)',
                [$login, 'x', $role, $isAdmin],
            );
        }
    }

    public function testAssignStoresAndReportsTheChange(): void
    {
        $change = $this->service->assign('carol', [$this->daisy, $this->fozzie]);

        $this->assertSame([], $change['before']);
        $this->assertSame([$this->daisy, $this->fozzie], $change['after']);
        $this->assertSame([$this->daisy, $this->fozzie], $change['added']);
        $this->assertSame([], $change['removed']);
        $this->assertSame([$this->daisy, $this->fozzie], $this->repo->getPatientIds('carol'));
    }

    public function testReassignReportsAddedAndRemoved(): void
    {
        $this->service->assign('carol', [$this->daisy]);

        $change = $this->service->assign('carol', [$this->fozzie]);

        $this->assertSame([$this->daisy], $change['before']);
        $this->assertSame([$this->fozzie], $change['added']);
        $this->assertSame([$this->daisy], $change['removed']);
    }

    public function testEmptySelectionClearsBackToAllPatients(): void
    {
        $this->service->assign('carol', [$this->daisy]);

        $change = $this->service->assign('carol', []);

        $this->assertSame([], $change['after']);
        $this->assertSame([], $this->repo->getPatientIds('carol'));
    }

    public function testUnknownPatientIdsAreDropped(): void
    {
        $change = $this->service->assign('carol', [$this->daisy, 9999, 0, -3, $this->daisy]);

        $this->assertSame([$this->daisy], $change['after']);
    }

    public function testUnknownUserIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->assign('ghost', [$this->daisy]);
    }

    public function testAdminTargetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->assign('root', [$this->daisy]);
    }

    public function testLegacyIsAdminFlagCountsAsAdmin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->assign('legacy', [$this->daisy]);
    }
}
