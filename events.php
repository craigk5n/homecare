<?php
/**
 * HC-140: Server-Sent Events endpoint tailing hc_audit_log.
 *
 * Streams new audit events as SSE messages. Client connects with:
 *   new EventSource('events.php?patient_id=N')
 *
 * Uses Last-Event-ID header or ?since_id= query parameter to resume.
 * Polls every 2 seconds, closes on client disconnect. No forever-open
 * DB connections — each poll cycle opens, queries, and releases.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$patientId = (int) (getIntValue('patient_id') ?? 0);
$sinceId = (int) (getIntValue('since_id') ?? 0);

if (isset($_SERVER['HTTP_LAST_EVENT_ID']) && $_SERVER['HTTP_LAST_EVENT_ID'] !== '') {
    $sinceId = max($sinceId, (int) $_SERVER['HTTP_LAST_EVENT_ID']);
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}

$maxRuntime = 60;
$pollInterval = 2;
$startTime = time();

$patientScheduleIds = [];
if ($patientId > 0) {
    $sql = 'SELECT id FROM hc_medicine_schedules WHERE patient_id = ?';
    $rows = dbi_get_cached_rows($sql, [$patientId]);
    foreach ($rows as $row) {
        $patientScheduleIds[] = (int) $row[0];
    }
}

echo ": connected\n\n";
flush();

while (true) {
    if (connection_aborted()) {
        break;
    }

    if ((time() - $startTime) >= $maxRuntime) {
        echo "event: timeout\ndata: reconnect\n\n";
        flush();
        break;
    }

    $sql = 'SELECT id, user_login, action, entity_type, entity_id, created_at
            FROM hc_audit_log
            WHERE id > ?
            ORDER BY id ASC
            LIMIT 50';
    $rows = dbi_get_cached_rows($sql, [$sinceId]);

    foreach ($rows as $row) {
        $eventId = (int) $row[0];
        $entityType = (string) ($row[3] ?? '');
        $entityId = $row[4] !== null ? (int) $row[4] : null;

        if ($patientId > 0 && !isRelevantToPatient($entityType, $entityId, $patientScheduleIds, $patientId)) {
            $sinceId = $eventId;
            continue;
        }

        $data = json_encode([
            'id' => $eventId,
            'action' => (string) $row[2],
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_login' => (string) ($row[1] ?? ''),
            'created_at' => (string) $row[5],
        ], JSON_UNESCAPED_SLASHES);

        echo "id: $eventId\n";
        echo "data: $data\n\n";
        $sinceId = $eventId;
    }

    flush();
    sleep($pollInterval);
}

/**
 * @param list<int> $scheduleIds
 */
function isRelevantToPatient(string $entityType, ?int $entityId, array $scheduleIds, int $patientId): bool
{
    if ($entityType === 'patient' && $entityId === $patientId) {
        return true;
    }

    if ($entityType === 'schedule' && $entityId !== null && in_array($entityId, $scheduleIds, true)) {
        return true;
    }

    if ($entityType === 'intake' || $entityType === 'note' || $entityType === 'inventory') {
        return true;
    }

    return false;
}
