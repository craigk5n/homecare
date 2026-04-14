<?php

declare(strict_types=1);

namespace HomeCare\Export;

/**
 * Render intake rows as RFC-4180 CSV via `fputcsv()`.
 *
 * Output columns (in order):
 *   Date, Time, Medication, Dosage, Frequency, UnitPerDose, Notes
 *
 * The acceptance criteria call out "Date, Time, Medication, Frequency,
 * Notes" as the required columns; we also include `Dosage` and
 * `UnitPerDose` because a CSV that only has frequency is ambiguous
 * about what one row of "intake" actually represents clinically.
 *
 * @phpstan-import-type IntakeExportRow from IntakeExportQuery
 */
final class CsvIntakeExporter
{
    /** @var list<string> */
    public const HEADERS = ['Date', 'Time', 'Medication', 'Dosage', 'Frequency', 'UnitPerDose', 'Notes'];

    /**
     * @param list<IntakeExportRow> $rows
     */
    public function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open php://temp for CSV writing');
        }

        fputcsv($handle, self::HEADERS);
        foreach ($rows as $row) {
            fputcsv($handle, self::formatRow($row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param IntakeExportRow $row
     *
     * @return list<string>
     */
    private static function formatRow(array $row): array
    {
        $takenTime = $row['taken_time'];
        // hc_medicine_intake.taken_time is stored "YYYY-MM-DD HH:MM:SS".
        $date = substr($takenTime, 0, 10);
        $time = strlen($takenTime) >= 19 ? substr($takenTime, 11, 8) : '';

        return [
            $date,
            $time,
            $row['medicine_name'],
            $row['medicine_dosage'],
            $row['frequency'],
            self::formatFloat($row['unit_per_dose']),
            $row['note'] ?? '',
        ];
    }

    private static function formatFloat(float $value): string
    {
        // Trim trailing zeros so "1.00" reads as "1" but "1.50" reads as "1.5".
        $s = rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');

        return $s === '' ? '0' : $s;
    }
}
