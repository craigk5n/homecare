<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\InteractionRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class InteractionRepositoryTest extends DatabaseTestCase
{
    private InteractionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new InteractionRepository($this->getDb());
    }

    public function testFindBetweenReturnsMatchingInteractions(): void
    {
        $this->seedInteraction('aspirin', 'warfarin', 'major', 'Increased bleeding risk');

        $results = $this->repo->findBetween(['aspirin'], ['warfarin']);

        $this->assertCount(1, $results);
        $this->assertSame('aspirin', $results[0]['ingredient_a']);
        $this->assertSame('warfarin', $results[0]['ingredient_b']);
        $this->assertSame('major', $results[0]['severity']);
    }

    public function testFindBetweenIsCaseInsensitive(): void
    {
        $this->seedInteraction('aspirin', 'warfarin', 'major', 'test');

        $results = $this->repo->findBetween(['Aspirin'], ['Warfarin']);

        $this->assertCount(1, $results);
    }

    public function testFindBetweenReturnsEmptyForNoMatch(): void
    {
        $this->seedInteraction('aspirin', 'warfarin', 'major', 'test');

        $results = $this->repo->findBetween(['lisinopril'], ['metformin']);

        $this->assertSame([], $results);
    }

    public function testFindBetweenReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], $this->repo->findBetween([], ['warfarin']));
        $this->assertSame([], $this->repo->findBetween(['aspirin'], []));
    }

    public function testFindBetweenHandlesReversedOrder(): void
    {
        $this->seedInteraction('aspirin', 'warfarin', 'major', 'test');

        // Query with ingredients in reverse order — should still match
        $results = $this->repo->findBetween(['warfarin'], ['aspirin']);

        $this->assertCount(1, $results);
    }

    public function testFindAmongReturnsInternalInteractions(): void
    {
        $this->seedInteraction('aspirin', 'warfarin', 'major', 'test');
        $this->seedInteraction('ibuprofen', 'warfarin', 'moderate', 'test2');

        $results = $this->repo->findAmong(['aspirin', 'warfarin', 'ibuprofen']);

        $this->assertCount(2, $results);
    }

    public function testUpsertInsertsNewPair(): void
    {
        $this->repo->upsert('aspirin', 'warfarin', 'major', 'Bleeding risk');

        $results = $this->repo->findBetween(['aspirin'], ['warfarin']);
        $this->assertCount(1, $results);
        $this->assertSame('major', $results[0]['severity']);
    }

    public function testUpsertUpdatesExistingPair(): void
    {
        $this->repo->upsert('aspirin', 'warfarin', 'major', 'Old description');
        $this->repo->upsert('aspirin', 'warfarin', 'moderate', 'Updated description');

        $results = $this->repo->findBetween(['aspirin'], ['warfarin']);
        $this->assertCount(1, $results);
        $this->assertSame('moderate', $results[0]['severity']);
        $this->assertSame('Updated description', $results[0]['description']);
    }

    public function testUpsertOrdersIngredientsAlphabetically(): void
    {
        // Insert with B before A — should still store as (A, B)
        $this->repo->upsert('warfarin', 'aspirin', 'major', 'test');

        $results = $this->repo->findBetween(['aspirin'], ['warfarin']);
        $this->assertCount(1, $results);
        $this->assertSame('aspirin', $results[0]['ingredient_a']);
        $this->assertSame('warfarin', $results[0]['ingredient_b']);
    }

    public function testFindBetweenSortsBySeverity(): void
    {
        $this->seedInteraction('aspirin', 'warfarin', 'minor', 'minor issue');
        $this->seedInteraction('aspirin', 'ibuprofen', 'major', 'major issue');

        $results = $this->repo->findBetween(
            ['aspirin'],
            ['warfarin', 'ibuprofen']
        );

        $this->assertCount(2, $results);
        $this->assertSame('major', $results[0]['severity']);
        $this->assertSame('minor', $results[1]['severity']);
    }

    private function seedInteraction(string $a, string $b, string $severity, string $description): void
    {
        $ordered = [$a, $b];
        sort($ordered);
        $this->getDb()->execute(
            'INSERT INTO hc_drug_interactions (ingredient_a, ingredient_b, severity, description)
             VALUES (?, ?, ?, ?)',
            [$ordered[0], $ordered[1], $severity, $description]
        );
    }
}
