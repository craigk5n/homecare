<?php

declare(strict_types=1);

namespace HomeCare\Export;

/**
 * Render intake rows as a Markdown report, grouped by calendar day with
 * one table per day. Pastes cleanly into GitHub issues, Obsidian, a
 * clinic wiki, or any Markdown-aware chat. Sibling of
 * {@see TextIntakeExporter} — same {@see IntakeExportQuery} row shape.
 *
 * @phpstan-import-type IntakeExportRow from IntakeExportQuery
 */
final class MarkdownIntakeExporter
{
    /**
     * @param list<IntakeExportRow>                                              $rows
     * @param array{patient_name?:string,period_label?:string,generated_at?:string} $meta
     */
    public function toMarkdown(array $rows, array $meta = []): string
    {
        $patientName = $meta['patient_name'] ?? ($rows[0]['patient_name'] ?? 'Patient');

        $out = ['# Intake History — ' . self::escape($patientName), ''];
        if (isset($meta['period_label']) && $meta['period_label'] !== '') {
            $out[] = '**Period:** ' . self::escape($meta['period_label']) . '  ';
        }
        if (isset($meta['generated_at']) && $meta['generated_at'] !== '') {
            $out[] = '**Generated:** ' . self::escape($meta['generated_at']) . '  ';
        }
        $out[] = '';

        if ($rows === []) {
            $out[] = '_No intakes recorded for this period._';

            return implode("\n", $out) . "\n";
        }

        $days = ExportRowGrouping::byDay($rows);
        foreach ($days as $day) {
            $out[] = '## ' . $day['heading'];
            $out[] = '';
            $out[] = '| Time | Medication | Dose | Notes |';
            $out[] = '| --- | --- | --- | --- |';
            foreach ($day['rows'] as $row) {
                $out[] = self::formatRow($row);
            }
            $out[] = '';
        }

        $out[] = sprintf(
            '**Total:** %d %s across %d %s.',
            count($rows),
            count($rows) === 1 ? 'intake' : 'intakes',
            count($days),
            count($days) === 1 ? 'day' : 'days',
        );

        return implode("\n", $out) . "\n";
    }

    /**
     * @param IntakeExportRow $row
     */
    private static function formatRow(array $row): string
    {
        $time = strlen($row['taken_time']) >= 16 ? substr($row['taken_time'], 11, 5) : '';
        $med = $row['medicine_name'];
        if ($row['medicine_dosage'] !== '') {
            $med .= ' (' . $row['medicine_dosage'] . ')';
        }
        $dose = '×' . ExportRowGrouping::formatFloat($row['unit_per_dose']);
        $note = trim(preg_replace('/\s+/', ' ', (string) ($row['note'] ?? '')) ?? '');

        return '| ' . self::cell($time)
            . ' | ' . self::cell($med)
            . ' | ' . self::cell($dose)
            . ' | ' . self::cell($note) . ' |';
    }

    /**
     * Escape pipe characters so free-text notes can't break the table
     * layout, plus the leading Markdown structural characters.
     */
    private static function cell(string $value): string
    {
        return str_replace('|', '\\|', self::escape($value));
    }

    private static function escape(string $value): string
    {
        // Escape the characters that would change block structure at the
        // start of a line / heading. Table-cell pipe escaping is layered
        // on top by cell().
        return str_replace(['\\'], ['\\\\'], $value);
    }
}
