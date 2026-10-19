<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Notification\NotificationMessage;

/**
 * Value object for one pending PRN limit alert (HC-238).
 *
 * Built by {@see PrnLimitAlertService::findPendingAlerts()}, handed to
 * `send_reminders.php` which renders it through the channel registry.
 * `lastDoseAt` + `level` are the throttle-log key.
 */
final readonly class PrnLimitAlert
{
    public function __construct(
        public int $scheduleId,
        public string $medicineName,
        public string $patientName,
        /** {@see PrnLimitStatus::LEVEL_REACHED} or LEVEL_EXCEEDED. */
        public string $level,
        public int $dosesInWindow,
        public int $maxDoses,
        /** Y-m-d H:i:s of the most recent dose in the window. */
        public string $lastDoseAt,
        /** Y-m-d H:i:s when the next dose is within limits; null = unknown. */
        public ?string $nextAllowedAt,
    ) {}

    public function isExceeded(): bool
    {
        return $this->level === PrnLimitStatus::LEVEL_EXCEEDED;
    }

    public function title(): string
    {
        return ($this->isExceeded() ? 'PRN limit exceeded: ' : 'PRN limit reached: ')
            . $this->medicineName;
    }

    /**
     * Over the limit is a red-card event; merely reaching it is an
     * informational "don't give another one yet".
     */
    public function priority(): int
    {
        return $this->isExceeded()
            ? NotificationMessage::PRIORITY_HIGH
            : NotificationMessage::PRIORITY_DEFAULT;
    }

    public function message(): string
    {
        $last = date('H:i', strtotime($this->lastDoseAt) ?: 0);
        $who = "{$this->medicineName} for {$this->patientName}: ";

        if ($this->isExceeded()) {
            return $who . "{$this->dosesInWindow} doses in the last 24 hours, "
                . "over the limit of {$this->maxDoses} (last at {$last}).";
        }

        $msg = $who . "{$this->dosesInWindow} of {$this->maxDoses} doses in the last 24 hours "
            . "(last at {$last}).";
        if ($this->nextAllowedAt !== null) {
            $msg .= ' Next dose allowed at ' . substr($this->nextAllowedAt, 0, 16) . '.';
        }

        return $msg;
    }
}
