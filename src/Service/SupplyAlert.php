<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * One low-supply alert about a medication. Immutable; carries just
 * enough context for the push-notification wrapper to render a
 * message and for the audit/test layers to identify which medicine.
 */
final class SupplyAlert
{
    public function __construct(
        public readonly int $medicineId,
        public readonly string $medicineName,
        public readonly int $remainingDays,
        public readonly string $projectedDepletion,
    ) {}

    /**
     * Human-readable line for the ntfy body.
     */
    public function message(): string
    {
        $dayWord = $this->remainingDays === 1 ? 'day' : 'days';

        return sprintf(
            'Low supply: %s — %d %s of supply left (projected depletion %s)',
            $this->medicineName,
            $this->remainingDays,
            $dayWord,
            $this->projectedDepletion,
        );
    }
}
