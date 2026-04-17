<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * One patient section inside the weekly digest.
 *
 * `rows` comes out of the cron's adherence loop for every active
 * schedule; an empty list is rendered as "no intakes this week" by
 * the builder rather than a blank table.
 */
final readonly class AdherenceDigestPatient
{
    /**
     * @param list<AdherenceDigestRow> $rows
     */
    public function __construct(
        public string $patientName,
        public array $rows = [],
    ) {}
}
