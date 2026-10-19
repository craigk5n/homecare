<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * Per-schedule throttle for the HC-238 PRN limit alert.
 *
 * One row per schedule: the dose that triggered the last alert
 * (`dose_at`) and the level it reported ('reached' / 'exceeded').
 * Same shape and contract as {@see LateDoseAlertLogInterface}.
 */
interface PrnLimitAlertLogInterface
{
    /**
     * @return array{dose_at:string, level:string}|null null when no
     *         alert has fired for this schedule yet
     */
    public function lastAlert(int $scheduleId): ?array;

    /**
     * Upsert one row: this schedule last alerted about $doseAt at
     * $level, delivered at $sentAt.
     */
    public function markSent(int $scheduleId, string $doseAt, string $level, string $sentAt): void;
}
