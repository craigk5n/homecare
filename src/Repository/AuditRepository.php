<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Read-side access to hc_audit_log with filtering and pagination.
 *
 * The search query LEFT JOINs through the main entity types (schedule,
 * intake, medicine, patient, inventory) to resolve patient and medicine
 * names so the admin viewer shows human-readable context instead of bare
 * entity IDs. Each JOIN path is gated on entity_type so at most one
 * path produces non-NULL values per row.
 *
 * @phpstan-type AuditFilters array{
 *     user_login?:string,
 *     action?:string,
 *     entity_type?:string,
 *     date_from?:string,
 *     date_to?:string
 * }
 * @phpstan-type AuditRow array{
 *     id:int,
 *     user_login:string|null,
 *     action:string,
 *     entity_type:string|null,
 *     entity_id:int|null,
 *     details:string|null,
 *     ip_address:string|null,
 *     created_at:string,
 *     patient_name:string|null,
 *     medicine_name:string|null
 * }
 */
final class AuditRepository
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @param AuditFilters $filters
     * @return list<AuditRow>
     */
    public function search(array $filters, int $page = 1, int $perPage = 50): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT al.id, al.user_login, al.action, al.entity_type, al.entity_id,
                       al.details, al.ip_address, al.created_at,
                       COALESCE(p1.name, p2.name, p3.name) AS patient_name,
                       COALESCE(m1.name, m2.name, m3.name, m4.name) AS medicine_name
                FROM hc_audit_log al
                LEFT JOIN hc_medicine_schedules ms1
                  ON al.entity_type = \'schedule\' AND al.entity_id = ms1.id
                LEFT JOIN hc_patients p1 ON ms1.patient_id = p1.id
                LEFT JOIN hc_medicines m1 ON ms1.medicine_id = m1.id
                LEFT JOIN hc_medicine_intake mi
                  ON al.entity_type = \'intake\' AND al.entity_id = mi.id
                LEFT JOIN hc_medicine_schedules ms2 ON mi.schedule_id = ms2.id
                LEFT JOIN hc_patients p2 ON ms2.patient_id = p2.id
                LEFT JOIN hc_medicines m2 ON ms2.medicine_id = m2.id
                LEFT JOIN hc_medicines m3
                  ON al.entity_type = \'medicine\' AND al.entity_id = m3.id
                LEFT JOIN hc_patients p3
                  ON al.entity_type = \'patient\' AND al.entity_id = p3.id
                LEFT JOIN hc_medicine_inventory inv
                  ON al.entity_type = \'inventory\' AND al.entity_id = inv.id
                LEFT JOIN hc_medicines m4 ON inv.medicine_id = m4.id';

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $sql .= ' ORDER BY al.created_at DESC, al.id DESC LIMIT ? OFFSET ?';
        $params[] = $perPage;
        $params[] = $offset;

        return array_map(self::hydrate(...), $this->db->query($sql, $params));
    }

    /**
     * @param AuditFilters $filters
     */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = 'SELECT COUNT(*) AS n FROM hc_audit_log al';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $rows = $this->db->query($sql, $params);

        return $rows === [] ? 0 : (int) $rows[0]['n'];
    }

    /**
     * @return list<string>
     */
    public function getDistinctValues(string $column): array
    {
        if (!in_array($column, ['user_login', 'action', 'entity_type'], true)) {
            return [];
        }

        $sql = "SELECT DISTINCT {$column} FROM hc_audit_log WHERE {$column} IS NOT NULL ORDER BY {$column}";
        $rows = $this->db->query($sql);

        return array_map(static fn(array $r): string => (string) $r[$column], $rows);
    }

    /**
     * @param AuditFilters $filters
     * @return array{string, list<scalar|null>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (($filters['user_login'] ?? '') !== '') {
            $clauses[] = 'al.user_login = ?';
            $params[] = $filters['user_login'];
        }

        if (($filters['action'] ?? '') !== '') {
            $clauses[] = 'al.action = ?';
            $params[] = $filters['action'];
        }

        if (($filters['entity_type'] ?? '') !== '') {
            $clauses[] = 'al.entity_type = ?';
            $params[] = $filters['entity_type'];
        }

        if (($filters['date_from'] ?? '') !== '') {
            $clauses[] = 'al.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (($filters['date_to'] ?? '') !== '') {
            $clauses[] = 'al.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string,scalar|null> $row
     * @return AuditRow
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_login' => $row['user_login'] === null ? null : (string) $row['user_login'],
            'action' => (string) $row['action'],
            'entity_type' => $row['entity_type'] === null ? null : (string) $row['entity_type'],
            'entity_id' => $row['entity_id'] === null ? null : (int) $row['entity_id'],
            'details' => $row['details'] === null ? null : (string) $row['details'],
            'ip_address' => $row['ip_address'] === null ? null : (string) $row['ip_address'],
            'created_at' => (string) $row['created_at'],
            'patient_name' => $row['patient_name'] === null ? null : (string) $row['patient_name'],
            'medicine_name' => $row['medicine_name'] === null ? null : (string) $row['medicine_name'],
        ];
    }
}
