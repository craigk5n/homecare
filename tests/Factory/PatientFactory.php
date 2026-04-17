<?php

declare(strict_types=1);

namespace HomeCare\Tests\Factory;

use HomeCare\Database\DatabaseInterface;

/**
 * Test factory for hc_patients.
 *
 * Example:
 *   $patient = (new PatientFactory($db))->create(['name' => 'Fozzie']);
 *
 * Returns the hydrated record including the generated `id` so tests can
 * chain it into schedule/inventory factories without another round trip.
 *
 * @phpstan-type PatientOverrides array{
 *     name?:string,
 *     is_active?:int
 * }
 * @phpstan-type PatientRecord array{
 *     id:int,
 *     name:string,
 *     is_active:int
 * }
 */
final class PatientFactory
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @param PatientOverrides $overrides
     *
     * @return PatientRecord
     */
    public function create(array $overrides = []): array
    {
        $record = [
            'name' => $overrides['name'] ?? 'Daisy',
            'is_active' => $overrides['is_active'] ?? 1,
        ];

        $this->db->execute(
            'INSERT INTO hc_patients (name, is_active) VALUES (?, ?)',
            [$record['name'], $record['is_active']],
        );

        return [
            'id' => $this->db->lastInsertId(),
            'name' => $record['name'],
            'is_active' => $record['is_active'],
        ];
    }
}
