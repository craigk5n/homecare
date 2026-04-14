<?php
/**
 * HC-020 follow-up: PDF export of intake history.
 *
 * Accepts `patient_id`, `start_date`, `end_date` query parameters —
 * identical surface to export_intake_csv.php / export_intake_fhir.php so
 * the three exports are interchangeable from the caller's perspective.
 * Role gate + audit logging mirror the other exports for the same PHI
 * reasons (anyone who can hit the URL can download the report).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_role('caregiver');

use HomeCare\Database\DbiAdapter;
use HomeCare\Export\IntakeExportQuery;
use HomeCare\Export\PdfIntakeExporter;

$patientId = (int) (getIntValue('patient_id') ?? 0);
if ($patientId <= 0) {
    die_miserable_death('Missing or invalid patient_id.');
}

$patient = getPatient($patientId);
$startDate = parse_export_date(getGetValue('start_date'), date('Y-m-d', strtotime('-30 days')));
$endDate = parse_export_date(getGetValue('end_date'), date('Y-m-d'));

$rows = (new IntakeExportQuery(new DbiAdapter()))->fetch($patientId, $startDate, $endDate);

$periodLabel = $startDate === $endDate
    ? $startDate
    : $startDate . ' – ' . $endDate;

$pdf = (new PdfIntakeExporter())->toPdf($rows, [
    'patient_name' => (string) $patient['name'],
    'period_label' => $periodLabel,
    'generated_at' => date('Y-m-d H:i'),
]);

audit_log('export.intake_pdf', 'patient', $patientId, [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'row_count' => count($rows),
]);

$filename = sprintf(
    'intake-history-%s-%s.pdf',
    preg_replace('/[^a-z0-9_-]+/i', '_', strtolower((string) $patient['name'])),
    date('Ymd')
);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: no-store');
echo $pdf;
