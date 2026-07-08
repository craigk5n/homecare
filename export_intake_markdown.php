<?php
/**
 * Markdown export of intake history.
 *
 * Accepts `patient_id`, `start_date`, `end_date` query parameters —
 * identical surface to the CSV/FHIR/PDF/text exports (see export.php).
 * Role gate + audit logging mirror the other exports for the same PHI
 * reasons.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once 'src/Auth/SignedUrl.php';

use HomeCare\Auth\SignedUrl;
use HomeCare\Database\DbiAdapter;
use HomeCare\Export\IntakeExportQuery;
use HomeCare\Export\MarkdownIntakeExporter;

require_once __DIR__ . '/includes/email_export_dispatch.php';

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
    if ($params !== null && $params['type'] === 'markdown' && (int) $params['patient_id'] === $patientId && $params['start_date'] === $startDate && $params['end_date'] === $endDate) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && getPostValue('delivery') === 'email') {
    hc108_email_and_exit(
        type: 'markdown',
        patientId: $patientId,
        startDate: $startDate,
        endDate: $endDate,
        patient: $patient,
    );
}

$rows = (new IntakeExportQuery(new DbiAdapter()))->fetch($patientId, $startDate, $endDate);
$periodLabel = $startDate === $endDate ? $startDate : $startDate . ' – ' . $endDate;
$markdown = (new MarkdownIntakeExporter())->toMarkdown($rows, [
    'patient_name' => (string) $patient['name'],
    'period_label' => $periodLabel,
    'generated_at' => date('Y-m-d H:i'),
]);

audit_log('export.intake_markdown', 'patient', $patientId, [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'row_count' => count($rows),
    'via' => $viaSigned ? 'signed_url' : 'session',
]);

$filename = sprintf(
    'intake-history-%s-%s.md',
    preg_replace('/[^a-z0-9_-]+/i', '_', strtolower((string) $patient['name'])),
    date('Ymd')
);

header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo $markdown;
