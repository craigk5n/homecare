<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\UserPatientRepository;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * HC-237: hc_user_patients round-trips against the real schema.
 */
final class UserPatientRepositoryTest extends DatabaseTestCase
{
    private UserPatientRepository $repo;

    /** @var list<int> */
    private array $patientIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new UserPatientRepository($this->getDb());
        $factory = new PatientFactory($this->getDb());
        $this->patientIds = [
            $factory->create(['name' => 'Daisy'])['id'],
            $factory->create(['name' => 'Fozzie'])['id'],
            $factory->create(['name' => 'Kermit'])['id'],
        ];
        foreach ([['root', 'admin', 'Y'], ['carol', 'caregiver', 'N'], ['vera', 'viewer', 'N']] as [$login, $role, $isAdmin]) {
            $this->getDb()->execute(
                'INSERT INTO hc_user (login, passwd, firstname, lastname, role, is_admin) VALUES (?, ?, ?, ?, ?, ?)',
                [$login, 'x', ucfirst($login), 'Test', $role, $isAdmin],
            );
        }
    }

    public function testNoRowsReturnsEmptyList(): void
    {
        $this->assertSame([], $this->repo->getPatientIds('carol'));
    }

    public function testReplaceStoresSortedUniqueIds(): void
    {
        [$a, $b, $c] = $this->patientIds;
        $this->repo->replacePatientIds('carol', [$c, $a, $c]);

        $this->assertSame([$a, $c], $this->repo->getPatientIds('carol'));
        $this->assertSame([], $this->repo->getPatientIds('vera'), 'other users untouched');
        unset($b);
    }

    public function testReplaceOverwritesPreviousAssignments(): void
    {
        [$a, $b] = $this->patientIds;
        $this->repo->replacePatientIds('carol', [$a]);
        $this->repo->replacePatientIds('carol', [$b]);

        $this->assertSame([$b], $this->repo->getPatientIds('carol'));
    }

    public function testReplaceWithEmptyListClearsAssignments(): void
    {
        $this->repo->replacePatientIds('carol', $this->patientIds);
        $this->repo->replacePatientIds('carol', []);

        $this->assertSame([], $this->repo->getPatientIds('carol'));
    }

    public function testDeletingPatientCascades(): void
    {
        [$a, $b] = $this->patientIds;
        $this->repo->replacePatientIds('carol', [$a, $b]);
        $this->getDb()->execute('DELETE FROM hc_patients WHERE id = ?', [$a]);

        $this->assertSame([$b], $this->repo->getPatientIds('carol'));
    }

    public function testListUsersFlagsAdmins(): void
    {
        $users = $this->repo->listUsers();

        $this->assertSame(['carol', 'root', 'vera'], array_column($users, 'login'));
        $byLogin = array_column($users, null, 'login');
        $this->assertTrue($byLogin['root']['is_admin']);
        $this->assertFalse($byLogin['carol']['is_admin']);
        $this->assertSame('viewer', $byLogin['vera']['role']);
        $this->assertSame('Carol', $byLogin['carol']['firstname']);
    }

    public function testCountsByUser(): void
    {
        [$a, $b] = $this->patientIds;
        $this->repo->replacePatientIds('carol', [$a, $b]);
        $this->repo->replacePatientIds('vera', [$a]);

        $this->assertSame(['carol' => 2, 'vera' => 1], $this->sorted($this->repo->countsByUser()));
    }

    /**
     * @param array<string,int> $counts
     *
     * @return array<string,int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }
}
