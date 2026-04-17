<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Access to hc_drug_catalog — the RxNorm-sourced drug lookup (HC-110).
 *
 * @phpstan-type DrugCatalogEntry array{
 *     id:int,
 *     rxnorm_id:?int,
 *     name:string,
 *     strength:?string,
 *     dosage_form:?string,
 *     ingredient_names:?string,
 *     generic:bool
 * }
 */
final class DrugCatalogRepository implements DrugCatalogRepositoryInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @return list<DrugCatalogEntry>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $rows = $this->db->query(
            'SELECT id, rxnorm_id, name, strength, dosage_form, ingredient_names, generic
             FROM hc_drug_catalog
             WHERE name LIKE ?
             ORDER BY name ASC
             LIMIT ?',
            ['%' . $query . '%', $limit]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return DrugCatalogEntry|null
     */
    public function findById(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT id, rxnorm_id, name, strength, dosage_form, ingredient_names, generic
             FROM hc_drug_catalog WHERE id = ?',
            [$id]
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    /**
     * @return DrugCatalogEntry|null
     */
    public function findByRxnormId(int $rxnormId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, rxnorm_id, name, strength, dosage_form, ingredient_names, generic
             FROM hc_drug_catalog WHERE rxnorm_id = ?',
            [$rxnormId]
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    /**
     * @param array{rxnorm_id:?int, name:string, strength:?string, dosage_form:?string, ingredient_names:?string, generic:bool} $data
     */
    public function upsertByRxnormId(array $data): int
    {
        $rxnormId = $data['rxnorm_id'];

        if ($rxnormId !== null) {
            $existing = $this->findByRxnormId($rxnormId);
            if ($existing !== null) {
                $this->db->execute(
                    'UPDATE hc_drug_catalog
                     SET name = ?, strength = ?, dosage_form = ?, ingredient_names = ?, generic = ?
                     WHERE rxnorm_id = ?',
                    [
                        $data['name'],
                        $data['strength'],
                        $data['dosage_form'],
                        $data['ingredient_names'],
                        $data['generic'] ? 'Y' : 'N',
                        $rxnormId,
                    ]
                );

                return $existing['id'];
            }
        }

        $this->db->execute(
            'INSERT INTO hc_drug_catalog (rxnorm_id, name, strength, dosage_form, ingredient_names, generic)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $rxnormId,
                $data['name'],
                $data['strength'],
                $data['dosage_form'],
                $data['ingredient_names'],
                $data['generic'] ? 'Y' : 'N',
            ]
        );

        return $this->db->lastInsertId();
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return DrugCatalogEntry
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'rxnorm_id' => $row['rxnorm_id'] !== null ? (int) $row['rxnorm_id'] : null,
            'name' => (string) $row['name'],
            'strength' => $row['strength'] !== null ? (string) $row['strength'] : null,
            'dosage_form' => $row['dosage_form'] !== null ? (string) $row['dosage_form'] : null,
            'ingredient_names' => $row['ingredient_names'] !== null ? (string) $row['ingredient_names'] : null,
            'generic' => ($row['generic'] ?? 'N') === 'Y',
        ];
    }
}
