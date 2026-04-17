<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;
use InvalidArgumentException;

/**
 * Read/write access to hc_medicine_schedules (a patient's prescriptions).
 *
 * Active-vs-ended filtering takes "today" as an explicit parameter instead
 * of reaching for SQL's CURDATE()/DATE('now'). That keeps the query
 * portable across MySQL and SQLite and makes time-dependent tests
 * deterministic without stubbing the clock.
 *
 * @phpstan-type Schedule array{
 *     id:int,
 *     patient_id:int,
 *     medicine_id:int,
 *     start_date:string,
 *     end_date:?string,
 *     frequency:?string,
 *     unit_per_dose:float,
 *     is_prn:bool,
 *     dose_basis:string,
 *     cycle_on_days:?int,
 *     cycle_off_days:?int,
 *     wall_clock_times:?string,
 *     created_at:?string
 * }
 *
 * @phpstan-type ScheduleInput array{
 *     patient_id:int,
 *     medicine_id:int,
 *     start_date:string,
 *     end_date?:?string,
 *     frequency?:?string,
 *     unit_per_dose:float,
 *     is_prn?:bool,
 *     dose_basis?:string,
 *     cycle_on_days?:?int,
 *     cycle_off_days?:?int,
 *     wall_clock_times?:?string
 * }
 */
final class ScheduleRepository implements ScheduleRepositoryInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @return Schedule|null
     */
    public function getScheduleById(int $id): ?array
    {
        $rows = $this->db->query($this->selectAllColumns() . ' WHERE id = ?', [$id]);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * Schedules whose start_date <= $today AND (end_date IS NULL OR end_date >= $today).
     *
     * @return list<Schedule>
     */
    public function getActiveSchedules(int $patientId, string $today): array
    {
        $rows = $this->db->query(
            $this->selectAllColumns()
            . ' WHERE patient_id = ?'
            . '   AND start_date <= ?'
            . '   AND (end_date IS NULL OR end_date >= ?)'
            . ' ORDER BY id ASC',
            [$patientId, $today, $today]
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function endSchedule(int $id, string $endDate): bool
    {
        return $this->db->execute(
            'UPDATE hc_medicine_schedules SET end_date = ? WHERE id = ?',
            [$endDate, $id]
        );
    }

    /**
     * @param ScheduleInput $data
     */
    public function createSchedule(array $data): int
    {
        foreach (['patient_id', 'medicine_id', 'start_date', 'unit_per_dose'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException("createSchedule: missing required field '{$required}'");
            }
        }

        // Frequency is required for fixed-cadence schedules but must be NULL
        // for PRN schedules (HC-120) so downstream math can short-circuit.
        $isPrn = ($data['is_prn'] ?? false) === true;
        $frequency = $data['frequency'] ?? null;
        if (!$isPrn && ($frequency === null || $frequency === '')) {
            throw new InvalidArgumentException(
                "createSchedule: 'frequency' is required unless is_prn is true"
            );
        }
        if ($isPrn) {
            $frequency = null;
        }

        $doseBasis = $data['dose_basis'] ?? 'fixed';
        $cycleOn = $data['cycle_on_days'] ?? null;
        $cycleOff = $data['cycle_off_days'] ?? null;
        $wallClock = $data['wall_clock_times'] ?? null;

        $this->db->execute(
            'INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose, is_prn, dose_basis, cycle_on_days, cycle_off_days, wall_clock_times)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['patient_id'],
                $data['medicine_id'],
                $data['start_date'],
                $data['end_date'] ?? null,
                $frequency,
                $data['unit_per_dose'],
                $isPrn ? 'Y' : 'N',
                $doseBasis,
                $cycleOn,
                $cycleOff,
                $wallClock,
            ]
        );

        return $this->db->lastInsertId();
    }

    private function selectAllColumns(): string
    {
        return 'SELECT id, patient_id, medicine_id, start_date, end_date, frequency, '
            . 'unit_per_dose, is_prn, dose_basis, cycle_on_days, cycle_off_days, '
            . 'wall_clock_times, created_at FROM hc_medicine_schedules';
    }

    /**
     * @param array<string,scalar|null> $row
     *
     * @return Schedule
     */
    private static function hydrate(array $row): array
    {
        $frequency = $row['frequency'];
        $isPrn = isset($row['is_prn']) && (string) $row['is_prn'] === 'Y';

        return [
            'id' => (int) $row['id'],
            'patient_id' => (int) $row['patient_id'],
            'medicine_id' => (int) $row['medicine_id'],
            'start_date' => (string) $row['start_date'],
            'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
            'frequency' => $frequency === null || $frequency === '' ? null : (string) $frequency,
            'unit_per_dose' => (float) $row['unit_per_dose'],
            'is_prn' => $isPrn,
            'dose_basis' => isset($row['dose_basis']) ? (string) $row['dose_basis'] : 'fixed',
            'cycle_on_days' => $row['cycle_on_days'] === null ? null : (int) $row['cycle_on_days'],
            'cycle_off_days' => $row['cycle_off_days'] === null ? null : (int) $row['cycle_off_days'],
            'wall_clock_times' => $row['wall_clock_times'] === null ? null : (string) $row['wall_clock_times'],
            'created_at' => $row['created_at'] === null ? null : (string) $row['created_at'],
        ];
    }
}
