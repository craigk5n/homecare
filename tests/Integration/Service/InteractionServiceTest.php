<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\InteractionRepository;
use HomeCare\Service\InteractionService;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class InteractionServiceTest extends DatabaseTestCase
{
    private InteractionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->service = new InteractionService(new InteractionRepository($db), $db);
    }

    public function testCheckForPatientReturnsInteractions(): void
    {
        $patientId = $this->seedPatient();

        $catIdA = $this->seedCatalogEntry('Warfarin 5 MG Oral Tablet', 'warfarin');
        $medIdA = $this->seedMedicine('Warfarin 5mg', $catIdA);
        $this->seedActiveSchedule($patientId, $medIdA);

        $catIdB = $this->seedCatalogEntry('Aspirin 325 MG Oral Tablet', 'aspirin');
        $medIdB = $this->seedMedicine('Aspirin 325mg', $catIdB);

        $this->seedInteraction('aspirin', 'warfarin', 'major', 'Increased bleeding risk');

        $results = $this->service->checkForPatient($patientId, $medIdB);

        $this->assertCount(1, $results);
        $this->assertSame('major', $results[0]['severity']);
        $this->assertSame('Increased bleeding risk', $results[0]['description']);
        $this->assertSame('Warfarin 5mg', $results[0]['existing_medicine']);
    }

    public function testCheckForPatientReturnsEmptyWhenNoInteraction(): void
    {
        $patientId = $this->seedPatient();

        $catIdA = $this->seedCatalogEntry('Lisinopril 10 MG Oral Tablet', 'lisinopril');
        $medIdA = $this->seedMedicine('Lisinopril 10mg', $catIdA);
        $this->seedActiveSchedule($patientId, $medIdA);

        $catIdB = $this->seedCatalogEntry('Metformin 500 MG Oral Tablet', 'metformin');
        $medIdB = $this->seedMedicine('Metformin 500mg', $catIdB);

        $results = $this->service->checkForPatient($patientId, $medIdB);

        $this->assertSame([], $results);
    }

    public function testCheckForPatientIgnoresEndedSchedules(): void
    {
        $patientId = $this->seedPatient();

        $catIdA = $this->seedCatalogEntry('Warfarin 5 MG Oral Tablet', 'warfarin');
        $medIdA = $this->seedMedicine('Warfarin 5mg', $catIdA);
        $this->seedEndedSchedule($patientId, $medIdA);

        $catIdB = $this->seedCatalogEntry('Aspirin 325 MG Oral Tablet', 'aspirin');
        $medIdB = $this->seedMedicine('Aspirin 325mg', $catIdB);

        $this->seedInteraction('aspirin', 'warfarin', 'major', 'Increased bleeding risk');

        $results = $this->service->checkForPatient($patientId, $medIdB);

        $this->assertSame([], $results);
    }

    public function testCheckForPatientReturnsEmptyWhenNoCatalogLink(): void
    {
        $patientId = $this->seedPatient();

        $medIdA = $this->seedMedicineWithoutCatalog('Free-text drug');
        $this->seedActiveSchedule($patientId, $medIdA);

        $catIdB = $this->seedCatalogEntry('Aspirin 325 MG Oral Tablet', 'aspirin');
        $medIdB = $this->seedMedicine('Aspirin 325mg', $catIdB);

        $this->seedInteraction('aspirin', 'warfarin', 'major', 'test');

        $results = $this->service->checkForPatient($patientId, $medIdB);

        $this->assertSame([], $results);
    }

    public function testCheckForPatientHandlesMultiIngredientDrugs(): void
    {
        $patientId = $this->seedPatient();

        $catIdA = $this->seedCatalogEntry('Warfarin 5 MG Oral Tablet', 'warfarin');
        $medIdA = $this->seedMedicine('Warfarin 5mg', $catIdA);
        $this->seedActiveSchedule($patientId, $medIdA);

        // Multi-ingredient drug: aspirin/caffeine
        $catIdB = $this->seedCatalogEntry('Aspirin/Caffeine Oral Tablet', 'aspirin / caffeine');
        $medIdB = $this->seedMedicine('Aspirin/Caffeine', $catIdB);

        $this->seedInteraction('aspirin', 'warfarin', 'major', 'Increased bleeding risk');

        $results = $this->service->checkForPatient($patientId, $medIdB);

        $this->assertCount(1, $results);
        $this->assertSame('major', $results[0]['severity']);
    }

    public function testCheckForPatientSortsBySeverity(): void
    {
        $patientId = $this->seedPatient();

        $catIdA = $this->seedCatalogEntry('Drug A', 'ingredienta');
        $medIdA = $this->seedMedicine('Drug A', $catIdA);
        $this->seedActiveSchedule($patientId, $medIdA);

        $catIdB = $this->seedCatalogEntry('Drug B', 'ingredientb');
        $medIdB = $this->seedMedicine('Drug B', $catIdB);
        $this->seedActiveSchedule($patientId, $medIdB);

        $catIdC = $this->seedCatalogEntry('Drug C', 'ingredientc');
        $medIdC = $this->seedMedicine('Drug C', $catIdC);

        $this->seedInteraction('ingredienta', 'ingredientc', 'minor', 'Minor issue');
        $this->seedInteraction('ingredientb', 'ingredientc', 'major', 'Major issue');

        $results = $this->service->checkForPatient($patientId, $medIdC);

        $this->assertCount(2, $results);
        $this->assertSame('major', $results[0]['severity']);
        $this->assertSame('minor', $results[1]['severity']);
    }

    public function testCheckAllForPatientFindsInteractionsAmongActive(): void
    {
        $patientId = $this->seedPatient();

        $catIdA = $this->seedCatalogEntry('Warfarin 5 MG Oral Tablet', 'warfarin');
        $medIdA = $this->seedMedicine('Warfarin 5mg', $catIdA);
        $this->seedActiveSchedule($patientId, $medIdA);

        $catIdB = $this->seedCatalogEntry('Aspirin 325 MG Oral Tablet', 'aspirin');
        $medIdB = $this->seedMedicine('Aspirin 325mg', $catIdB);
        $this->seedActiveSchedule($patientId, $medIdB);

        $this->seedInteraction('aspirin', 'warfarin', 'major', 'Increased bleeding risk');

        $results = $this->service->checkAllForPatient($patientId);

        $this->assertCount(1, $results);
        $this->assertSame('major', $results[0]['severity']);
    }

    public function testCheckAllForPatientReturnsEmptyWhenNoActiveInteractions(): void
    {
        $patientId = $this->seedPatient();

        $catIdA = $this->seedCatalogEntry('Lisinopril Tablet', 'lisinopril');
        $medIdA = $this->seedMedicine('Lisinopril', $catIdA);
        $this->seedActiveSchedule($patientId, $medIdA);

        $catIdB = $this->seedCatalogEntry('Metformin Tablet', 'metformin');
        $medIdB = $this->seedMedicine('Metformin', $catIdB);
        $this->seedActiveSchedule($patientId, $medIdB);

        $results = $this->service->checkAllForPatient($patientId);

        $this->assertSame([], $results);
    }

    // -- Seed helpers --

    private function seedPatient(string $name = 'Daisy'): int
    {
        $this->getDb()->execute('INSERT INTO hc_patients (name) VALUES (?)', [$name]);

        return $this->getDb()->lastInsertId();
    }

    private function seedCatalogEntry(string $name, string $ingredients): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_drug_catalog (name, ingredient_names) VALUES (?, ?)',
            [$name, $ingredients],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedMedicine(string $name, int $catalogId): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_medicines (name, dosage, drug_catalog_id) VALUES (?, ?, ?)',
            [$name, 'test', $catalogId],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedMedicineWithoutCatalog(string $name): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)',
            [$name, 'test'],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedActiveSchedule(int $patientId, int $medicineId): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$patientId, $medicineId, '2026-01-01', '1d', 1.0],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedEndedSchedule(int $patientId, int $medicineId): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$patientId, $medicineId, '2025-01-01', '2025-06-01', '1d', 1.0],
        );

        return $this->getDb()->lastInsertId();
    }

    private function seedInteraction(string $a, string $b, string $severity, string $description): void
    {
        $ordered = [$a, $b];
        sort($ordered);
        $this->getDb()->execute(
            'INSERT INTO hc_drug_interactions (ingredient_a, ingredient_b, severity, description)
             VALUES (?, ?, ?, ?)',
            [$ordered[0], $ordered[1], $severity, $description],
        );
    }
}
