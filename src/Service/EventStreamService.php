<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Database\DatabaseInterface;

/**
 * Fetch new audit events for SSE streaming (HC-140).
 *
 * Extracted from events.php so the query + filter logic is testable.
 *
 * @phpstan-type SseEvent array{
 *     id:int,
 *     action:string,
 *     entity_type:string,
 *     entity_id:?int,
 *     user_login:string,
 *     created_at:string
 * }
 */
final class EventStreamService
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * Fetch audit events with id > $sinceId, optionally filtered to a patient.
     *
     * @return list<SseEvent>
     */
    public function poll(int $sinceId, ?int $patientId = null, int $limit = 50): array
    {
        $rows = $this->db->query(
            'SELECT id, user_login, action, entity_type, entity_id, created_at
             FROM hc_audit_log
             WHERE id > ?
             ORDER BY id ASC
             LIMIT ?',
            [$sinceId, $limit],
        );

        $scheduleIds = $patientId !== null ? $this->getPatientScheduleIds($patientId) : [];

        $events = [];
        foreach ($rows as $row) {
            $entityType = (string) ($row['entity_type'] ?? '');
            $entityId = $row['entity_id'] !== null ? (int) $row['entity_id'] : null;

            if ($patientId !== null) {
                if (!self::isRelevant($entityType, $entityId, $scheduleIds, $patientId)) {
                    continue;
                }
            }

            $events[] = [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'user_login' => (string) ($row['user_login'] ?? ''),
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $events;
    }

    /**
     * @return list<int>
     */
    private function getPatientScheduleIds(int $patientId): array
    {
        $rows = $this->db->query(
            'SELECT id FROM hc_medicine_schedules WHERE patient_id = ?',
            [$patientId],
        );

        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * @param list<int> $scheduleIds
     */
    private static function isRelevant(string $entityType, ?int $entityId, array $scheduleIds, int $patientId): bool
    {
        if ($entityType === 'patient' && $entityId === $patientId) {
            return true;
        }

        if ($entityType === 'schedule' && $entityId !== null && in_array($entityId, $scheduleIds, true)) {
            return true;
        }

        if (in_array($entityType, ['intake', 'note', 'inventory'], true)) {
            return true;
        }

        return false;
    }
}
