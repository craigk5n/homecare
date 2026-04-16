<?php

declare(strict_types=1);

namespace HomeCare\Report;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Service\InventoryService;

/**
 * Builds the data set for the printable medication summary (HC-021).
 *
 * A caregiver-facing "one sheet of paper" for a vet/doctor visit:
 * patient name, what they're currently taking, and what they stopped
 * taking recently. The rendering page hands the output straight to a
 * print template; no mutation inside.
 *
 * @phpstan-type ActiveSchedule array{
 *     schedule_id:int,
 *     medicine_id:int,
 *     medicine_name:string,
 *     dosage:string,
 *     frequency:string,
 *     unit_per_dose:float,
 *     start_date:string,
 *     end_date:?string,
 *     remaining_doses:float,
 *     remaining_days:int,
 *     last_inventory:?float
 * }
 *
 * @phpstan-type DiscontinuedSchedule array{
 *     schedule_id:int,
 *     medicine_id:int,
 *     medicine_name:string,
 *     dosage:string,
 *     frequency:string,
 *     unit_per_dose:float,
 *     start_date:string,
 *     end_date:string
 * }
 *
 * @phpstan-type Summary array{
 *     patient:array{id:int,name:string},
 *     generated_at:string,
 *     today:string,
 *     discontinued_window_days:int,
 *     active:list<ActiveSchedule>,
 *     discontinued:list<DiscontinuedSchedule>
 * }
 */
final class MedicationSummaryReport
{
    public const DEFAULT_DISCONTINUED_WINDOW_DAYS = 90;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly InventoryService $inventory,
    ) {
    }

    /**
     * @return Summary|null Null when the patient does not exist.
     */
    public function build(
        int $patientId,
        string $today,
        int $discontinuedWindowDays = self::DEFAULT_DISCONTINUED_WINDOW_DAYS
    ): ?array {
        $patient = $this->fetchPatient($patientId);
        if ($patient === null) {
            return null;
        }

        return [
            'patient' => $patient,
            'generated_at' => date('Y-m-d H:i'),
            'today' => $today,
            'discontinued_window_days' => $discontinuedWindowDays,
            'active' => $this->fetchActive($patientId, $today),
            'discontinued' => $this->fetchDiscontinued($patientId, $today, $discontinuedWindowDays),
        ];
    }

    /**
     * @return array{id:int,name:string}|null
     */
    private function fetchPatient(int $patientId): ?array
    {
        $rows = $this->db->query(
            'SELECT id, name FROM hc_patients WHERE id = ?',
            [$patientId]
        );
        if ($rows === []) {
            return null;
        }

        return [
            'id' => (int) $rows[0]['id'],
            'name' => (string) $rows[0]['name'],
        ];
    }

    /**
     * @return list<ActiveSchedule>
     */
    private function fetchActive(int $patientId, string $today): array
    {
        $rows = $this->db->query(
            'SELECT ms.id, ms.medicine_id, m.name, m.dosage, ms.frequency,
                    ms.unit_per_dose, ms.start_date, ms.end_date, ms.is_prn
             FROM hc_medicine_schedules ms
             JOIN hc_medicines m ON ms.medicine_id = m.id
             WHERE ms.patient_id = ?
               AND ms.start_date <= ?
               AND (ms.end_date IS NULL OR ms.end_date >= ?)
             ORDER BY m.name ASC',
            [$patientId, $today, $today]
        );

        $active = [];
        foreach ($rows as $row) {
            $medicineId = (int) $row['medicine_id'];
            $scheduleId = (int) $row['id'];
            $isPrn = isset($row['is_prn']) && (string) $row['is_prn'] === 'Y';
            $remaining = $this->inventory->calculateRemaining($medicineId, $scheduleId);

            $active[] = [
                'schedule_id' => $scheduleId,
                'medicine_id' => $medicineId,
                'medicine_name' => (string) $row['name'],
                'dosage' => (string) $row['dosage'],
                'frequency' => $isPrn ? 'PRN' : (string) ($row['frequency'] ?? ''),
                'unit_per_dose' => (float) $row['unit_per_dose'],
                'start_date' => (string) $row['start_date'],
                'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
                'remaining_doses' => (float) $remaining['remainingDoses'],
                'remaining_days' => (int) $remaining['remainingDays'],
                'last_inventory' => $remaining['lastInventory'] === null
                    ? null : (float) $remaining['lastInventory'],
            ];
        }

        return $active;
    }

    /**
     * @return list<DiscontinuedSchedule>
     */
    private function fetchDiscontinued(int $patientId, string $today, int $windowDays): array
    {
        $base = strtotime("{$today} -{$windowDays} days");
        if ($base === false) {
            throw new \InvalidArgumentException("Unparseable today/window: {$today} -{$windowDays} days");
        }
        $cutoff = date('Y-m-d', $base);

        $rows = $this->db->query(
            'SELECT ms.id, ms.medicine_id, m.name, m.dosage, ms.frequency,
                    ms.unit_per_dose, ms.start_date, ms.end_date, ms.is_prn
             FROM hc_medicine_schedules ms
             JOIN hc_medicines m ON ms.medicine_id = m.id
             WHERE ms.patient_id = ?
               AND ms.end_date IS NOT NULL
               AND ms.end_date < ?
               AND ms.end_date >= ?
             ORDER BY ms.end_date DESC, m.name ASC',
            [$patientId, $today, $cutoff]
        );

        $out = [];
        foreach ($rows as $row) {
            $isPrn = isset($row['is_prn']) && (string) $row['is_prn'] === 'Y';
            $out[] = [
                'schedule_id' => (int) $row['id'],
                'medicine_id' => (int) $row['medicine_id'],
                'medicine_name' => (string) $row['name'],
                'dosage' => (string) $row['dosage'],
                'frequency' => $isPrn ? 'PRN' : (string) ($row['frequency'] ?? ''),
                'unit_per_dose' => (float) $row['unit_per_dose'],
                'start_date' => (string) $row['start_date'],
                'end_date' => (string) $row['end_date'],
            ];
        }

        return $out;
    }
}
