<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Access to hc_medicine_inventory (stock checkpoints) and the derived
 * "consumed since" metric used by the inventory dashboard and
 * {@see \HomeCare\Service\InventoryService} (coming in HC-005).
 *
 * Inventory rows are checkpoints, not running counters -- `current_stock`
 * is the absolute level at `recorded_at`. Subsequent intakes are summed
 * off of the schedule-level `unit_per_dose` so stock math stays honest
 * even after a dosage change (HC-005 covers the math layer).
 *
 * @phpstan-type StockLevel array{
 *     id:int,
 *     medicine_id:int,
 *     quantity:float,
 *     current_stock:float,
 *     recorded_at:string,
 *     note:?string
 * }
 */
final class InventoryRepository implements InventoryRepositoryInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * Product-catalog name for a medicine, or null if the medicine is missing.
     *
     * Lives on this repository rather than in a separate MedicineRepository
     * because every caller of {@see getLatestStock()} also wants the medicine's
     * display name, and a one-method repo would be over-abstraction.
     */
    public function getMedicineName(int $medicineId): ?string
    {
        $rows = $this->db->query(
            'SELECT name FROM hc_medicines WHERE id = ?',
            [$medicineId],
        );

        return $rows === [] ? null : (string) $rows[0]['name'];
    }

    /**
     * @return StockLevel|null
     */
    public function getLatestStock(int $medicineId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, medicine_id, quantity, current_stock, recorded_at, note
             FROM hc_medicine_inventory
             WHERE medicine_id = ?
             ORDER BY recorded_at DESC, id DESC
             LIMIT 1',
            [$medicineId],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return [
            'id' => (int) $row['id'],
            'medicine_id' => (int) $row['medicine_id'],
            'quantity' => (float) $row['quantity'],
            'current_stock' => (float) $row['current_stock'],
            'recorded_at' => (string) $row['recorded_at'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
        ];
    }

    /**
     * Total units consumed since $since, summed from the schedule-level
     * unit_per_dose of each intake. Returns 0.0 when nothing has been taken.
     */
    public function getTotalConsumedSince(int $medicineId, string $since): float
    {
        $rows = $this->db->query(
            'SELECT SUM(s.unit_per_dose) AS total_consumed
             FROM hc_medicine_intake i
             JOIN hc_medicine_schedules s ON s.id = i.schedule_id
             WHERE s.medicine_id = ? AND i.taken_time > ?',
            [$medicineId, $since],
        );

        if ($rows === [] || $rows[0]['total_consumed'] === null) {
            return 0.0;
        }

        return (float) $rows[0]['total_consumed'];
    }
}
