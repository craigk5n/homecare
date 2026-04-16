<?php

declare(strict_types=1);

namespace HomeCare\Service;

/**
 * Render the plain-text body of the weekly adherence digest (HC-107).
 *
 * Pure function — no DB, no date math beyond formatting the run date.
 * Caller owns the adherence calculations and hands in a list of
 * {@see AdherenceDigestPatient} sections; the builder just lays them
 * out.
 *
 * Output shape:
 *
 *     Weekly adherence digest — {date}
 *
 *     Daisy
 *       Medication            7-day   30-day
 *       Tobra                 ✓ 100%  ✓ 95%
 *       Oxetene               ⚠ 85%   ✓ 92%
 *
 *     Fozzie
 *       No intakes this week.
 *
 * Colour markers follow the spec:
 *     ✓  ≥ 90%
 *     ⚠  70%-89%
 *     ✗  < 70%
 */
final class AdherenceDigestBuilder
{
    public const THRESHOLD_OK = 90.0;
    public const THRESHOLD_WARN = 70.0;

    private const MEDICINE_COL_WIDTH = 22;

    /**
     * @param list<AdherenceDigestPatient> $patients
     */
    public function build(string $runDate, array $patients): string
    {
        $lines = [
            "Weekly adherence digest — {$runDate}",
            '',
        ];

        if ($patients === []) {
            $lines[] = 'No patients are active yet.';
            $lines[] = '';

            return implode("\n", $lines);
        }

        foreach ($patients as $patient) {
            $lines[] = $patient->patientName;
            if ($patient->rows === []) {
                $lines[] = '  No intakes this week.';
                $lines[] = '';
                continue;
            }

            $lines[] = '  ' . $this->pad('Medication', self::MEDICINE_COL_WIDTH)
                . $this->padLeft('7-day', 8)
                . $this->padLeft('30-day', 10);

            foreach ($patient->rows as $row) {
                $lines[] = '  '
                    . $this->pad($row->medicineName, self::MEDICINE_COL_WIDTH)
                    . $this->padLeft($this->formatCell($row->sevenDayPct), 8)
                    . $this->padLeft($this->formatCell($row->thirtyDayPct), 10);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * "✓ 95%" / "⚠ 72%" / "✗ 48%" with no trailing decimals when
     * the value is a whole number (cleaner on mobile mail clients).
     */
    private function formatCell(float $pct): string
    {
        $marker = match (true) {
            $pct >= self::THRESHOLD_OK => '✓',
            $pct >= self::THRESHOLD_WARN => '⚠',
            default => '✗',
        };

        $formatted = abs($pct - round($pct)) < 0.05
            ? (string) (int) round($pct)
            : (string) round($pct, 1);

        return $marker . ' ' . $formatted . '%';
    }

    private function pad(string $s, int $width): string
    {
        // str_pad counts bytes, which matches our ASCII-heavy cells.
        // Unicode-heavy medicine names may mis-align by a column or
        // two; acceptable tradeoff vs. requiring PHP 8.3's
        // mb_str_pad across the board.
        return str_pad($s, $width);
    }

    private function padLeft(string $s, int $width): string
    {
        return str_pad($s, $width, ' ', STR_PAD_LEFT);
    }
}
