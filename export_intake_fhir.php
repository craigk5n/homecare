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
require_once 'src/Auth/SignedUrl.php';

use HomeCare\Auth\SignedUrl;
use HomeCare\Database\DbiAdapter;
use HomeCare\Export\FhirIntakeExporter;
use HomeCare\Export\IntakeExportQuery;

require_once __DIR__ . '/includes/email_export_dispatch.php';

// Check auth: session or token
$token = getGetValue('token', '');
$patientId = (int) (getIntValue('patient_id') ?? 0);
$startDate = parse_export_date(getGetValue('start_date'), date('Y-m-d', strtotime('-30 days')));
$endDate = parse_export_date(getGetValue('end_date'), date('Y-m-d'));

if ($patientId <= 0) {
    http_response_code(400);
    die('Missing or invalid patient_id.');
}

$authorized = false;
$viaSigned = false;
if ($token) {
    $signed = SignedUrl::instance();
    $params = $signed->getParams($token);
    if ($params !== null && $params['type'] === 'fhir' && (int) $params['patient_id'] === $patientId && $params['start_date'] === $startDate && $params['end_date'] === $endDate) {
        $authorized = true;
        $viaSigned = true;
    }
}

if (!$authorized) {
    require_role('caregiver');
}

$patient = getPatient($patientId);
if (!$patient) {
    http_response_code(404);
    die('Patient not found.');
}

// HC-108: delivery=email branch — attach FHIR bundle to an email
// instead of streaming it to the browser.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && getPostValue('delivery') === 'email') {
    hc108_email_and_exit(
        type: 'fhir',
        patientId: $patientId,
        startDate: $startDate,
        endDate: $endDate,
        patient: $patient,
    );
}

$rows = (new IntakeExportQuery(new DbiAdapter()))->fetch($patientId, $startDate, $endDate);
$json = (new FhirIntakeExporter())->toJson($rows);

audit_log('export.intake_fhir', 'patient', $patientId, [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'row_count' => count($rows),
    'via' => $viaSigned ? 'signed_url' : 'session'
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
