<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Import;

use HomeCare\Import\CsvImportService;
use HomeCare\Tests\Integration\DatabaseTestCase;
use InvalidArgumentException;

final class CsvImportServiceTest extends DatabaseTestCase
{
    private int $patientId;
    private int $medicineId;
    private int $scheduleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patientId = $this->seedPatient('Daisy');
        $this->medicineId = $this->seedMedicine('Amoxicillin', '500mg');
        $this->scheduleId = $this->seedSchedule($this->patientId, $this->medicineId, '2026-01-01', '8h');
    }

    // -- Intake import --

    public function testPreviewIntakesValidatesRows(): void
    {
        $csv = "Date,Time,Medication,Dosage,Frequency,UnitPerDose,Notes\n"
             . "2026-04-01,08:00:00,Amoxicillin,500mg,8h,1,Morning dose\n"
             . "2026-04-01,16:00:00,Amoxicillin,500mg,8h,1,Afternoon dose\n";

        $service = new CsvImportService($this->getDb());
        $result = $service->previewIntakes($csv, $this->patientId);

        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['errors']);
    }

    public function testImportIntakesCreatesRecords(): void
    {
        $csv = "Date,Time,Medication,Dosage,Frequency,UnitPerDose,Notes\n"
             . "2026-04-01,08:00:00,Amoxicillin,500mg,8h,1,Morning dose\n";

        $service = new CsvImportService($this->getDb());
        $result = $service->importIntakes($csv, $this->patientId);

        $this->assertSame(1, $result['imported']);

        $rows = $this->getDb()->query(
            'SELECT * FROM hc_medicine_intake WHERE schedule_id = ?',
            [$this->scheduleId],
        );
        $this->assertCount(1, $rows);
        $this->assertSame('2026-04-01 08:00:00', $rows[0]['taken_time']);
    }

    public function testImportIntakesSkipsDuplicates(): void
    {
        $this->getDb()->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time) VALUES (?, ?)',
            [$this->scheduleId, '2026-04-01 08:00:00'],
        );

        $csv = "Date,Time,Medication,Dosage,Frequency,UnitPerDose,Notes\n"
             . "2026-04-01,08:00:00,Amoxicillin,500mg,8h,1,\n";

        $service = new CsvImportService($this->getDb());
        $result = $service->importIntakes($csv, $this->patientId);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('Already exists', $result['rows'][0]['message']);
    }

    public function testImportIntakesReportsUnknownMedicine(): void
    {
        $csv = "Date,Time,Medication,Dosage,Frequency,UnitPerDose,Notes\n"
             . "2026-04-01,08:00:00,UnknownDrug,10mg,1d,1,\n";

        $service = new CsvImportService($this->getDb());
        $result = $service->importIntakes($csv, $this->patientId);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['errors']);
        $this->assertStringContainsString('Medicine not found', $result['rows'][0]['message']);
    }

    public function testImportIntakesCreatesMissingMedicineWhenFlagged(): void
    {
        $csv = "Date,Time,Medication,Dosage,Frequency,UnitPerDose,Notes\n"
             . "2026-04-01,08:00:00,BrandNewDrug,50mg,1d,1,\n";

        // Need a schedule for this new medicine
        $service = new CsvImportService($this->getDb(), createMissing: true);
        $result = $service->importIntakes($csv, $this->patientId);

        // Medicine created but no schedule exists for it yet → error
        $this->assertSame(1, $result['errors']);
        $this->assertStringContainsString('No matching schedule', $result['rows'][0]['message']);

        // But the medicine was created
        $rows = $this->getDb()->query("SELECT id FROM hc_medicines WHERE name = 'BrandNewDrug'");
        $this->assertCount(1, $rows);
    }

    public function testImportIntakesRejectsMissingDate(): void
    {
        $csv = "Date,Time,Medication,Dosage,Frequency,UnitPerDose,Notes\n"
             . ",08:00:00,Amoxicillin,500mg,8h,1,\n";

        $service = new CsvImportService($this->getDb());
        $result = $service->importIntakes($csv, $this->patientId);

        $this->assertSame(1, $result['errors']);
        $this->assertStringContainsString('Missing medication or date', $result['rows'][0]['message']);
    }

    public function testImportIntakesRejectsEmptyCsv(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $service = new CsvImportService($this->getDb());
        $service->importIntakes("Date,Time,Medication\n", $this->patientId);
    }

    // -- Schedule import --

    public function testPreviewSchedulesValidatesRows(): void
    {
        // End the existing schedule so the new one doesn't overlap
        $this->getDb()->execute(
            'UPDATE hc_medicine_schedules SET end_date = ? WHERE id = ?',
            ['2026-03-31', $this->scheduleId],
        );

        $csv = "PatientName,Medication,Dosage,Frequency,UnitPerDose,StartDate,EndDate\n"
             . "Daisy,Amoxicillin,500mg,8h,1,2026-05-01,\n";

        $service = new CsvImportService($this->getDb());
        $result = $service->previewSchedules($csv);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['imported']);
    }

    public function testImportSchedulesCreatesRecords(): void
    {
        // End the existing schedule so we don't overlap
        $this->getDb()->execute(
            'UPDATE hc_medicine_schedules SET end_date = ? WHERE id = ?',
            ['2026-03-31', $this->scheduleId],
        );

        $csv = "PatientName,Medication,Dosage,Frequency,UnitPerDose,StartDate,EndDate\n"
             . "Daisy,Amoxicillin,500mg,8h,1,2026-05-01,\n";

        $service = new CsvImportService($this->getDb());
        $result = $service->importSchedules($csv);

        $this->assertSame(1, $result['imported']);

        $rows = $this->getDb()->query(
            'SELECT * FROM hc_medicine_schedules WHERE patient_id = ? AND start_date = ?',
            [$this->patientId, '2026-05-01'],
        );
        $this->assertCount(1, $rows);
    }

    public function testImportSchedulesSkipsExisting(): void
    {
        $csv = "PatientName,Medication,Dosage,Frequency,UnitPerDose,StartDate,EndDate\n"
             . "Daisy,Amoxicillin,500mg,8h,1,2026-03-01,\n";

        $service = new CsvImportService($this->getDb());
        $result = $service->importSchedules($csv);

        // The existing schedule (start 2026-01-01, no end) covers 2026-03-01
        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
    }

    public function testImportSchedulesReportsUnknownPatient(): void
    {
        $csv = "PatientName,Medication,Dosage,Frequency,UnitPerDose,StartDate,EndDate\n"
             . "UnknownPatient,Amoxicillin,500mg,8h,1,2026-05-01,\n";

        $service = new CsvImportService($this->getDb());
        $result = $service->importSchedules($csv);

        $this->assertSame(1, $result['errors']);
        $this->assertStringContainsString('Patient not found', $result['rows'][0]['message']);
    }

    public function testImportSchedulesCreatesPatientWhenFlagged(): void
    {
        $csv = "PatientName,Medication,Dosage,Frequency,UnitPerDose,StartDate,EndDate\n"
             . "NewPatient,Amoxicillin,500mg,8h,1,2026-05-01,\n";

        $service = new CsvImportService($this->getDb(), createMissing: true);
        $result = $service->importSchedules($csv);

        $this->assertSame(1, $result['imported']);

        $rows = $this->getDb()->query("SELECT id FROM hc_patients WHERE name = 'NewPatient'");
        $this->assertCount(1, $rows);
    }

    // -- Round-trip test: export → wipe → import → verify --

    public function testRoundTripExportImportIntakes(): void
    {
        // Seed some intakes
        $this->getDb()->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES (?, ?, ?)',
            [$this->scheduleId, '2026-04-10 08:00:00', 'Morning'],
        );
        $this->getDb()->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES (?, ?, ?)',
            [$this->scheduleId, '2026-04-10 16:00:00', 'Afternoon'],
        );

        // Export
        $exportRows = $this->getDb()->query(
            'SELECT mi.taken_time, m.name AS medication, m.dosage, ms.frequency, ms.unit_per_dose, mi.note
             FROM hc_medicine_intake mi
             JOIN hc_medicine_schedules ms ON mi.schedule_id = ms.id
             JOIN hc_medicines m ON ms.medicine_id = m.id
             WHERE ms.patient_id = ?
             ORDER BY mi.taken_time',
            [$this->patientId],
        );

        $csv = "Date,Time,Medication,Dosage,Frequency,UnitPerDose,Notes\n";
        foreach ($exportRows as $r) {
            $date = substr((string) $r['taken_time'], 0, 10);
            $time = substr((string) $r['taken_time'], 11);
            $csv .= "$date,$time,{$r['medication']},{$r['dosage']},{$r['frequency']},{$r['unit_per_dose']},{$r['note']}\n";
        }

        // Wipe intakes
        $this->getDb()->execute('DELETE FROM hc_medicine_intake WHERE schedule_id = ?', [$this->scheduleId]);
        $remaining = $this->getDb()->query('SELECT COUNT(*) as c FROM hc_medicine_intake WHERE schedule_id = ?', [$this->scheduleId]);
        $this->assertSame(0, (int) $remaining[0]['c']);

        // Import
        $service = new CsvImportService($this->getDb());
        $result = $service->importIntakes($csv, $this->patientId);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['errors']);

        // Verify
        $reimported = $this->getDb()->query(
            'SELECT taken_time, note FROM hc_medicine_intake WHERE schedule_id = ? ORDER BY taken_time',
            [$this->scheduleId],
        );
        $this->assertCount(2, $reimported);
        $this->assertSame('2026-04-10 08:00:00', $reimported[0]['taken_time']);
        $this->assertSame('Morning', $reimported[0]['note']);
        $this->assertSame('2026-04-10 16:00:00', $reimported[1]['taken_time']);
        $this->assertSame('Afternoon', $reimported[1]['note']);
    }

    // -- Helpers --

    private function seedPatient(string $name): int
    {
        $this->getDb()->execute('INSERT INTO hc_patients (name) VALUES (?)', [$name]);

        return $this->getDb()->lastInsertId();
    }

    private function seedMedicine(string $name, string $dosage): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)',
            [$name, $dosage],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedSchedule(int $patientId, int $medicineId, string $startDate, string $frequency): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$patientId, $medicineId, $startDate, $frequency, 1.0],
        );

        return $this->getDb()->lastInsertId();
    }
}
