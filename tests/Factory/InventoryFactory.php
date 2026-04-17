<?php

declare(strict_types=1);

namespace HomeCare\Tests\Factory;

use HomeCare\Database\DatabaseInterface;

/**
 * Test factory for hc_medicine_inventory (stock checkpoints).
 *
 * Inventory rows are checkpoints -- `current_stock` is the authoritative
 * level at `recorded_at`, not a running delta. The factory reflects that:
 * default `quantity` equals `current_stock`.
 *
 * @phpstan-type InventoryOverrides array{
 *     medicine_id:int,
 *     current_stock?:float,
 *     quantity?:float,
 *     recorded_at?:string,
 *     note?:?string
 * }
 * @phpstan-type InventoryRecord array{
 *     id:int,
 *     medicine_id:int,
 *     quantity:float,
 *     current_stock:float,
 *     recorded_at:string,
 *     note:?string
 * }
 */
final class InventoryFactory
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @param InventoryOverrides $overrides
     *
     * @return InventoryRecord
     */
    public function create(array $overrides): array
    {
        $currentStock = $overrides['current_stock'] ?? 30.0;
        $record = [
            'medicine_id' => $overrides['medicine_id'],
            'quantity' => $overrides['quantity'] ?? $currentStock,
            'current_stock' => $currentStock,
            'recorded_at' => $overrides['recorded_at'] ?? date('Y-m-d H:i:s'),
            'note' => $overrides['note'] ?? null,
        ];

        $this->db->execute(
            'INSERT INTO hc_medicine_inventory
                (medicine_id, quantity, current_stock, recorded_at, note)
             VALUES (?, ?, ?, ?, ?)',
            [
                $record['medicine_id'],
                $record['quantity'],
                $record['current_stock'],
                $record['recorded_at'],
                $record['note'],
            ],
        );

        return [
            'id' => $this->db->lastInsertId(),
            ...$record,
        ];
    }
}
