<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Export;

use HomeCare\Export\CsvIntakeExporter;
use HomeCare\Export\IntakeExportQuery;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class IntakeCsvExportTest extends DatabaseTestCase
{
    private int $patientId;
    private int $medicineId;
    private int $scheduleId;
    private IntakeExportQuery $query;
    private CsvIntakeExporter $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->query = new IntakeExportQuery($db);
        $this->csv = new CsvIntakeExporter();

        $this->patientId = (new PatientFactory($db))->create(['name' => 'Daisy'])['id'];
        $this->medicineId = (new MedicineFactory($db))
            ->create(['name' => 'Sildenafil', 'dosage' => '20mg'])['id'];
        $this->scheduleId = (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $this->medicineId,
            'frequency' => '8h',
            'unit_per_dose' => 1.5,
        ])['id'];
    }

    public function testEmptyResultProducesHeadersOnly(): void
    {
        $csv = $this->csv->toCsv($this->query->fetch($this->patientId, '2026-04-01', '2026-04-30'));

        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertCount(1, $lines, 'only the header row should be present');
        $this->assertSame(
            'Date,Time,Medication,Dosage,Frequency,UnitPerDose,Notes',
            $lines[0]
        );
    }

    public function testDataRowsMatchDatabase(): void
    {
        (new IntakeFactory($this->getDb()))->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-04-05 08:00:00',
            'note' => 'with food',
        ]);
        (new IntakeFactory($this->getDb()))->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-04-05 16:30:00',
        ]);

        $csv = $this->csv->toCsv($this->query->fetch($this->patientId, '2026-04-01', '2026-04-30'));
        $rows = self::parseCsv($csv);

        $this->assertCount(3, $rows); // header + 2 data rows
        $this->assertSame(
            ['2026-04-05', '08:00:00', 'Sildenafil', '20mg', '8h', '1.5', 'with food'],
            $rows[1]
        );
        $this->assertSame(
            ['2026-04-05', '16:30:00', 'Sildenafil', '20mg', '8h', '1.5', ''],
            $rows[2]
        );
    }

    public function testDateRangeFiltering(): void
    {
        $f = new IntakeFactory($this->getDb());
        $f->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-03-28 10:00:00']);
        $f->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-04-05 08:00:00']);
        $f->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-05-10 08:00:00']);

        $csv = $this->csv->toCsv($this->query->fetch($this->patientId, '2026-04-01', '2026-04-30'));
        $rows = array_values(array_filter(explode("\n", $csv)));

        $this->assertCount(2, $rows, 'only the April row plus header');
        $this->assertStringContainsString('2026-04-05', $rows[1]);
    }

    public function testSpecialCharactersAreEscaped(): void
    {
        $db = $this->getDb();
        $msgMed = (new MedicineFactory($db))->create([
            'name' => 'Acme, Co "Super" Relief',
            'dosage' => 'a "strong" 100mg',
        ])['id'];
        $sched = (new ScheduleFactory($db))->create([
            'patient_id' => $this->patientId,
            'medicine_id' => $msgMed,
            'frequency' => '1d',
            'unit_per_dose' => 1.0,
        ])['id'];
        (new IntakeFactory($db))->create([
            'schedule_id' => $sched,
            'taken_time' => '2026-04-05 08:00:00',
            'note' => "line1\nline2, with comma",
        ]);

        $csv = $this->csv->toCsv($this->query->fetch($this->patientId, '2026-04-01', '2026-04-30'));
        $rows = self::parseCsvWithEmbeddedNewlines($csv);

        // fputcsv wraps fields containing commas or quotes in double quotes
        // and doubles any internal quotes. Sanity-check: round-trip via
        // str_getcsv gives us the original strings back.
        $this->assertSame('Acme, Co "Super" Relief', $rows[1][2]);
        $this->assertSame("line1\nline2, with comma", $rows[1][6]);
    }

    /**
     * @return list<list<string>>
     */
    private static function parseCsv(string $csv): array
    {
        $parsed = [];
        foreach (array_values(array_filter(explode("\n", $csv))) as $line) {
            /** @var list<string> $fields */
            $fields = str_getcsv($line);
            $parsed[] = $fields;
        }

        return $parsed;
    }

    /**
     * Round-trip CSV that may contain embedded newlines inside quoted
     * fields via a temp stream so fgetcsv() handles the quoting.
     *
     * @return list<list<string>>
     */
    private static function parseCsvWithEmbeddedNewlines(string $csv): array
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            return [];
        }
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            /** @var list<string> $row */
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
