<?php

declare(strict_types=1);

namespace HomeCare\Export;

/**
 * Render intake rows as an HL7 FHIR R4 `Bundle` of type `collection`
 * containing one `Patient` resource, one `Medication` resource per
 * distinct product, and one `MedicationAdministration` resource per
 * recorded dose.
 *
 * FHIR R4 is the de-facto interchange standard for medication data --
 * Apple Health, most modern EHRs (Epic, Cerner, athenahealth),
 * ONC-certified clinical systems, and consumer health platforms all
 * speak it. Using FHIR here means a caregiver can hand an exported
 * bundle to a vet/doctor's system and expect it to land cleanly.
 *
 * We only emit the subset required to represent "patient X took
 * medication Y at time Z with dose D". Extensions (route, performer,
 * reasonCode, etc.) are intentionally omitted until we have data to
 * back them -- better no field than a guessed field in clinical
 * context.
 *
 * Reference: http://hl7.org/fhir/R4/medicationadministration.html
 *
 * @phpstan-import-type IntakeExportRow from IntakeExportQuery
 */
final class FhirIntakeExporter
{
    public const FHIR_VERSION = '4.0.1';

    /** @var callable():string */
    private readonly mixed $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param list<IntakeExportRow> $rows
     */
    public function toJson(array $rows, int $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        $bundle = $this->toBundle($rows);

        $encoded = json_encode($bundle, $jsonFlags);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @param list<IntakeExportRow> $rows
     *
     * @return array<string,mixed>
     */
    public function toBundle(array $rows): array
    {
        $timestamp = $this->currentTimestamp();
        $patients = [];
        $medicines = [];
        $entries = [];

        foreach ($rows as $row) {
            $patientId = $row['patient_id'];
            if (!isset($patients[$patientId])) {
                $patients[$patientId] = self::patientResource($patientId, $row['patient_name']);
                $entries[] = self::entry($patients[$patientId]);
            }

            $medicineId = $row['medicine_id'];
            if (!isset($medicines[$medicineId])) {
                $medicines[$medicineId] = self::medicationResource(
                    $medicineId,
                    $row['medicine_name'],
                    $row['medicine_dosage']
                );
                $entries[] = self::entry($medicines[$medicineId]);
            }

            $entries[] = self::entry(self::medicationAdministrationResource($row));
        }

        return [
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'timestamp' => $timestamp,
            'meta' => [
                'lastUpdated' => $timestamp,
                'source' => 'HomeCare',
            ],
            'entry' => $entries,
        ];
    }

    /**
     * @param array<string,mixed> $resource
     *
     * @return array<string,mixed>
     */
    private static function entry(array $resource): array
    {
        $rt = isset($resource['resourceType']) && is_string($resource['resourceType'])
            ? $resource['resourceType'] : 'Unknown';
        $id = isset($resource['id']) && is_string($resource['id']) ? $resource['id'] : '';

        return [
            'fullUrl' => 'urn:homecare:' . strtolower($rt) . ':' . $id,
            'resource' => $resource,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function patientResource(int $id, string $name): array
    {
        return [
            'resourceType' => 'Patient',
            'id' => (string) $id,
            'name' => [
                ['text' => $name, 'use' => 'usual'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function medicationResource(int $id, string $name, string $dosage): array
    {
        return [
            'resourceType' => 'Medication',
            'id' => (string) $id,
            'code' => [
                'text' => trim($name . ' ' . $dosage),
            ],
        ];
    }

    /**
     * @param IntakeExportRow $row
     *
     * @return array<string,mixed>
     */
    private static function medicationAdministrationResource(array $row): array
    {
        $resource = [
            'resourceType' => 'MedicationAdministration',
            'id' => (string) $row['intake_id'],
            'status' => 'completed',
            'subject' => ['reference' => 'Patient/' . $row['patient_id']],
            'medicationReference' => ['reference' => 'Medication/' . $row['medicine_id']],
            'effectiveDateTime' => self::toFhirDateTime($row['taken_time']),
            'dosage' => [
                'dose' => [
                    'value' => $row['unit_per_dose'],
                    'unit' => 'dose',
                ],
                'text' => $row['medicine_dosage'] !== ''
                    ? $row['medicine_dosage'] . ' every ' . $row['frequency']
                    : 'every ' . $row['frequency'],
            ],
        ];

        if ($row['note'] !== null && $row['note'] !== '') {
            $resource['note'] = [['text' => $row['note']]];
        }

        return $resource;
    }

    /**
     * Convert "YYYY-MM-DD HH:MM:SS" (MySQL DATETIME) to FHIR's ISO-8601.
     * Treats the stored time as local; callers that store UTC should
     * re-stamp beforehand.
     */
    private static function toFhirDateTime(string $mysqlDateTime): string
    {
        if (strlen($mysqlDateTime) < 19) {
            return $mysqlDateTime;
        }

        return substr($mysqlDateTime, 0, 10) . 'T' . substr($mysqlDateTime, 11, 8);
    }

    private function currentTimestamp(): string
    {
        /** @var callable():string $fn */
        $fn = $this->clock;

        return ($fn)();
    }
}
