<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Database\DatabaseInterface;

/**
 * "Which PRN schedules have hit their 24h dose ceiling?" (HC-238)
 *
 * Runs from the `send_reminders.php` cron alongside
 * {@see LateDoseAlertService} and {@see SupplyAlertService}, so a dose
 * recorded from ANY surface (web form, one-tap button, REST API, bulk
 * entry) is picked up on the next tick and delivered through the same
 * channel registry -- no notification I/O on the request path.
 *
 * Two public surfaces, mirroring the other alert services:
 *   - {@see shouldAlert()} is a pure throttle decision.
 *   - {@see findPendingAlerts()} walks active, limited PRN schedules.
 *
 * Throttle: one alert per schedule per 24h "episode", keyed on the
 * dose that triggered it. Escalating from 'reached' to 'exceeded'
 * within the episode alerts once more; further over-limit doses in the
 * same 24h do not (the caregiver already knows). An episode ends once
 * a newer dose is 24h or more past the last alerted one.
 */
final class PrnLimitAlertService
{
    /** @var callable():string */
    private readonly mixed $clock;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly PrnLimitService $limits,
        private readonly PrnLimitAlertLogInterface $log,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): string => date('Y-m-d H:i:s');
    }

    /**
     * Pure throttle decision.
     *
     * @param ?string $level          current level ('reached' / 'exceeded' / null)
     * @param string  $lastDoseAt     most recent dose in the window
     * @param ?string $lastAlertDoseAt dose the last alert was about (null = never)
     * @param ?string $lastAlertLevel level the last alert reported
     */
    public static function shouldAlert(
        ?string $level,
        string $lastDoseAt,
        ?string $lastAlertDoseAt,
        ?string $lastAlertLevel,
    ): bool {
        if ($level === null) {
            return false;
        }
        if ($lastAlertDoseAt === null) {
            return true;
        }
        if ($level === PrnLimitStatus::LEVEL_EXCEEDED
            && $lastAlertLevel === PrnLimitStatus::LEVEL_REACHED) {
            return true;
        }

        $doseTs = strtotime($lastDoseAt);
        $alertTs = strtotime($lastAlertDoseAt);
        if ($doseTs === false || $alertTs === false) {
            // A corrupt log row shouldn't silence a safety alert.
            return true;
        }

        return $doseTs - $alertTs >= PrnLimitService::WINDOW_SECONDS;
    }

    /**
     * Walk active PRN schedules that carry a max and return the alerts
     * to fire now. Does NOT write to the log -- call {@see recordSent()}
     * after a channel accepts the message so a failed push retries.
     *
     * The level is evaluated at "now", so doses that have already aged
     * out of the window (e.g. cron was down) don't produce stale alerts.
     *
     * @return list<PrnLimitAlert>
     */
    public function findPendingAlerts(): array
    {
        /** @var callable():string $clock */
        $clock = $this->clock;
        $now = ($clock)();
        $today = substr($now, 0, 10);

        $rows = $this->db->query(
            "SELECT ms.id AS schedule_id, ms.max_doses_per_24h,
                    m.name AS medicine_name, p.name AS patient_name
             FROM hc_medicine_schedules ms
             JOIN hc_medicines m ON ms.medicine_id = m.id
             JOIN hc_patients p ON ms.patient_id = p.id
             WHERE ms.is_prn = 'Y'
               AND ms.max_doses_per_24h IS NOT NULL
               AND ms.start_date <= ?
               AND (ms.end_date IS NULL OR ms.end_date >= ?)
             ORDER BY ms.id ASC",
            [$today, $today],
        );

        $alerts = [];
        foreach ($rows as $row) {
            $scheduleId = (int) $row['schedule_id'];
            $status = $this->limits->statusFor($scheduleId, $now);
            if ($status === null || $status->maxDosesPer24h === null || $status->lastTakenAt === null) {
                continue;
            }
            $level = $status->limitLevel();

            $last = $this->log->lastAlert($scheduleId);
            if (!self::shouldAlert($level, $status->lastTakenAt, $last['dose_at'] ?? null, $last['level'] ?? null)) {
                continue;
            }

            $alerts[] = new PrnLimitAlert(
                scheduleId: $scheduleId,
                medicineName: (string) $row['medicine_name'],
                patientName: (string) $row['patient_name'],
                level: (string) $level,
                dosesInWindow: $status->dosesInWindow,
                maxDoses: $status->maxDosesPer24h,
                lastDoseAt: $status->lastTakenAt,
                nextAllowedAt: $status->nextAllowedAt,
            );
        }

        return $alerts;
    }

    /**
     * Record that we alerted about this dose/level. Call AFTER channel
     * dispatch so a transport failure doesn't silence a retry.
     */
    public function recordSent(PrnLimitAlert $alert): void
    {
        /** @var callable():string $clock */
        $clock = $this->clock;
        $this->log->markSent($alert->scheduleId, $alert->lastDoseAt, $alert->level, ($clock)());
    }
}
