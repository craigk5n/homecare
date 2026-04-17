<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Repository\InteractionRepositoryInterface;

/**
 * Drug interaction checking against a patient's active schedules (HC-112).
 *
 * Resolves ingredient names from hc_drug_catalog (via hc_medicines.drug_catalog_id)
 * and checks them against the hc_drug_interactions table.
 *
 * @phpstan-type InteractionResult array{
 *     ingredient_a:string,
 *     ingredient_b:string,
 *     severity:string,
 *     description:?string,
 *     existing_medicine:string
 * }
 */
final class InteractionService implements InteractionServiceInterface
{
    public function __construct(
        private readonly InteractionRepositoryInterface $interactions,
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * Check whether a medicine interacts with any of the patient's active schedules.
     *
     * @return list<InteractionResult>
     */
    public function checkForPatient(int $patientId, int $newMedicineId): array
    {
        $newIngredients = $this->getIngredients($newMedicineId);
        if ($newIngredients === []) {
            return [];
        }

        $activeRows = $this->db->query(
            "SELECT DISTINCT ms.medicine_id, m.name, m.drug_catalog_id
             FROM hc_medicine_schedules ms
             JOIN hc_medicines m ON m.id = ms.medicine_id
             WHERE ms.patient_id = ?
               AND ms.medicine_id != ?
               AND (ms.end_date IS NULL OR ms.end_date >= DATE('now'))
               AND ms.start_date <= DATE('now')",
            [$patientId, $newMedicineId],
        );

        $results = [];
        foreach ($activeRows as $row) {
            $existingMedicineId = (int) $row['medicine_id'];
            $existingName = (string) $row['name'];
            $existingIngredients = $this->getIngredients($existingMedicineId);

            if ($existingIngredients === []) {
                continue;
            }

            $found = $this->interactions->findBetween($newIngredients, $existingIngredients);
            foreach ($found as $interaction) {
                $results[] = [
                    'ingredient_a' => $interaction['ingredient_a'],
                    'ingredient_b' => $interaction['ingredient_b'],
                    'severity' => $interaction['severity'],
                    'description' => $interaction['description'],
                    'existing_medicine' => $existingName,
                ];
            }
        }

        usort($results, static function (array $a, array $b): int {
            $order = ['major' => 1, 'moderate' => 2, 'minor' => 3];

            return ($order[$a['severity']] ?? 4) <=> ($order[$b['severity']] ?? 4);
        });

        return $results;
    }

    /**
     * Check all active schedules for a patient for cross-interactions.
     *
     * @return list<InteractionResult>
     */
    public function checkAllForPatient(int $patientId): array
    {
        $activeRows = $this->db->query(
            "SELECT DISTINCT ms.medicine_id, m.name, m.drug_catalog_id
             FROM hc_medicine_schedules ms
             JOIN hc_medicines m ON m.id = ms.medicine_id
             WHERE ms.patient_id = ?
               AND (ms.end_date IS NULL OR ms.end_date >= DATE('now'))
               AND ms.start_date <= DATE('now')",
            [$patientId],
        );

        $medicineIngredients = [];
        foreach ($activeRows as $row) {
            $medId = (int) $row['medicine_id'];
            $ingredients = $this->getIngredients($medId);
            if ($ingredients !== []) {
                $medicineIngredients[] = [
                    'medicine_id' => $medId,
                    'name' => (string) $row['name'],
                    'ingredients' => $ingredients,
                ];
            }
        }

        $results = [];
        $seen = [];
        $count = count($medicineIngredients);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $found = $this->interactions->findBetween(
                    $medicineIngredients[$i]['ingredients'],
                    $medicineIngredients[$j]['ingredients'],
                );
                foreach ($found as $interaction) {
                    $key = $interaction['ingredient_a'] . '|' . $interaction['ingredient_b'];
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $results[] = [
                        'ingredient_a' => $interaction['ingredient_a'],
                        'ingredient_b' => $interaction['ingredient_b'],
                        'severity' => $interaction['severity'],
                        'description' => $interaction['description'],
                        'existing_medicine' => $medicineIngredients[$i]['name']
                            . ' / ' . $medicineIngredients[$j]['name'],
                    ];
                }
            }
        }

        usort($results, static function (array $a, array $b): int {
            $order = ['major' => 1, 'moderate' => 2, 'minor' => 3];

            return ($order[$a['severity']] ?? 4) <=> ($order[$b['severity']] ?? 4);
        });

        return $results;
    }

    /**
     * Get ingredient names for a medicine via its drug_catalog_id link.
     *
     * @return list<string>
     */
    private function getIngredients(int $medicineId): array
    {
        $rows = $this->db->query(
            'SELECT dc.ingredient_names
             FROM hc_medicines m
             JOIN hc_drug_catalog dc ON dc.id = m.drug_catalog_id
             WHERE m.id = ?',
            [$medicineId],
        );

        if ($rows === [] || $rows[0]['ingredient_names'] === null) {
            return [];
        }

        $raw = (string) $rows[0]['ingredient_names'];
        $parts = array_map('trim', explode('/', $raw));
        $parts = array_filter($parts, static fn(string $s): bool => $s !== '');

        return array_values(array_map('strtolower', $parts));
    }
}
