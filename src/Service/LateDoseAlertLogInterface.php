<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * Per-schedule throttle for the HC-105 late-dose alert.
 *
 * One row per schedule: the exact due instant we last alerted about
 * (`last_due_at`) plus when the alert went out (`sent_at`). The
 * service compares `currentDueAt` against `last_due_at` to know
 * whether the caregiver has already been pinged for THIS specific
 * miss — different due instant = fair game again.
 */
interface LateDoseAlertLogInterface
{
    /**
     * Return the last-alerted due instant for this schedule, or
     * null when no alert has fired yet.
     */
    public function lastDueAt(int $scheduleId): ?string;

    /**
     * Upsert one row: this schedule last alerted about $dueAt at $sentAt.
     */
    public function markSent(int $scheduleId, string $dueAt, string $sentAt): void;
}
