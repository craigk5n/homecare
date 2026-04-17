<?php

declare(strict_types=1);

namespace HomeCare\Import;

use HomeCare\Database\DatabaseInterface;
use InvalidArgumentException;

/**
 * Import schedules and intakes from CSV files (HC-131).
 *
 * Intake CSV matches the shape emitted by CsvIntakeExporter:
 *   Date, Time, Medication, Dosage, Frequency, UnitPerDose, Notes
 *
 * Schedule CSV (optional):
 *   PatientName, Medication, Dosage, Frequency, UnitPerDose, StartDate, EndDate
 *
 * @phpstan-type ImportRow array{
 *     line:int,
 *     status:'ok'|'skipped'|'error',
 *     message:string,
 *     data:array<string,string>
 * }
 * @phpstan-type ImportResult array{
 *     total:int,
 *     imported:int,
 *     skipped:int,
 *     errors:int,
 *     rows:list<ImportRow>
 * }
 */
final class CsvImportService
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly bool $createMissing = false,
    ) {}

    /**
     * Validate an intake CSV without committing. Returns a preview.
     *
     * @return ImportResult
     */
    public function previewIntakes(string $csvContent, int $patientId): array
    {
        return $this->processIntakes($csvContent, $patientId, dryRun: true);
    }

    /**
     * Import an intake CSV, committing inside a transaction.
     *
     * @return ImportResult
     */
    public function importIntakes(string $csvContent, int $patientId): array
    {
        return $this->db->transactional(
            fn() => $this->processIntakes($csvContent, $patientId, dryRun: false),
        );
    }

    /**
     * Validate a schedule CSV without committing.
     *
     * @return ImportResult
     */
    public function previewSchedules(string $csvContent): array
    {
        return $this->processSchedules($csvContent, dryRun: true);
    }

    /**
     * Import a schedule CSV, committing inside a transaction.
     *
     * @return ImportResult
     */
    public function importSchedules(string $csvContent): array
    {
        return $this->db->transactional(
            fn() => $this->processSchedules($csvContent, dryRun: false),
        );
    }

    /**
     * @return ImportResult
     */
    private function processIntakes(string $csvContent, int $patientId, bool $dryRun): array
    {
        $rows = $this->parseCsv($csvContent);
        if ($rows === []) {
            throw new InvalidArgumentException('CSV file is empty or has no data rows');
        }

        $header = array_map('strtolower', array_map('trim', array_keys($rows[0])));
        $this->requireColumns($header, ['date', 'time', 'medication']);

        $result = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'rows' => []];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // +1 header, +1 zero-index
            $medName = trim($row['medication'] ?? $row['Medication'] ?? '');
            $dosage = trim($row['dosage'] ?? $row['Dosage'] ?? '');
            $frequency = trim($row['frequency'] ?? $row['Frequency'] ?? '');
            $unitPerDose = trim($row['unitperdose'] ?? $row['UnitPerDose'] ?? '1');
            $date = trim($row['date'] ?? $row['Date'] ?? '');
            $time = trim($row['time'] ?? $row['Time'] ?? '');
            $note = trim($row['notes'] ?? $row['Notes'] ?? '');

            if ($medName === '' || $date === '') {
                $result['errors']++;
                $result['rows'][] = ['line' => $line, 'status' => 'error', 'message' => 'Missing medication or date', 'data' => $row];
                continue;
            }

            $takenTime = $date . ($time !== '' ? ' ' . $time : ' 00:00:00');

            $medicineId = $this->resolveMedicine($medName, $dosage);
            if ($medicineId === null) {
                $result['errors']++;
                $result['rows'][] = ['line' => $line, 'status' => 'error', 'message' => "Medicine not found: $medName", 'data' => $row];
                continue;
            }

            $scheduleId = $this->resolveSchedule($patientId, $medicineId, $frequency, (float) $unitPerDose, $date);
            if ($scheduleId === null) {
                $result['errors']++;
                $result['rows'][] = ['line' => $line, 'status' => 'error', 'message' => "No matching schedule for $medName", 'data' => $row];
                continue;
            }

            if ($this->intakeExists($scheduleId, $takenTime)) {
                $result['skipped']++;
                $result['rows'][] = ['line' => $line, 'status' => 'skipped', 'message' => 'Already exists', 'data' => $row];
                continue;
            }

            if (!$dryRun) {
                $this->db->execute(
                    'INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES (?, ?, ?)',
                    [$scheduleId, $takenTime, $note !== '' ? $note : null],
                );
            }

            $result['imported']++;
            $result['rows'][] = ['line' => $line, 'status' => 'ok', 'message' => 'OK', 'data' => $row];
        }

        return $result;
    }

    /**
     * @return ImportResult
     */
    private function processSchedules(string $csvContent, bool $dryRun): array
    {
        $rows = $this->parseCsv($csvContent);
        if ($rows === []) {
            throw new InvalidArgumentException('CSV file is empty or has no data rows');
        }

        $header = array_map('strtolower', array_map('trim', array_keys($rows[0])));
        $this->requireColumns($header, ['patientname', 'medication', 'frequency', 'startdate']);

        $result = ['total' => count($rows), 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'rows' => []];

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $patientName = trim($row['patientname'] ?? $row['PatientName'] ?? '');
            $medName = trim($row['medication'] ?? $row['Medication'] ?? '');
            $dosage = trim($row['dosage'] ?? $row['Dosage'] ?? '');
            $frequency = trim($row['frequency'] ?? $row['Frequency'] ?? '');
            $unitPerDose = trim($row['unitperdose'] ?? $row['UnitPerDose'] ?? '1');
            $startDate = trim($row['startdate'] ?? $row['StartDate'] ?? '');
            $endDate = trim($row['enddate'] ?? $row['EndDate'] ?? '');

            if ($patientName === '' || $medName === '' || $startDate === '') {
                $result['errors']++;
                $result['rows'][] = ['line' => $line, 'status' => 'error', 'message' => 'Missing required field', 'data' => $row];
                continue;
            }

            $patientId = $this->resolvePatient($patientName);
            if ($patientId === null) {
                $result['errors']++;
                $result['rows'][] = ['line' => $line, 'status' => 'error', 'message' => "Patient not found: $patientName", 'data' => $row];
                continue;
            }

            $medicineId = $this->resolveMedicine($medName, $dosage);
            if ($medicineId === null) {
                $result['errors']++;
                $result['rows'][] = ['line' => $line, 'status' => 'error', 'message' => "Medicine not found: $medName", 'data' => $row];
                continue;
            }

            $existing = $this->resolveSchedule($patientId, $medicineId, $frequency, (float) $unitPerDose, $startDate);
            if ($existing !== null) {
                $result['skipped']++;
                $result['rows'][] = ['line' => $line, 'status' => 'skipped', 'message' => 'Schedule already exists', 'data' => $row];
                continue;
            }

            if (!$dryRun) {
                $this->db->execute(
                    'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$patientId, $medicineId, $startDate, $endDate !== '' ? $endDate : null, $frequency, (float) $unitPerDose],
                );
            }

            $result['imported']++;
            $result['rows'][] = ['line' => $line, 'status' => 'ok', 'message' => 'OK', 'data' => $row];
        }

        return $result;
    }

    /**
     * @return list<array<string,string>>
     */
    private function parseCsv(string $content): array
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open php://temp');
        }
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);

            return [];
        }

        $header = array_map(static fn(?string $v): string => trim((string) $v), $header);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null]) {
                continue;
            }
            /** @var array<string,string> $assoc */
            $assoc = [];
            foreach ($header as $idx => $col) {
                $assoc[strtolower($col)] = (string) ($line[$idx] ?? '');
            }
            $rows[] = $assoc;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param list<string> $actual
     * @param list<string> $required
     */
    private function requireColumns(array $actual, array $required): void
    {
        foreach ($required as $col) {
            if (!in_array($col, $actual, true)) {
                throw new InvalidArgumentException("Missing required CSV column: $col");
            }
        }
    }

    private function resolvePatient(string $name): ?int
    {
        $rows = $this->db->query(
            'SELECT id FROM hc_patients WHERE name = ? LIMIT 1',
            [$name],
        );

        if ($rows !== []) {
            return (int) $rows[0]['id'];
        }

        if ($this->createMissing) {
            $this->db->execute('INSERT INTO hc_patients (name) VALUES (?)', [$name]);

            return $this->db->lastInsertId();
        }

        return null;
    }

    private function resolveMedicine(string $name, string $dosage): ?int
    {
        $rows = $this->db->query(
            'SELECT id FROM hc_medicines WHERE name = ? LIMIT 1',
            [$name],
        );

        if ($rows !== []) {
            return (int) $rows[0]['id'];
        }

        if ($this->createMissing && $dosage !== '') {
            $this->db->execute(
                'INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)',
                [$name, $dosage],
            );

            return $this->db->lastInsertId();
        }

        return null;
    }

    private function resolveSchedule(int $patientId, int $medicineId, string $frequency, float $unitPerDose, string $date): ?int
    {
        $rows = $this->db->query(
            'SELECT id FROM hc_medicine_schedules
             WHERE patient_id = ? AND medicine_id = ?
               AND start_date <= ?
               AND (end_date IS NULL OR end_date >= ?)
             ORDER BY id DESC LIMIT 1',
            [$patientId, $medicineId, $date, $date],
        );

        return $rows !== [] ? (int) $rows[0]['id'] : null;
    }

    private function intakeExists(int $scheduleId, string $takenTime): bool
    {
        $rows = $this->db->query(
            'SELECT 1 FROM hc_medicine_intake WHERE schedule_id = ? AND taken_time = ? LIMIT 1',
            [$scheduleId, $takenTime],
        );

        return $rows !== [];
    }
}
