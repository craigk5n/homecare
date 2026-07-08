<?php

declare(strict_types=1);

namespace HomeCare\Export;

/**
 * Render intake rows as a plain-text report, grouped by calendar day.
 *
 * Meant for the "download / email me a .txt copy" path: a caregiver can
 * paste it straight into a message, a notes app, or a printout without a
 * spreadsheet or FHIR viewer. Sibling of {@see CsvIntakeExporter} —
 * consumes the same {@see IntakeExportQuery} row shape.
 *
 * @phpstan-import-type IntakeExportRow from IntakeExportQuery
 */
final class TextIntakeExporter
{
    /**
     * @param list<IntakeExportRow>                                              $rows
     * @param array{patient_name?:string,period_label?:string,generated_at?:string} $meta
     */
    public function toText(array $rows, array $meta = []): string
    {
        $patientName = $meta['patient_name'] ?? ($rows[0]['patient_name'] ?? 'Patient');

        $out = ['Intake History — ' . $patientName];
        if (isset($meta['period_label']) && $meta['period_label'] !== '') {
            $out[] = 'Period: ' . $meta['period_label'];
        }
        if (isset($meta['generated_at']) && $meta['generated_at'] !== '') {
            $out[] = 'Generated: ' . $meta['generated_at'];
        }
        $out[] = '';

        if ($rows === []) {
            $out[] = 'No intakes recorded for this period.';

            return implode("\n", $out) . "\n";
        }

        $days = ExportRowGrouping::byDay($rows);
        foreach ($days as $day) {
            $out[] = $day['heading'];
            foreach ($day['rows'] as $row) {
                $out[] = '  ' . self::formatLine($row);
            }
            $out[] = '';
        }

        $out[] = sprintf(
            'Total: %d %s across %d %s.',
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
    private static function formatLine(array $row): string
    {
        $time = strlen($row['taken_time']) >= 16 ? substr($row['taken_time'], 11, 5) : '';
        $line = $time . '  ' . $row['medicine_name'];
        if ($row['medicine_dosage'] !== '') {
            $line .= ' (' . $row['medicine_dosage'] . ')';
        }
        $line .= ' ×' . ExportRowGrouping::formatFloat($row['unit_per_dose']);
        if (($row['note'] ?? '') !== '') {
            // Collapse newlines so one intake stays on one line.
            $note = trim(preg_replace('/\s+/', ' ', (string) $row['note']) ?? '');
            if ($note !== '') {
                $line .= ' — ' . $note;
            }
        }

        return $line;
    }
}
