<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Repository for the hc_webhook_log table.
 *
 * Stores and queries webhook dispatch attempts so admins can see
 * what was sent, whether it succeeded, and how long it took.
 */
final class WebhookLogRepository
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * Record a single dispatch attempt.
     *
     * @param array{
     *     message_id: string,
     *     url: string,
     *     request_body: string,
     *     http_status: int|null,
     *     response_body: string|null,
     *     error_message: string|null,
     *     attempt: int,
     *     max_attempts: int,
     *     elapsed_ms: int|null,
     *     success: bool,
     * } $entry
     */
    public function insert(array $entry): void
    {
        $this->db->execute(
            'INSERT INTO hc_webhook_log
                (message_id, url, request_body, http_status, response_body,
                 error_message, attempt, max_attempts, elapsed_ms, success)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $entry['message_id'],
                $entry['url'],
                $entry['request_body'],
                $entry['http_status'],
                $entry['response_body'],
                $entry['error_message'],
                $entry['attempt'],
                $entry['max_attempts'],
                $entry['elapsed_ms'],
                $entry['success'] ? 1 : 0,
            ],
        );
    }

    /**
     * Paginated, filtered search.
     *
     * @param array<string, string> $filters  Keys: success, date_from, date_to
     *
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, int $page = 1, int $perPage = 50): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT id, message_id, url, request_body, http_status,
                       response_body, error_message, attempt, max_attempts,
                       elapsed_ms, success, created_at
                  FROM hc_webhook_log
                 {$where}
                 ORDER BY created_at DESC, id DESC
                 LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->db->query($sql, $params);

        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'message_id' => (string) $r['message_id'],
            'url' => (string) $r['url'],
            'request_body' => (string) $r['request_body'],
            'http_status' => $r['http_status'] !== null ? (int) $r['http_status'] : null,
            'response_body' => $r['response_body'] !== null ? (string) $r['response_body'] : null,
            'error_message' => $r['error_message'] !== null ? (string) $r['error_message'] : null,
            'attempt' => (int) $r['attempt'],
            'max_attempts' => (int) $r['max_attempts'],
            'elapsed_ms' => $r['elapsed_ms'] !== null ? (int) $r['elapsed_ms'] : null,
            'success' => (bool) $r['success'],
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * Count matching rows (for pagination).
     *
     * @param array<string, string> $filters
     */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $rows = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM hc_webhook_log {$where}",
            $params,
        );

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * Distinct HTTP status codes for filter dropdown.
     *
     * @return list<int>
     */
    public function getDistinctStatuses(): array
    {
        $rows = $this->db->query(
            'SELECT DISTINCT http_status FROM hc_webhook_log
              WHERE http_status IS NOT NULL ORDER BY http_status',
            [],
        );

        return array_map(static fn(array $r): int => (int) $r['http_status'], $rows);
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{0: string, 1: list<string|int>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (isset($filters['success']) && $filters['success'] !== '') {
            $clauses[] = 'success = ?';
            $params[] = (int) $filters['success'];
        }
        if (isset($filters['http_status']) && $filters['http_status'] !== '') {
            $clauses[] = 'http_status = ?';
            $params[] = (int) $filters['http_status'];
        }
        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $clauses[] = 'created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $clauses[] = 'created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }
}
