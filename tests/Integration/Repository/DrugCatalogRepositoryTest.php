<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\DrugCatalogRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class DrugCatalogRepositoryTest extends DatabaseTestCase
{
    private DrugCatalogRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new DrugCatalogRepository($this->getDb());
    }

    public function testSearchReturnsMatchingEntries(): void
    {
        $this->seedCatalogEntry('Amoxicillin 500 MG Oral Capsule', 12345);
        $this->seedCatalogEntry('Amoxicillin 250 MG Oral Capsule', 12346);
        $this->seedCatalogEntry('Ibuprofen 200 MG Oral Tablet', 12347);

        $results = $this->repo->search('amox');

        $this->assertCount(2, $results);
        $this->assertSame('Amoxicillin 250 MG Oral Capsule', $results[0]['name']);
        $this->assertSame('Amoxicillin 500 MG Oral Capsule', $results[1]['name']);
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $this->seedCatalogEntry('Amoxicillin 500 MG Oral Capsule', 12345);

        $results = $this->repo->search('zzzzz');

        $this->assertSame([], $results);
    }

    public function testSearchReturnsEmptyForEmptyQuery(): void
    {
        $this->seedCatalogEntry('Amoxicillin 500 MG Oral Capsule', 12345);

        $results = $this->repo->search('');

        $this->assertSame([], $results);
    }

    public function testSearchRespectsLimit(): void
    {
        $this->seedCatalogEntry('Drug Alpha', 1001);
        $this->seedCatalogEntry('Drug Beta', 1002);
        $this->seedCatalogEntry('Drug Charlie', 1003);

        $results = $this->repo->search('Drug', 2);

        $this->assertCount(2, $results);
    }

    public function testFindByIdReturnsEntry(): void
    {
        $id = $this->seedCatalogEntry('Lisinopril 10 MG Oral Tablet', 54321);

        $entry = $this->repo->findById($id);

        $this->assertNotNull($entry);
        $this->assertSame('Lisinopril 10 MG Oral Tablet', $entry['name']);
        $this->assertSame(54321, $entry['rxnorm_id']);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->findById(9999));
    }

    public function testFindByRxnormIdReturnsEntry(): void
    {
        $this->seedCatalogEntry('Metformin 500 MG Oral Tablet', 99999);

        $entry = $this->repo->findByRxnormId(99999);

        $this->assertNotNull($entry);
        $this->assertSame('Metformin 500 MG Oral Tablet', $entry['name']);
    }

    public function testFindByRxnormIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->findByRxnormId(88888));
    }

    public function testUpsertInsertsNewEntry(): void
    {
        $id = $this->repo->upsertByRxnormId([
            'rxnorm_id' => 77777,
            'name' => 'Aspirin 325 MG Oral Tablet',
            'strength' => '325 MG',
            'dosage_form' => 'Oral Tablet',
            'ingredient_names' => 'Aspirin',
            'generic' => true,
        ]);

        $this->assertGreaterThan(0, $id);

        $entry = $this->repo->findById($id);
        $this->assertNotNull($entry);
        $this->assertSame('Aspirin 325 MG Oral Tablet', $entry['name']);
        $this->assertSame('325 MG', $entry['strength']);
        $this->assertSame('Oral Tablet', $entry['dosage_form']);
        $this->assertSame('Aspirin', $entry['ingredient_names']);
        $this->assertTrue($entry['generic']);
    }

    public function testUpsertUpdatesExistingByRxnormId(): void
    {
        $originalId = $this->repo->upsertByRxnormId([
            'rxnorm_id' => 66666,
            'name' => 'Old Name',
            'strength' => '100 MG',
            'dosage_form' => 'Oral Tablet',
            'ingredient_names' => 'Old',
            'generic' => false,
        ]);

        $updatedId = $this->repo->upsertByRxnormId([
            'rxnorm_id' => 66666,
            'name' => 'New Name',
            'strength' => '200 MG',
            'dosage_form' => 'Oral Capsule',
            'ingredient_names' => 'New',
            'generic' => true,
        ]);

        $this->assertSame($originalId, $updatedId);

        $entry = $this->repo->findById($originalId);
        $this->assertNotNull($entry);
        $this->assertSame('New Name', $entry['name']);
        $this->assertSame('200 MG', $entry['strength']);
        $this->assertTrue($entry['generic']);
    }

    public function testUpsertWithNullRxnormIdAlwaysInserts(): void
    {
        $id1 = $this->repo->upsertByRxnormId([
            'rxnorm_id' => null,
            'name' => 'Vet Drug A',
            'strength' => null,
            'dosage_form' => null,
            'ingredient_names' => null,
            'generic' => false,
        ]);

        $id2 = $this->repo->upsertByRxnormId([
            'rxnorm_id' => null,
            'name' => 'Vet Drug B',
            'strength' => null,
            'dosage_form' => null,
            'ingredient_names' => null,
            'generic' => false,
        ]);

        $this->assertNotSame($id1, $id2);
    }

    // HC-111: NDC barcode lookup ----------------------------------------

    public function testFindByNdcReturnsMatchingEntries(): void
    {
        $this->seedCatalogEntryWithNdc('Amoxicillin 500 MG Oral Capsule', 12345, '00071015523');

        $results = $this->repo->findByNdc('00071015523');

        $this->assertCount(1, $results);
        $this->assertSame('Amoxicillin 500 MG Oral Capsule', $results[0]['name']);
        $this->assertSame('00071015523', $results[0]['ndc']);
    }

    public function testFindByNdcStripsNonDigits(): void
    {
        $this->seedCatalogEntryWithNdc('Lisinopril 10 MG Oral Tablet', 54321, '00781218201');

        $results = $this->repo->findByNdc('0078-1218-201');

        $this->assertCount(1, $results);
    }

    public function testFindByNdcReturnsEmptyForNoMatch(): void
    {
        $this->seedCatalogEntryWithNdc('Some Drug', 11111, '99999999999');

        $results = $this->repo->findByNdc('00000000000');

        $this->assertSame([], $results);
    }

    public function testFindByNdcReturnsEmptyForEmptyString(): void
    {
        $results = $this->repo->findByNdc('');

        $this->assertSame([], $results);
    }

    public function testHydrateIncludesNdcField(): void
    {
        $this->seedCatalogEntryWithNdc('Test Drug', 88888, '12345678901');

        $entry = $this->repo->findByRxnormId(88888);
        $this->assertNotNull($entry);
        $this->assertSame('12345678901', $entry['ndc']);
    }

    public function testHydrateReturnsNullNdcWhenNotSet(): void
    {
        $this->seedCatalogEntry('No NDC Drug', 77777);

        $entry = $this->repo->findByRxnormId(77777);
        $this->assertNotNull($entry);
        $this->assertNull($entry['ndc']);
    }

    public function testHydrateSetsBooleanForGeneric(): void
    {
        $this->seedCatalogEntryFull('Generic Drug', 11111, generic: true);
        $this->seedCatalogEntryFull('Brand Drug', 22222, generic: false);

        $generic = $this->repo->findByRxnormId(11111);
        $brand = $this->repo->findByRxnormId(22222);

        $this->assertNotNull($generic);
        $this->assertTrue($generic['generic']);
        $this->assertNotNull($brand);
        $this->assertFalse($brand['generic']);
    }

    private function seedCatalogEntry(string $name, int $rxnormId): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_drug_catalog (rxnorm_id, name) VALUES (?, ?)',
            [$rxnormId, $name],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedCatalogEntryFull(
        string $name,
        int $rxnormId,
        bool $generic = false,
    ): int {
        $this->getDb()->execute(
            'INSERT INTO hc_drug_catalog (rxnorm_id, name, generic) VALUES (?, ?, ?)',
            [$rxnormId, $name, $generic ? 'Y' : 'N'],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedCatalogEntryWithNdc(string $name, int $rxnormId, string $ndc): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_drug_catalog (rxnorm_id, ndc, name) VALUES (?, ?, ?)',
            [$rxnormId, $ndc, $name],
        );

        return $this->getDb()->lastInsertId();
    }
}
