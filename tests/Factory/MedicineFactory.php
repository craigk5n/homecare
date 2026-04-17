<?php

declare(strict_types=1);

namespace HomeCare\Tests\Factory;

use HomeCare\Database\DatabaseInterface;

/**
 * Test factory for hc_medicines (product catalog entries).
 *
 * @phpstan-type MedicineOverrides array{
 *     name?:string,
 *     dosage?:string
 * }
 * @phpstan-type MedicineRecord array{
 *     id:int,
 *     name:string,
 *     dosage:string
 * }
 */
final class MedicineFactory
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @param MedicineOverrides $overrides
     *
     * @return MedicineRecord
     */
    public function create(array $overrides = []): array
    {
        $record = [
            'name' => $overrides['name'] ?? 'Sildenafil',
            'dosage' => $overrides['dosage'] ?? '20mg',
        ];

        $this->db->execute(
            'INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)',
            [$record['name'], $record['dosage']],
        );

        return [
            'id' => $this->db->lastInsertId(),
            'name' => $record['name'],
            'dosage' => $record['dosage'],
        ];
    }
}
