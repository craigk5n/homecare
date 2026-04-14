<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Database\DatabaseInterface;

/**
 * "Which medicines should we push a low-supply alert about?"
 *
 * Two public surfaces:
 *   - {@see shouldAlert()} is a pure decision function (no DB, just
 *     the bookkeeping) -- unit tests exercise every branch.
 *   - {@see findPendingAlerts()} is the orchestrator that walks the
 *     active medicines, asks {@see InventoryService} for each's
 *     projected stock, and returns a queue of {@see SupplyAlert}s.
 *     Integration tests cover the walk + SQL; unit tests cover the
 *     decision rule it composes.
 *
 * Throttling: at most one alert per medicine per
 * `THROTTLE_SECONDS` window (24h by default). Tracked via
 * {@see SupplyAlertLogInterface}.
 */
final class SupplyAlertService
{
    public const DEFAULT_THRESHOLD_DAYS = 7;
    public const DEFAULT_THROTTLE_SECONDS = 24 * 3600;

    /** @var callable():string */
    private readonly mixed $clock;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly InventoryService $inventory,
        private readonly SupplyAlertLogInterface $log,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /**
     * Pure throttle + threshold decision.
     *
     * @param int         $remainingDays   Projected days of supply left.
     * @param int         $thresholdDays   Alert when remaining <= threshold.
     * @param string|null $lastSentAt      Previous alert timestamp (or null).
     * @param string      $now             Reference "now" timestamp.
     * @param int         $throttleSeconds Minimum gap between alerts.
     */
    public static function shouldAlert(
        int $remainingDays,
        int $thresholdDays,
        ?string $lastSentAt,
        string $now,
        int $throttleSeconds = self::DEFAULT_THROTTLE_SECONDS,
    ): bool {
        if ($remainingDays > $thresholdDays) {
            return false;
        }
        if ($lastSentAt === null) {
            return true;
        }

        $nowTs = strtotime($now);
        $lastTs = strtotime($lastSentAt);
        if ($nowTs === false || $lastTs === false) {
            // Can't parse? Fail open -- better a duplicate alert than missing one.
            return true;
        }

        return ($nowTs - $lastTs) >= $throttleSeconds;
    }

    /**
     * Compute the list of alerts we should fire right now, honoring
     * the per-medicine throttle. Does NOT record the alerts as sent;
     * the caller calls {@see recordSent()} after the push succeeds.
     *
     * @return list<SupplyAlert>
     */
    public function findPendingAlerts(
        int $thresholdDays = self::DEFAULT_THRESHOLD_DAYS,
        int $throttleSeconds = self::DEFAULT_THROTTLE_SECONDS,
    ): array {
        /** @var callable():string $clock */
        $clock = $this->clock;
        $now = ($clock)();
        $today = substr($now, 0, 10);

        // One row per active medicine. If a medicine has multiple active
        // schedules, we arbitrarily pick the lowest-id one for the
        // InventoryService call -- calculateRemaining sums consumption
        // across ALL schedules for that medicine internally, so which
        // schedule_id we pass only matters for the unit_per_dose override
        // layer.
        $rows = $this->db->query(
            'SELECT ms.medicine_id AS medicine_id,
                    MIN(ms.id) AS schedule_id,
                    MIN(m.name) AS name
             FROM hc_medicine_schedules ms
             JOIN hc_medicines m ON ms.medicine_id = m.id
             WHERE ms.start_date <= ?
               AND (ms.end_date IS NULL OR ms.end_date >= ?)
             GROUP BY ms.medicine_id
             ORDER BY m.name ASC',
            [$today, $today]
        );

        $alerts = [];
        $nowTs = strtotime($now);
        foreach ($rows as $row) {
            $medicineId = (int) $row['medicine_id'];
            $scheduleId = (int) $row['schedule_id'];
            $name = (string) $row['name'];

            $remaining = $this->inventory->calculateRemaining($medicineId, $scheduleId);
            if ($remaining['lastInventory'] === null) {
                // No stock ever recorded -- we can't project, so skip.
                continue;
            }
            $remainingDays = (int) $remaining['remainingDays'];

            if (!self::shouldAlert(
                $remainingDays,
                $thresholdDays,
                $this->log->lastSentAt($medicineId),
                $now,
                $throttleSeconds,
            )) {
                continue;
            }

            $depletion = ($nowTs === false)
                ? $today
                : date('Y-m-d', $nowTs + $remainingDays * 86400);

            $alerts[] = new SupplyAlert($medicineId, $name, $remainingDays, $depletion);
        }

        return $alerts;
    }

    /**
     * Stamp "sent at now" for a medicine. Use after the push notification
     * succeeds so a failed push doesn't silence future retries.
     */
    public function recordSent(int $medicineId): void
    {
        /** @var callable():string $clock */
        $clock = $this->clock;
        $this->log->markSent($medicineId, ($clock)());
    }
}
