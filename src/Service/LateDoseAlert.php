<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * Value object for one pending late-dose alert.
 *
 * Built by {@see LateDoseAlertService::findPendingAlerts()}, handed
 * to `send_reminders.php` which renders it through the channel
 * registry. `dueAt` is the canonical DATETIME string used for both
 * message rendering AND the throttle-log upsert key — one source
 * of truth.
 */
final readonly class LateDoseAlert
{
    public function __construct(
        public int $scheduleId,
        public string $medicineName,
        public string $patientName,
        /** Y-m-d H:i:s when the dose was expected. */
        public string $dueAt,
        public int $minutesLate,
    ) {
    }

    /**
     * Short user-facing line for the alert body. Paired with the
     * title "Late dose: {medicine}" at the channel layer.
     */
    public function message(): string
    {
        $dueDisplay = date('H:i', strtotime($this->dueAt) ?: 0);

        return "{$this->medicineName} for {$this->patientName} was due at "
            . "{$dueDisplay} — {$this->minutesLate} minutes late.";
    }
}
