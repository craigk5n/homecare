<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Domain\ScheduleCalculator;
use InvalidArgumentException;

/**
 * "Which schedules have missed their dose long enough to escalate?"
 *
 * Two public surfaces, mirroring {@see SupplyAlertService}:
 *   - {@see shouldAlert()} is a pure decision function — unit
 *     tests drive every branch without a DB.
 *   - {@see findPendingAlerts()} walks active schedules, asks the
 *     service for each "late yet?" verdict, and returns a list of
 *     {@see LateDoseAlert}.
 *
 * Replay suppression is keyed on the exact due instant
 * (`hc_late_dose_alert_log.last_due_at`). A permanently-overdue
 * schedule gets exactly one alert; when the caregiver records the
 * dose the due instant shifts and the next miss re-arms the alert.
 */
final class LateDoseAlertService
{
    public const DEFAULT_THRESHOLD_MINUTES = 60;

    /** @var callable():string */
    private readonly mixed $clock;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly LateDoseAlertLogInterface $log,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    /**
     * Pure decision function. Returns true iff:
     *
     *   1. `$thresholdMinutes > 0` (feature off when 0),
     *   2. $lastTaken + frequency + threshold ≤ $now, AND
     *   3. `$lastAlertDueAt` is either null or a different instant
     *      than the current dose's due timestamp.
     *
     * Returns false on unparseable timestamps or frequencies — fail
     * quiet, a malformed row shouldn't cause the cron loop to die
     * or mis-alert the caregiver.
     */
    public static function shouldAlert(
        string $lastTaken,
        string $frequency,
        int $thresholdMinutes,
        ?string $lastAlertDueAt,
        string $now,
    ): bool {
        if ($thresholdMinutes <= 0) {
            return false;
        }

        try {
            $freqSeconds = ScheduleCalculator::frequencyToSeconds($frequency);
        } catch (InvalidArgumentException) {
            return false;
        }

        $takenTs = strtotime($lastTaken);
        $nowTs = strtotime($now);
        if ($takenTs === false || $nowTs === false) {
            return false;
        }

        $dueTs = $takenTs + $freqSeconds;
        $alertAt = $dueTs + $thresholdMinutes * 60;
        if ($nowTs < $alertAt) {
            return false;
        }

        if ($lastAlertDueAt !== null) {
            $lastDueTs = strtotime($lastAlertDueAt);
            if ($lastDueTs !== false && $lastDueTs === $dueTs) {
                return false;
            }
        }

        return true;
    }

    /**
     * Walk every active schedule and return the alerts we should
     * fire right now. Does NOT write to the log — the caller calls
     * {@see recordSent()} after the push succeeds so a failed push
     * doesn't silence future retries.
     *
     * @return list<LateDoseAlert>
     */
    public function findPendingAlerts(
        int $thresholdMinutes = self::DEFAULT_THRESHOLD_MINUTES,
    ): array {
        if ($thresholdMinutes <= 0) {
            return [];
        }

        /** @var callable():string $clock */
        $clock = $this->clock;
        $now = ($clock)();
        $today = substr($now, 0, 10);
        $nowTs = strtotime($now);
        if ($nowTs === false) {
            return [];
        }

        // HC-120: skip PRN schedules — no cadence, "late" is meaningless.
        // HC-124: skip paused schedules — no alerts while on hold.
        $rows = $this->db->query(
            "SELECT ms.id AS schedule_id, ms.frequency, ms.start_date,
                    ms.cycle_on_days, ms.cycle_off_days,
                    m.name AS medicine_name, p.name AS patient_name,
                    (SELECT MAX(mi.taken_time)
                       FROM hc_medicine_intake mi
                      WHERE mi.schedule_id = ms.id) AS last_taken
             FROM hc_medicine_schedules ms
             JOIN hc_medicines m ON ms.medicine_id = m.id
             JOIN hc_patients p ON ms.patient_id = p.id
             WHERE ms.start_date <= ?
               AND ms.is_prn = 'N'
               AND ms.frequency IS NOT NULL
               AND (ms.end_date IS NULL OR ms.end_date >= ?)
               AND NOT EXISTS (
                   SELECT 1 FROM hc_schedule_pauses sp
                    WHERE sp.schedule_id = ms.id
                      AND sp.start_date <= ?
                      AND (sp.end_date IS NULL OR sp.end_date >= ?)
               )
             ORDER BY ms.id ASC",
            [$today, $today, $today, $today]
        );

        $alerts = [];
        foreach ($rows as $row) {
            $lastTaken = $row['last_taken'];
            if ($lastTaken === null || $lastTaken === '') {
                continue;
            }
            $scheduleId = (int) $row['schedule_id'];
            $frequency = (string) $row['frequency'];

            // HC-121: skip off-days in cycle schedules.
            $cycleOn = $row['cycle_on_days'] !== null ? (int) $row['cycle_on_days'] : null;
            $cycleOff = $row['cycle_off_days'] !== null ? (int) $row['cycle_off_days'] : null;
            if (!ScheduleCalculator::isOnDay((string) $row['start_date'], $cycleOn, $cycleOff, $today)) {
                continue;
            }

            $lastAlertDueAt = $this->log->lastDueAt($scheduleId);
            if (!self::shouldAlert(
                (string) $lastTaken,
                $frequency,
                $thresholdMinutes,
                $lastAlertDueAt,
                $now,
            )) {
                continue;
            }

            try {
                $freqSeconds = ScheduleCalculator::frequencyToSeconds($frequency);
            } catch (InvalidArgumentException) {
                continue;
            }
            $takenTs = strtotime((string) $lastTaken);
            if ($takenTs === false) {
                continue;
            }
            $dueTs = $takenTs + $freqSeconds;
            $minutesLate = (int) floor(($nowTs - $dueTs) / 60);

            $alerts[] = new LateDoseAlert(
                scheduleId:   $scheduleId,
                medicineName: (string) $row['medicine_name'],
                patientName:  (string) $row['patient_name'],
                dueAt:        date('Y-m-d H:i:s', $dueTs),
                minutesLate:  $minutesLate,
            );
        }

        return $alerts;
    }

    /**
     * Record that we alerted about this specific due instant. Call
     * AFTER the channel dispatch so a transport failure doesn't
     * silence a retry on the next cron tick.
     */
    public function recordSent(int $scheduleId, string $dueAt): void
    {
        /** @var callable():string $clock */
        $clock = $this->clock;
        $this->log->markSent($scheduleId, $dueAt, ($clock)());
    }
}
