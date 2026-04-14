<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Database\DatabaseInterface;

/**
 * GET /api/v1/schedules.php?patient_id=N
 *
 * Returns a patient's active medication schedules joined with each
 * medication's name and dosage. "Active" = start_date <= today AND
 * (end_date IS NULL OR end_date >= today).
 *
 * Response shape per row:
 *   {
 *     "schedule_id": 1,
 *     "patient_id": 1,
 *     "medicine_id": 5,
 *     "medicine_name": "Sildenafil",
 *     "medicine_dosage": "20mg",
 *     "frequency": "8h",
 *     "unit_per_dose": 1.0,
 *     "start_date": "2026-01-01",
 *     "end_date": null
 *   }
 */
final class SchedulesApi
{
    /** @var callable():string */
    private readonly mixed $clock;

    public function __construct(
        private readonly DatabaseInterface $db,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d');
    }

    /**
     * @param array<string,mixed> $query
     */
    public function handle(array $query): ApiResponse
    {
        $patientId = self::intParam($query, 'patient_id');
        if ($patientId === null) {
            return ApiResponse::error('patient_id is required (positive integer)', 400);
        }

        $patient = $this->db->query('SELECT id FROM hc_patients WHERE id = ?', [$patientId]);
        if ($patient === []) {
            return ApiResponse::error('patient not found', 404);
        }

        /** @var callable():string $clock */
        $clock = $this->clock;
        $today = ($clock)();

        $rows = $this->db->query(
            'SELECT ms.id, ms.patient_id, ms.medicine_id, m.name, m.dosage,
                    ms.frequency, ms.unit_per_dose, ms.start_date, ms.end_date
             FROM hc_medicine_schedules ms
             JOIN hc_medicines m ON ms.medicine_id = m.id
             WHERE ms.patient_id = ?
               AND ms.start_date <= ?
               AND (ms.end_date IS NULL OR ms.end_date >= ?)
             ORDER BY m.name ASC, ms.id ASC',
            [$patientId, $today, $today]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'schedule_id' => (int) $r['id'],
                'patient_id' => (int) $r['patient_id'],
                'medicine_id' => (int) $r['medicine_id'],
                'medicine_name' => (string) $r['name'],
                'medicine_dosage' => (string) $r['dosage'],
                'frequency' => (string) $r['frequency'],
                'unit_per_dose' => (float) $r['unit_per_dose'],
                'start_date' => (string) $r['start_date'],
                'end_date' => $r['end_date'] === null ? null : (string) $r['end_date'],
            ];
        }

        return ApiResponse::ok($out);
    }

    /**
     * @param array<string,mixed> $query
     */
    private static function intParam(array $query, string $key): ?int
    {
        if (!isset($query[$key]) || !is_scalar($query[$key])) {
            return null;
        }
        $raw = (string) $query[$key];
        if (!preg_match('/^\d+$/', $raw)) {
            return null;
        }
        $n = (int) $raw;

        return $n > 0 ? $n : null;
    }
}
