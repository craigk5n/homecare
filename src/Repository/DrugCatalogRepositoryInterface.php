<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Read contract for the hc_drug_catalog table (HC-110).
 *
 * @phpstan-import-type DrugCatalogEntry from DrugCatalogRepository
 */
interface DrugCatalogRepositoryInterface
{
    /**
     * @return list<DrugCatalogEntry>
     */
    public function search(string $query, int $limit = 20): array;

    /**
     * @return DrugCatalogEntry|null
     */
    public function findById(int $id): ?array;

    /**
     * @return DrugCatalogEntry|null
     */
    public function findByRxnormId(int $rxnormId): ?array;

    /**
     * @return list<DrugCatalogEntry>
     */
    public function findByNdc(string $ndc): array;

    /**
     * @param array{rxnorm_id:?int, name:string, strength:?string, dosage_form:?string, ingredient_names:?string, generic:bool} $data
     */
    public function upsertByRxnormId(array $data): int;
}
