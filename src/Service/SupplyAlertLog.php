<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Database\DatabaseInterface;

/**
 * hc_supply_alert_log-backed implementation of {@see SupplyAlertLogInterface}.
 *
 * The table has one row per medicine. To stay portable across MySQL
 * and SQLite we avoid dialect-specific UPSERT syntax -- a SELECT +
 * INSERT/UPDATE is two round-trips but works everywhere.
 */
final class SupplyAlertLog implements SupplyAlertLogInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function lastSentAt(int $medicineId): ?string
    {
        $rows = $this->db->query(
            'SELECT last_sent_at FROM hc_supply_alert_log WHERE medicine_id = ?',
            [$medicineId],
        );
        if ($rows === []) {
            return null;
        }

        return (string) $rows[0]['last_sent_at'];
    }

    public function markSent(int $medicineId, string $whenDateTime): void
    {
        $existing = $this->db->query(
            'SELECT medicine_id FROM hc_supply_alert_log WHERE medicine_id = ?',
            [$medicineId],
        );
        if ($existing === []) {
            $this->db->execute(
                'INSERT INTO hc_supply_alert_log (medicine_id, last_sent_at) VALUES (?, ?)',
                [$medicineId, $whenDateTime],
            );
        } else {
            $this->db->execute(
                'UPDATE hc_supply_alert_log SET last_sent_at = ? WHERE medicine_id = ?',
                [$whenDateTime, $medicineId],
            );
        }
    }
}
