<?php
/**
 * HC-020: CSV export of intake history.
 *
 * Accepts `patient_id`, `start_date`, `end_date` query parameters.
 * Requires `caregiver` role like every other write-ish operation
 * (CSV export can leak PHI to anyone who can hit the URL).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once 'src/Auth/SignedUrl.php';

use HomeCare\Auth\SignedUrl;
use HomeCare\Database\DbiAdapter;
use HomeCare\Export\CsvIntakeExporter;
use HomeCare\Export\IntakeExportQuery;

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
    if ($params !== null && $params['type'] === 'csv' && (int) $params['patient_id'] === $patientId && $params['start_date'] === $startDate && $params['end_date'] === $endDate) {
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

$rows = (new IntakeExportQuery(new DbiAdapter()))->fetch($patientId, $startDate, $endDate);
$csv = (new CsvIntakeExporter())->toCsv($rows);

audit_log('export.intake_csv', 'patient', $patientId, [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'row_count' => count($rows),
    'via' => $viaSigned ? 'signed_url' : 'session'
]);

$filename = sprintf(
    'intake-history-%s-%s.csv',
    preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($patient['name'])),
    date('Ymd')
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo $csv;
