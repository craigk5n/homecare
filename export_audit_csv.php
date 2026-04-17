<?php
/**
 * Export the audit log as CSV (admin only).
 *
 * Reuses the same filters as audit_log.php but exports all matching
 * rows (no pagination) as a CSV download.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_role('admin');

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\AuditRepository;

$db = new DbiAdapter();
$audit = new AuditRepository($db);

$filters = [
    'user_login' => trim((string) (getGetValue('user_login') ?? '')),
    'action' => trim((string) (getGetValue('action') ?? '')),
    'entity_type' => trim((string) (getGetValue('entity_type') ?? '')),
    'date_from' => trim((string) (getGetValue('date_from') ?? '')),
    'date_to' => trim((string) (getGetValue('date_to') ?? '')),
];
$filters = array_filter($filters, static fn(string $v): bool => $v !== '');

// Fetch all matching rows (no page limit).
$total = $audit->count($filters);
$rows = $audit->search($filters, 1, max($total, 1));

audit_log('export.audit_log', 'config', null, [
    'filters' => $filters,
    'row_count' => count($rows),
]);

// Stream CSV.
$filename = 'homecare-audit-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$out = fopen('php://output', 'wb');
if ($out === false) {
    http_response_code(500);
    exit;
}

fputcsv($out, ['Time', 'User', 'Action', 'Entity Type', 'Entity ID', 'Patient', 'Medicine', 'IP', 'Details']);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['created_at'] ?? '',
        $row['user_login'] ?? '',
        $row['action'] ?? '',
        $row['entity_type'] ?? '',
        $row['entity_id'] !== null ? (string) $row['entity_id'] : '',
        $row['patient_name'] ?? '',
        $row['medicine_name'] ?? '',
        $row['ip_address'] ?? '',
        $row['details'] ?? '',
    ]);
}

fclose($out);
exit;
