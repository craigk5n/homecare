<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Domain\ScheduleCalculator;
use HomeCare\Repository\InventoryRepositoryInterface;

/**
 * GET /api/v1/inventory.php?medicine_id=N
 *
 * Returns the latest stock checkpoint for a medicine plus a projected
 * days-of-supply based on the aggregate daily consumption of all
 * currently-active schedules using that medicine.
 */
final class InventoryApi
{
    /** @var callable():string */
    private readonly mixed $clock;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly InventoryRepositoryInterface $inventory,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d');
    }

    /**
     * @param array<string,mixed> $query
     */
    public function handle(array $query): ApiResponse
    {
        $medicineId = self::intParam($query, 'medicine_id');
        if ($medicineId === null) {
            return ApiResponse::error('medicine_id is required (positive integer)', 400);
        }

        $name = $this->inventory->getMedicineName($medicineId);
        if ($name === null) {
            return ApiResponse::error('medicine not found', 404);
        }

        $stock = $this->inventory->getLatestStock($medicineId);

        /** @var callable():string $clock */
        $clock = $this->clock;
        $today = ($clock)();

        // Active schedules for this medicine: sum unit_per_dose × doses/day.
        $schedRows = $this->db->query(
            'SELECT id, patient_id, frequency, unit_per_dose
             FROM hc_medicine_schedules
             WHERE medicine_id = ?
               AND start_date <= ?
               AND (end_date IS NULL OR end_date >= ?)',
            [$medicineId, $today, $today]
        );

        $totalDaily = 0.0;
        $schedules = [];
        foreach ($schedRows as $r) {
            $freq = (string) $r['frequency'];
            $upd = (float) $r['unit_per_dose'];
            try {
                $dailyForSched = $upd * (86400 / ScheduleCalculator::frequencyToSeconds($freq));
            } catch (\InvalidArgumentException) {
                // Corrupt/legacy frequency strings -- skip so one bad row
                // doesn't break the whole endpoint.
                continue;
            }
            $totalDaily += $dailyForSched;
            $schedules[] = [
                'schedule_id' => (int) $r['id'],
                'patient_id' => (int) $r['patient_id'],
                'frequency' => $freq,
                'unit_per_dose' => $upd,
                'daily_consumption' => $dailyForSched,
            ];
        }

        $projectedDays = null;
        if ($stock !== null && $totalDaily > 0.0) {
            $projectedDays = (int) floor($stock['current_stock'] / $totalDaily);
        }

        return ApiResponse::ok([
            'medicine_id' => $medicineId,
            'medicine_name' => $name,
            'current_stock' => $stock === null ? null : $stock['current_stock'],
            'recorded_at' => $stock === null ? null : $stock['recorded_at'],
            'note' => $stock === null ? null : $stock['note'],
            'active_schedules' => $schedules,
            'total_daily_consumption' => $totalDaily,
            'projected_days_supply' => $projectedDays,
        ]);
    }

    /**
     * @param array<string,mixed> $query
     */
    private static function intParam(array $query, string $key): ?int
    {
        if (!isset($query[$key]) || !is_scalar($query[$key])) {
            return null;
        }
        $raw = (string) $query[$key];
        if (!preg_match('/^\d+$/', $raw)) {
            return null;
        }
        $n = (int) $raw;

        return $n > 0 ? $n : null;
    }
}
