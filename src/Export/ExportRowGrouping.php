<?php

declare(strict_types=1);

namespace HomeCare\Export;

/**
 * Small shared helpers for the human-readable intake exporters
 * ({@see TextIntakeExporter}, {@see MarkdownIntakeExporter}). Kept in one
 * place so the two formats group and format doses identically.
 *
 * @phpstan-import-type IntakeExportRow from IntakeExportQuery
 */
final class ExportRowGrouping
{
    /**
     * Group rows into calendar-day buckets, preserving row order within
     * each day. Rows are assumed to arrive sorted ascending by
     * `taken_time` (as IntakeExportQuery returns them).
     *
     * @param list<IntakeExportRow> $rows
     *
     * @return list<array{heading:string,date:string,rows:list<IntakeExportRow>}>
     */
    public static function byDay(array $rows): array
    {
        /** @var array<string, list<IntakeExportRow>> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $date = substr($row['taken_time'], 0, 10);
            $buckets[$date][] = $row;
        }

        $days = [];
        foreach ($buckets as $date => $dayRows) {
            $ts = strtotime((string) $date) ?: 0;
            $days[] = [
                'heading' => date('l, F j, Y', $ts),
                'date' => (string) $date,
                'rows' => $dayRows,
            ];
        }

        return $days;
    }

    /**
     * Trim trailing zeros so "1.00" reads as "1" but "1.50" reads as "1.5".
     */
    public static function formatFloat(float $value): string
    {
        $s = rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');

        return $s === '' ? '0' : $s;
    }
}
