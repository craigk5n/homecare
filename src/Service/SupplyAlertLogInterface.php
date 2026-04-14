<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * Per-medicine "when did we last fire a supply alert?" ledger.
 *
 * Kept behind an interface so {@see SupplyAlertService}'s throttle
 * logic is trivially mockable in unit tests.
 */
interface SupplyAlertLogInterface
{
    /**
     * Return the most recent `YYYY-MM-DD HH:MM:SS` timestamp an alert
     * was recorded for this medicine, or null if never.
     */
    public function lastSentAt(int $medicineId): ?string;

    /**
     * Upsert the "last sent" timestamp for $medicineId.
     */
    public function markSent(int $medicineId, string $whenDateTime): void;
}
