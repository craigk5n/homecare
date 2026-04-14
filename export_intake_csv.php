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
require_role('caregiver');

use HomeCare\Database\DbiAdapter;
use HomeCare\Export\CsvIntakeExporter;
use HomeCare\Export\IntakeExportQuery;

$patientId = (int) (getIntValue('patient_id') ?? 0);
if ($patientId <= 0) {
    die_miserable_death('Missing or invalid patient_id.');
}

$patient = getPatient($patientId);
$startDate = parse_export_date(getGetValue('start_date'), date('Y-m-d', strtotime('-30 days')));
$endDate = parse_export_date(getGetValue('end_date'), date('Y-m-d'));

$rows = (new IntakeExportQuery(new DbiAdapter()))->fetch($patientId, $startDate, $endDate);
$csv = (new CsvIntakeExporter())->toCsv($rows);

audit_log('export.intake_csv', 'patient', $patientId, [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'row_count' => count($rows),
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
