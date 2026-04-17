<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\WeightRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class WeightRepositoryTest extends DatabaseTestCase
{
    private WeightRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed a patient.
        $this->getDb()->execute(
            "INSERT INTO hc_patients (id, name, is_active) VALUES (1, 'Daisy', 1)",
            [],
        );

        $this->repo = new WeightRepository($this->getDb());
    }

    public function testInsertAndGetHistory(): void
    {
        $id = $this->repo->insert(1, 5.25, '2026-04-01', 'Morning weigh-in');

        self::assertGreaterThan(0, $id);

        $history = $this->repo->getHistory(1);
        self::assertCount(1, $history);
        self::assertSame(5.25, $history[0]['weight_kg']);
        self::assertSame('2026-04-01', $history[0]['recorded_at']);
        self::assertSame('Morning weigh-in', $history[0]['note']);
    }

    public function testHistoryReturnedNewestFirst(): void
    {
        $this->repo->insert(1, 5.00, '2026-03-01');
        $this->repo->insert(1, 5.25, '2026-04-01');
        $this->repo->insert(1, 5.10, '2026-03-15');

        $history = $this->repo->getHistory(1);

        self::assertCount(3, $history);
        self::assertSame('2026-04-01', $history[0]['recorded_at']);
        self::assertSame('2026-03-15', $history[1]['recorded_at']);
        self::assertSame('2026-03-01', $history[2]['recorded_at']);
    }

    public function testGetLatestReturnsNewest(): void
    {
        $this->repo->insert(1, 5.00, '2026-03-01');
        $this->repo->insert(1, 5.50, '2026-04-15');

        $latest = $this->repo->getLatest(1);

        self::assertNotNull($latest);
        self::assertSame(5.50, $latest['weight_kg']);
        self::assertSame('2026-04-15', $latest['recorded_at']);
    }

    public function testGetLatestReturnsNullWhenNoHistory(): void
    {
        $latest = $this->repo->getLatest(1);
        self::assertNull($latest);
    }

    public function testDeleteRemovesEntry(): void
    {
        $id = $this->repo->insert(1, 5.25, '2026-04-01');

        self::assertCount(1, $this->repo->getHistory(1));

        $this->repo->delete($id);

        self::assertCount(0, $this->repo->getHistory(1));
    }

    public function testHistoryLimitIsRespected(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repo->insert(1, 5.0 + ($i * 0.1), "2026-04-0{$i}");
        }

        $limited = $this->repo->getHistory(1, 3);
        self::assertCount(3, $limited);
    }

    public function testNullNoteIsAllowed(): void
    {
        $this->repo->insert(1, 5.25, '2026-04-01', null);

        $history = $this->repo->getHistory(1);
        self::assertNull($history[0]['note']);
    }

    public function testMultiplePatientsAreSeparate(): void
    {
        $this->getDb()->execute(
            "INSERT INTO hc_patients (id, name, is_active) VALUES (2, 'Fozzie', 1)",
            [],
        );

        $this->repo->insert(1, 5.25, '2026-04-01');
        $this->repo->insert(2, 12.00, '2026-04-01');

        self::assertCount(1, $this->repo->getHistory(1));
        self::assertCount(1, $this->repo->getHistory(2));

        $latest1 = $this->repo->getLatest(1);
        $latest2 = $this->repo->getLatest(2);
        self::assertNotNull($latest1);
        self::assertNotNull($latest2);
        self::assertSame(5.25, $latest1['weight_kg']);
        self::assertSame(12.00, $latest2['weight_kg']);
    }
}
