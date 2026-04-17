<?php

declare(strict_types=1);

namespace HomeCare\Report;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Service\AdherenceService;

/**
 * Per-medication adherence snapshot for a patient: 7-day, 30-day, and
 * 90-day rates side-by-side. Consumed by `report_adherence.php` (the
 * caregiver UI) and suitable for the chart + table the page renders.
 *
 * Trending the 7/30/90 windows together makes drift obvious -- a
 * medication whose 7-day rate is 55% while 30/90-day rates sit above
 * 90% is a fresh problem, not a chronic one.
 *
 * @phpstan-import-type AdherenceReport from AdherenceService
 *
 * @phpstan-type PatientAdherenceRow array{
 *     schedule_id:int,
 *     medicine_id:int,
 *     medicine_name:string,
 *     dosage:string,
 *     frequency:string,
 *     adherence_7d:AdherenceReport,
 *     adherence_30d:AdherenceReport,
 *     adherence_90d:AdherenceReport
 * }
 */
final class PatientAdherenceReport
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly AdherenceService $adherence,
    ) {}

    /**
     * Build the per-schedule adherence snapshot.
     *
     * By default the filter includes every schedule that was active at
     * any point in the last 90 days (the widest of the three default
     * columns). Callers that render a custom window can pass
     * `$filterStart` / `$filterEnd` to widen the filter so a
     * long-discontinued schedule still surfaces when the user is looking
     * at a historical date range. Without the override, a 4-week
     * antibiotic course that ended 6 months ago is silently hidden —
     * confusing when the caller is asking "how adherent was Daisy with
     * Tobramycin back in October?"
     *
     * @return list<PatientAdherenceRow>
     */
    public function build(
        int $patientId,
        string $today,
        ?string $filterStart = null,
        ?string $filterEnd = null,
    ): array {
        $filterStart ??= self::daysAgo($today, 89);
        $filterEnd ??= $today;

        // Overlap filter: schedule lifetime [start_date, end_date?] intersects
        // the filter window [filterStart, filterEnd].
        // HC-120: PRN schedules have no expected cadence, so adherence
        // is not a meaningful metric — exclude them from the report.
        $rows = $this->db->query(
            "SELECT ms.id, ms.medicine_id, m.name, m.dosage, ms.frequency,
                    ms.start_date, ms.end_date
             FROM hc_medicine_schedules ms
             JOIN hc_medicines m ON ms.medicine_id = m.id
             WHERE ms.patient_id = ?
               AND ms.is_prn = 'N'
               AND ms.frequency IS NOT NULL
               AND ms.start_date <= ?
               AND (ms.end_date IS NULL OR ms.end_date >= ?)
             ORDER BY m.name ASC, ms.id ASC",
            [$patientId, $filterEnd, $filterStart],
        );

        $out = [];
        foreach ($rows as $row) {
            $scheduleId = (int) $row['id'];
            $out[] = [
                'schedule_id' => $scheduleId,
                'medicine_id' => (int) $row['medicine_id'],
                'medicine_name' => (string) $row['name'],
                'dosage' => (string) $row['dosage'],
                'frequency' => (string) $row['frequency'],
                'adherence_7d' => $this->adherence->calculateAdherence(
                    $scheduleId,
                    self::daysAgo($today, 6),
                    $today,
                ),
                'adherence_30d' => $this->adherence->calculateAdherence(
                    $scheduleId,
                    self::daysAgo($today, 29),
                    $today,
                ),
                'adherence_90d' => $this->adherence->calculateAdherence(
                    $scheduleId,
                    self::daysAgo($today, 89),
                    $today,
                ),
            ];
        }

        return $out;
    }

    /**
     * Compute adherence for a single schedule + arbitrary window.
     *
     * Used for the "custom" range on the report page; kept here so the
     * UI doesn't reach through to AdherenceService directly and keep
     * its composition surface tidy.
     *
     * @return AdherenceReport
     */
    public function calculateCustom(int $scheduleId, string $startDate, string $endDate): array
    {
        return $this->adherence->calculateAdherence($scheduleId, $startDate, $endDate);
    }

    private static function daysAgo(string $today, int $days): string
    {
        $ts = strtotime("{$today} -{$days} days");
        if ($ts === false) {
            throw new \InvalidArgumentException("Unparseable date: {$today}");
        }

        return date('Y-m-d', $ts);
    }
}
