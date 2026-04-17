<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Database\DatabaseInterface;

/**
 * hc_late_dose_alert_log-backed implementation of
 * {@see LateDoseAlertLogInterface}.
 *
 * SELECT + INSERT/UPDATE (not UPSERT) for MySQL/SQLite portability,
 * same pattern as `SupplyAlertLog`.
 */
final class LateDoseAlertLog implements LateDoseAlertLogInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function lastDueAt(int $scheduleId): ?string
    {
        $rows = $this->db->query(
            'SELECT last_due_at FROM hc_late_dose_alert_log WHERE schedule_id = ?',
            [$scheduleId],
        );
        if ($rows === []) {
            return null;
        }

        return (string) $rows[0]['last_due_at'];
    }

    public function markSent(int $scheduleId, string $dueAt, string $sentAt): void
    {
        $existing = $this->db->query(
            'SELECT schedule_id FROM hc_late_dose_alert_log WHERE schedule_id = ?',
            [$scheduleId],
        );
        if ($existing === []) {
            $this->db->execute(
                'INSERT INTO hc_late_dose_alert_log
                    (schedule_id, last_due_at, sent_at) VALUES (?, ?, ?)',
                [$scheduleId, $dueAt, $sentAt],
            );
        } else {
            $this->db->execute(
                'UPDATE hc_late_dose_alert_log
                    SET last_due_at = ?, sent_at = ? WHERE schedule_id = ?',
                [$dueAt, $sentAt, $scheduleId],
            );
        }
    }
}
