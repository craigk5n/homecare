<?php
/**
 * HC-020: HL7 FHIR R4 export of intake history as a MedicationAdministration bundle.
 *
 * Emits a `Bundle` (type=collection) with one `Patient`, one
 * `Medication` per distinct product, and one
 * `MedicationAdministration` per intake. The output can be imported
 * into any FHIR-capable system (Apple Health, Epic, Cerner,
 * athenahealth, most PHRs).
 *
 * Reference: http://hl7.org/fhir/R4/medicationadministration.html
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_role('caregiver');

use HomeCare\Database\DbiAdapter;
use HomeCare\Export\FhirIntakeExporter;
use HomeCare\Export\IntakeExportQuery;

$patientId = (int) (getIntValue('patient_id') ?? 0);
if ($patientId <= 0) {
    die_miserable_death('Missing or invalid patient_id.');
}

$patient = getPatient($patientId);
$startDate = parse_export_date(getGetValue('start_date'), date('Y-m-d', strtotime('-30 days')));
$endDate = parse_export_date(getGetValue('end_date'), date('Y-m-d'));

$rows = (new IntakeExportQuery(new DbiAdapter()))->fetch($patientId, $startDate, $endDate);
$json = (new FhirIntakeExporter())->toJson($rows);

audit_log('export.intake_fhir', 'patient', $patientId, [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'row_count' => count($rows),
]);

$filename = sprintf(
    'intake-history-%s-%s.fhir.json',
    preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($patient['name'])),
    date('Ymd')
);

// application/fhir+json is the registered FHIR media type; most clients
// also accept plain application/json. We set the FHIR type so
// interoperable tools detect the payload correctly.
header('Content-Type: application/fhir+json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo $json;
