<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Database\DatabaseInterface;

/**
 * hc_prn_limit_alert_log-backed implementation of
 * {@see PrnLimitAlertLogInterface}.
 *
 * SELECT + INSERT/UPDATE (not UPSERT) for MySQL/SQLite portability,
 * same pattern as `LateDoseAlertLog`.
 */
final class PrnLimitAlertLog implements PrnLimitAlertLogInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function lastAlert(int $scheduleId): ?array
    {
        $rows = $this->db->query(
            'SELECT last_dose_at, last_level FROM hc_prn_limit_alert_log WHERE schedule_id = ?',
            [$scheduleId],
        );
        if ($rows === []) {
            return null;
        }

        return [
            'dose_at' => (string) $rows[0]['last_dose_at'],
            'level' => (string) $rows[0]['last_level'],
        ];
    }

    public function markSent(int $scheduleId, string $doseAt, string $level, string $sentAt): void
    {
        $existing = $this->db->query(
            'SELECT schedule_id FROM hc_prn_limit_alert_log WHERE schedule_id = ?',
            [$scheduleId],
        );
        if ($existing === []) {
            $this->db->execute(
                'INSERT INTO hc_prn_limit_alert_log
                    (schedule_id, last_dose_at, last_level, sent_at) VALUES (?, ?, ?, ?)',
                [$scheduleId, $doseAt, $level, $sentAt],
            );
        } else {
            $this->db->execute(
                'UPDATE hc_prn_limit_alert_log
                    SET last_dose_at = ?, last_level = ?, sent_at = ? WHERE schedule_id = ?',
                [$doseAt, $level, $sentAt, $scheduleId],
            );
        }
    }
}
