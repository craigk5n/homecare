<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Fixture;

use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\PatientRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\InventoryService;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * End-to-end check that the seed fixture + factories work together.
 *
 * If this test fails, either the seed data drifted from the schema or the
 * factories stopped producing inserts compatible with the repositories.
 */
final class SeedDataTest extends DatabaseTestCase
{
    public function testSeedLoadsCanonicalCorpus(): void
    {
        $this->loadSeedData();

        $patients = new PatientRepository($this->getDb());
        $active = $patients->getAll();
        $all = $patients->getAll(true);

        $this->assertCount(2, $active, 'two active patients seeded');
        $this->assertCount(3, $all, 'three total including disabled Kermit');
        $this->assertSame('Daisy', $active[0]['name']);
        $this->assertSame('Fozzie', $active[1]['name']);
    }

    public function testSeedPlusFactoryIntegratesWithInventoryService(): void
    {
        $this->loadSeedData();

        // Add a fresh intake via factory to layer on top of the seed data.
        (new IntakeFactory($this->getDb()))->create([
            'schedule_id' => 1,
            'taken_time' => '2026-04-07 08:00:00',
        ]);

        $service = new InventoryService(
            new InventoryRepository($this->getDb()),
            new ScheduleRepository($this->getDb()),
        );

        // Daisy's Sildenafil schedule (id=1) against 60u inventory recorded
        // 2026-04-01. The seed adds 3 intakes for that schedule; the factory
        // added a fourth. Each consumes 1u.
        $report = $service->calculateRemaining(1, 1);

        $this->assertSame('Sildenafil', $report['medicineName']);
        $this->assertSame(60.0, $report['lastInventory']);
        $this->assertSame(4.0, $report['quantityTakenSince']);
        $this->assertSame(56.0, $report['remainingDoses']);
    }

    public function testFactoriesWorkWithoutSeedData(): void
    {
        // Demonstrates the "empty schema + factories only" pattern.
        $db = $this->getDb();

        $patient = (new \HomeCare\Tests\Factory\PatientFactory($db))->create(['name' => 'Gonzo']);
        $medicine = (new \HomeCare\Tests\Factory\MedicineFactory($db))->create(['name' => 'Meloxicam', 'dosage' => '1.5mg']);
        $schedule = (new \HomeCare\Tests\Factory\ScheduleFactory($db))->create([
            'patient_id' => $patient['id'],
            'medicine_id' => $medicine['id'],
        ]);
        $inventory = (new \HomeCare\Tests\Factory\InventoryFactory($db))->create([
            'medicine_id' => $medicine['id'],
            'current_stock' => 15.0,
        ]);

        $this->assertGreaterThan(0, $patient['id']);
        $this->assertGreaterThan(0, $medicine['id']);
        $this->assertGreaterThan(0, $schedule['id']);
        $this->assertGreaterThan(0, $inventory['id']);
        $this->assertSame('Gonzo', $patient['name']);
        $this->assertSame(15.0, $inventory['current_stock']);
        $this->assertSame(15.0, $inventory['quantity'], 'quantity defaults to current_stock');
    }
}
