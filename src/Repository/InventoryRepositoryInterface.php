<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Inventory read contract consumed by {@see \HomeCare\Service\InventoryService}.
 *
 * The interface exists so the service can be unit-tested with mock
 * implementations; production uses {@see InventoryRepository} directly.
 *
 * @phpstan-import-type StockLevel from InventoryRepository
 */
interface InventoryRepositoryInterface
{
    /**
     * @return StockLevel|null
     */
    public function getLatestStock(int $medicineId): ?array;

    public function getTotalConsumedSince(int $medicineId, string $since): float;

    public function getMedicineName(int $medicineId): ?string;
}
