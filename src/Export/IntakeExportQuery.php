<?php

declare(strict_types=1);

namespace HomeCare\Export;

use HomeCare\Database\DatabaseInterface;

/**
 * Pulls the denormalised row set consumed by both the CSV and FHIR
 * exporters. Keeping the JOIN in one place means the two output
 * formats can't drift on what "an intake record" means.
 *
 * @phpstan-type IntakeExportRow array{
 *     intake_id:int,
 *     schedule_id:int,
 *     patient_id:int,
 *     patient_name:string,
 *     medicine_id:int,
 *     medicine_name:string,
 *     medicine_dosage:string,
 *     frequency:string,
 *     unit_per_dose:float,
 *     taken_time:string,
 *     note:?string
 * }
 */
final class IntakeExportQuery
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * Fetch intake rows for $patientId between [$startDate, $endDate]
     * (inclusive of both bounds). Dates are 'YYYY-MM-DD'; the bounds
     * are expanded to full-day windows internally.
     *
     * @return list<IntakeExportRow>
     */
    public function fetch(int $patientId, string $startDate, string $endDate): array
    {
        $rows = $this->db->query(
            'SELECT
                mi.id              AS intake_id,
                ms.id              AS schedule_id,
                ms.patient_id      AS patient_id,
                p.name             AS patient_name,
                m.id               AS medicine_id,
                m.name             AS medicine_name,
                m.dosage           AS medicine_dosage,
                ms.frequency       AS frequency,
                ms.unit_per_dose   AS unit_per_dose,
                mi.taken_time      AS taken_time,
                mi.note            AS note
             FROM hc_medicine_intake mi
             JOIN hc_medicine_schedules ms ON mi.schedule_id = ms.id
             JOIN hc_medicines m ON ms.medicine_id = m.id
             JOIN hc_patients p ON ms.patient_id = p.id
             WHERE ms.patient_id = ?
               AND mi.taken_time >= ?
               AND mi.taken_time <= ?
             ORDER BY mi.taken_time ASC, mi.id ASC',
            [$patientId, $startDate . ' 00:00:00', $endDate . ' 23:59:59'],
        );

        return array_map(
            static fn(array $r): array => [
                'intake_id' => (int) $r['intake_id'],
                'schedule_id' => (int) $r['schedule_id'],
                'patient_id' => (int) $r['patient_id'],
                'patient_name' => (string) $r['patient_name'],
                'medicine_id' => (int) $r['medicine_id'],
                'medicine_name' => (string) $r['medicine_name'],
                'medicine_dosage' => (string) $r['medicine_dosage'],
                'frequency' => (string) $r['frequency'],
                'unit_per_dose' => (float) $r['unit_per_dose'],
                'taken_time' => (string) $r['taken_time'],
                'note' => $r['note'] === null ? null : (string) $r['note'],
            ],
            $rows,
        );
    }
}
