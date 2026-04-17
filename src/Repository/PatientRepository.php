<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Read-side access to hc_patients.
 *
 * Returns simple associative arrays keyed by column name rather than a
 * Patient value object. The legacy pages already consume arrays; promoting
 * to a DTO is a future refactor (see HC-004 follow-ups).
 *
 * @phpstan-type Patient array{
 *     id:int,
 *     name:string,
 *     species:?string,
 *     weight_kg:?float,
 *     weight_as_of:?string,
 *     created_at:?string,
 *     updated_at:?string,
 *     is_active:int
 * }
 */
final class PatientRepository
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @return Patient|null
     */
    public function getById(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT id, name, species, weight_kg, weight_as_of, created_at, updated_at, is_active
             FROM hc_patients WHERE id = ?',
            [$id],
        );
        if ($rows === []) {
            return null;
        }

        return self::hydrate($rows[0]);
    }

    /**
     * @return list<Patient>
     */
    public function getAll(bool $includeDisabled = false): array
    {
        $where = $includeDisabled ? '' : ' WHERE is_active = 1';
        $sql = 'SELECT id, name, species, weight_kg, weight_as_of, created_at, updated_at, is_active'
            . ' FROM hc_patients' . $where . ' ORDER BY name ASC';

        return array_map(self::hydrate(...), $this->db->query($sql));
    }

    /**
     * @param array<string,scalar|null> $row
     *
     * @return Patient
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'species' => $row['species'] === null ? null : (string) $row['species'],
            'weight_kg' => $row['weight_kg'] === null ? null : (float) $row['weight_kg'],
            'weight_as_of' => $row['weight_as_of'] === null ? null : (string) $row['weight_as_of'],
            'created_at' => $row['created_at'] === null ? null : (string) $row['created_at'],
            'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
            'is_active' => (int) $row['is_active'],
        ];
    }
}
