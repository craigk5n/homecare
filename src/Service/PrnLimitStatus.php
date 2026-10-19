<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * Snapshot of a PRN schedule's dose allowance at one instant (HC-238).
 *
 * Built by {@see PrnLimitService}. "Evaluated at" is either the moment
 * a caregiver proposes a dose (before recording — is it within
 * limits?) or the moment a dose was just recorded (after recording —
 * did it reach the ceiling?). The same object answers both:
 *
 *   - {@see wouldExceedMax()} / {@see tooSoon}: one MORE dose at
 *     `evaluatedAt` would break a limit. Drives the confirm step.
 *   - {@see limitLevel()}: the doses already counted (including one
 *     recorded at `evaluatedAt`) have reached / exceeded the ceiling.
 *     Drives the notification.
 */
final readonly class PrnLimitStatus
{
    public const LEVEL_REACHED = 'reached';
    public const LEVEL_EXCEEDED = 'exceeded';

    public function __construct(
        public ?int $maxDosesPer24h,
        public ?float $minHoursBetween,
        /** Intakes in the 24h window ending at (and including) evaluatedAt. */
        public int $dosesInWindow,
        /** Y-m-d H:i:s of the latest intake at or before evaluatedAt. */
        public ?string $lastTakenAt,
        /** Y-m-d H:i:s when another dose is within limits; null = now. */
        public ?string $nextAllowedAt,
        /** True when the min-hours-between rule is not yet satisfied. */
        public bool $tooSoon,
        public string $evaluatedAt,
    ) {}

    /** Doses still allowed in the current window; null when no max. */
    public function remaining(): ?int
    {
        if ($this->maxDosesPer24h === null) {
            return null;
        }

        return max(0, $this->maxDosesPer24h - $this->dosesInWindow);
    }

    /** One more dose now would put the window over the max. */
    public function wouldExceedMax(): bool
    {
        return $this->maxDosesPer24h !== null
            && $this->dosesInWindow >= $this->maxDosesPer24h;
    }

    /** Recording a dose now deserves a confirm step. */
    public function isWarning(): bool
    {
        return $this->wouldExceedMax() || $this->tooSoon;
    }

    /**
     * 'reached' when the window holds exactly the max, 'exceeded' when
     * it holds more, null otherwise (or when no max is set).
     */
    public function limitLevel(): ?string
    {
        if ($this->maxDosesPer24h === null) {
            return null;
        }
        if ($this->dosesInWindow > $this->maxDosesPer24h) {
            return self::LEVEL_EXCEEDED;
        }
        if ($this->dosesInWindow === $this->maxDosesPer24h) {
            return self::LEVEL_REACHED;
        }

        return null;
    }

    /**
     * Plain-English warning for a proposed dose, or null when the dose
     * is within limits. Used verbatim in the API `warning` field.
     */
    public function warningMessage(): ?string
    {
        $parts = [];
        if ($this->wouldExceedMax()) {
            $parts[] = "{$this->dosesInWindow} of {$this->maxDosesPer24h} doses already given in the last 24 hours.";
        }
        if ($this->tooSoon && $this->minHoursBetween !== null) {
            $parts[] = 'Doses should be at least ' . self::formatHours($this->minHoursBetween) . ' hours apart.';
        }
        if ($parts === []) {
            return null;
        }
        if ($this->nextAllowedAt !== null) {
            $parts[] = 'Next dose allowed at ' . substr($this->nextAllowedAt, 0, 16) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{
     *     max_doses_per_24h:?int,
     *     min_hours_between:?float,
     *     doses_in_last_24h:int,
     *     remaining:?int,
     *     last_taken_at:?string,
     *     next_allowed_at:?string,
     *     would_exceed_max:bool,
     *     too_soon:bool
     * }
     */
    public function toArray(): array
    {
        return [
            'max_doses_per_24h' => $this->maxDosesPer24h,
            'min_hours_between' => $this->minHoursBetween,
            'doses_in_last_24h' => $this->dosesInWindow,
            'remaining' => $this->remaining(),
            'last_taken_at' => $this->lastTakenAt,
            'next_allowed_at' => $this->nextAllowedAt,
            'would_exceed_max' => $this->wouldExceedMax(),
            'too_soon' => $this->tooSoon,
        ];
    }

    private static function formatHours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }
}
