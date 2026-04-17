<?php

declare(strict_types=1);

namespace HomeCare\Tests\Factory;

use HomeCare\Database\DatabaseInterface;

/**
 * Test factory for hc_medicine_schedules (prescriptions).
 *
 * `patient_id` and `medicine_id` are required because schedules are FK-bound
 * on both sides -- inventing defaults would only hide missing setup. The
 * remaining fields (start_date, frequency, unit_per_dose) have sensible
 * defaults tuned to match the existing test corpus.
 *
 * @phpstan-type ScheduleOverrides array{
 *     patient_id:int,
 *     medicine_id:int,
 *     start_date?:string,
 *     end_date?:?string,
 *     frequency?:?string,
 *     unit_per_dose?:float,
 *     is_prn?:bool,
 *     dose_basis?:string,
 *     cycle_on_days?:?int,
 *     cycle_off_days?:?int
 * }
 * @phpstan-type ScheduleRecord array{
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
 *     cycle_off_days:?int
 * }
 */
final class ScheduleFactory
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @param ScheduleOverrides $overrides
     *
     * @return ScheduleRecord
     */
    public function create(array $overrides): array
    {
        $isPrn = ($overrides['is_prn'] ?? false) === true;
        $frequency = array_key_exists('frequency', $overrides) ? $overrides['frequency'] : '8h';
        if ($isPrn) {
            $frequency = null;
        }

        $doseBasis = $overrides['dose_basis'] ?? 'fixed';
        $cycleOn = $overrides['cycle_on_days'] ?? null;
        $cycleOff = $overrides['cycle_off_days'] ?? null;
        $record = [
            'patient_id' => $overrides['patient_id'],
            'medicine_id' => $overrides['medicine_id'],
            'start_date' => $overrides['start_date'] ?? '2026-01-01',
            'end_date' => $overrides['end_date'] ?? null,
            'frequency' => $frequency,
            'unit_per_dose' => $overrides['unit_per_dose'] ?? 1.0,
            'is_prn' => $isPrn,
            'dose_basis' => $doseBasis,
            'cycle_on_days' => $cycleOn,
            'cycle_off_days' => $cycleOff,
        ];

        $this->db->execute(
            'INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, end_date, frequency, unit_per_dose, is_prn, dose_basis, cycle_on_days, cycle_off_days)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $record['patient_id'],
                $record['medicine_id'],
                $record['start_date'],
                $record['end_date'],
                $record['frequency'],
                $record['unit_per_dose'],
                $isPrn ? 'Y' : 'N',
                $doseBasis,
                $cycleOn,
                $cycleOff,
            ],
        );

        return [
            'id' => $this->db->lastInsertId(),
            ...$record,
        ];
    }
}
