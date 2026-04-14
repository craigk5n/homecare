<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\PatientRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class PatientRepositoryTest extends DatabaseTestCase
{
    private PatientRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PatientRepository($this->getDb());
    }

    public function testGetByIdReturnsPatientWhenFound(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $id = $db->lastInsertId();

        $patient = $this->repo->getById($id);

        $this->assertNotNull($patient);
        $this->assertSame($id, $patient['id']);
        $this->assertSame('Daisy', $patient['name']);
        $this->assertSame(1, $patient['is_active']);
    }

    public function testGetByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->getById(999));
    }

    public function testGetAllExcludesDisabledByDefault(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name, is_active) VALUES (?, ?)', ['Daisy', 1]);
        $db->execute('INSERT INTO hc_patients (name, is_active) VALUES (?, ?)', ['Fozzie', 0]);

        $patients = $this->repo->getAll();

        $this->assertCount(1, $patients);
        $this->assertSame('Daisy', $patients[0]['name']);
    }

    public function testGetAllIncludesDisabledWhenRequested(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name, is_active) VALUES (?, ?)', ['Daisy', 1]);
        $db->execute('INSERT INTO hc_patients (name, is_active) VALUES (?, ?)', ['Fozzie', 0]);

        $patients = $this->repo->getAll(true);

        $this->assertCount(2, $patients);
    }

    public function testGetAllOrdersByName(): void
    {
        $db = $this->getDb();
        foreach (['Zelda', 'Apollo', 'Milo'] as $name) {
            $db->execute('INSERT INTO hc_patients (name) VALUES (?)', [$name]);
        }

        $names = array_map(
            static fn (array $p): string => $p['name'],
            $this->repo->getAll()
        );

        $this->assertSame(['Apollo', 'Milo', 'Zelda'], $names);
    }

    public function testGetAllReturnsEmptyArrayWhenNoPatients(): void
    {
        $this->assertSame([], $this->repo->getAll());
    }
}
