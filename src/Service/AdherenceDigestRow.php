<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * One medication row inside a patient's digest section.
 *
 * `sevenDayPct` and `thirtyDayPct` are ALREADY computed (rounded
 * to one decimal place, matching `AdherenceService`'s output shape).
 * The builder just formats them; the caller owns the maths.
 */
final readonly class AdherenceDigestRow
{
    public function __construct(
        public string $medicineName,
        public float $sevenDayPct,
        public float $thirtyDayPct,
    ) {
    }
}
