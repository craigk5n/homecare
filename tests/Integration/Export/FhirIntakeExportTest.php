<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Export;

use HomeCare\Export\FhirIntakeExporter;
use HomeCare\Export\IntakeExportQuery;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Structural checks that the emitted FHIR bundle honours R4 shape.
 *
 * We compare the JSON-serialised form against expected substrings /
 * decoded arrays rather than chasing nested offsets -- that side-steps
 * PHPStan's mixed-offset warnings without weakening the guarantees.
 */
final class FhirIntakeExportTest extends DatabaseTestCase
{
    private int $patientId;
    private int $medicineId;
    private int $scheduleId;
    private IntakeExportQuery $query;
    private FhirIntakeExporter $fhir;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->query = new IntakeExportQuery($db);
        $this->fhir = new FhirIntakeExporter(
            static fn (): string => '2026-04-13T12:00:00Z',
        );

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

    public function testEmptyBundleStillValidatesShape(): void
    {
        $json = $this->fhir->toJson([]);
        $this->assertStringContainsString('"resourceType": "Bundle"', $json);
        $this->assertStringContainsString('"type": "collection"', $json);
        $this->assertStringContainsString('"timestamp": "2026-04-13T12:00:00Z"', $json);
        $this->assertStringContainsString('"entry": []', $json);
    }

    public function testSingleIntakeProducesCompleteResources(): void
    {
        (new IntakeFactory($this->getDb()))->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-04-05 08:00:00',
            'note' => 'with food',
        ]);

        $json = $this->fhir->toJson($this->query->fetch($this->patientId, '2026-04-01', '2026-04-30'));

        // Patient resource
        $this->assertStringContainsString('"resourceType": "Patient"', $json);
        $this->assertStringContainsString('"text": "Daisy"', $json);

        // Medication resource
        $this->assertStringContainsString('"resourceType": "Medication"', $json);
        $this->assertStringContainsString('"text": "Sildenafil 20mg"', $json);

        // MedicationAdministration resource
        $this->assertStringContainsString('"resourceType": "MedicationAdministration"', $json);
        $this->assertStringContainsString('"status": "completed"', $json);
        $this->assertStringContainsString('"reference": "Patient/' . $this->patientId . '"', $json);
        $this->assertStringContainsString('"reference": "Medication/' . $this->medicineId . '"', $json);
        $this->assertStringContainsString('"effectiveDateTime": "2026-04-05T08:00:00"', $json);
        $this->assertStringContainsString('"value": 1.5', $json);
        $this->assertStringContainsString('"unit": "dose"', $json);
        $this->assertStringContainsString('"text": "with food"', $json);
    }

    public function testDistinctResourcesAreDeduplicated(): void
    {
        $f = new IntakeFactory($this->getDb());
        $f->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-04-05 08:00:00']);
        $f->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-04-05 16:00:00']);
        $f->create(['schedule_id' => $this->scheduleId, 'taken_time' => '2026-04-06 08:00:00']);

        $json = $this->fhir->toJson($this->query->fetch($this->patientId, '2026-04-01', '2026-04-30'));

        $this->assertSame(
            1,
            substr_count($json, '"resourceType": "Patient"'),
            'one Patient resource for a single patient across three intakes'
        );
        $this->assertSame(
            1,
            substr_count($json, '"resourceType": "Medication"'),
            'one Medication resource for a single medicine across three intakes'
        );
        $this->assertSame(
            3,
            substr_count($json, '"resourceType": "MedicationAdministration"'),
            'one MedicationAdministration per intake'
        );
    }

    public function testJsonDecodesAsValidJson(): void
    {
        (new IntakeFactory($this->getDb()))->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-04-05 08:00:00',
        ]);

        $json = $this->fhir->toJson(
            $this->query->fetch($this->patientId, '2026-04-01', '2026-04-30')
        );

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertStringContainsString("\n", $json, 'JSON_PRETTY_PRINT newlines present');
    }

    public function testOmittedNoteProducesNoNoteField(): void
    {
        (new IntakeFactory($this->getDb()))->create([
            'schedule_id' => $this->scheduleId,
            'taken_time' => '2026-04-05 08:00:00',
            'note' => null,
        ]);

        $json = $this->fhir->toJson(
            $this->query->fetch($this->patientId, '2026-04-01', '2026-04-30')
        );

        // A `note` field would serialise as `"note": [...]`. Its absence
        // from the MedicationAdministration resource is what we're
        // guarding -- the Medication resource has no note field in our
        // shape, so the raw string won't appear at all.
        $this->assertStringNotContainsString('"note"', $json);
    }
}
