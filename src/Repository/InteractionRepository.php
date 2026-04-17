<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Access to hc_drug_interactions — ingredient-level interaction pairs (HC-112).
 *
 * Ingredients are stored alphabetically ordered (ingredient_a < ingredient_b)
 * so each pair exists only once. Lookups check both directions.
 *
 * @phpstan-type Interaction array{
 *     ingredient_a:string,
 *     ingredient_b:string,
 *     severity:string,
 *     description:?string
 * }
 */
final class InteractionRepository implements InteractionRepositoryInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @param list<string> $ingredientsA
     * @param list<string> $ingredientsB
     *
     * @return list<Interaction>
     */
    public function findBetween(array $ingredientsA, array $ingredientsB): array
    {
        if ($ingredientsA === [] || $ingredientsB === []) {
            return [];
        }

        $allIngredients = array_unique(array_merge(
            array_map('strtolower', $ingredientsA),
            array_map('strtolower', $ingredientsB),
        ));

        $setA = array_map('strtolower', $ingredientsA);
        $setB = array_map('strtolower', $ingredientsB);

        $placeholders = implode(',', array_fill(0, count($allIngredients), '?'));
        $params = array_merge($allIngredients, $allIngredients);

        $rows = $this->db->query(
            "SELECT ingredient_a, ingredient_b, severity, description
             FROM hc_drug_interactions
             WHERE ingredient_a IN ($placeholders)
               AND ingredient_b IN ($placeholders)
             ORDER BY CASE severity
                WHEN 'major' THEN 1
                WHEN 'moderate' THEN 2
                ELSE 3
             END, ingredient_a",
            $params,
        );

        $results = [];
        foreach ($rows as $row) {
            $a = strtolower((string) $row['ingredient_a']);
            $b = strtolower((string) $row['ingredient_b']);
            $aInA = in_array($a, $setA, true);
            $aInB = in_array($a, $setB, true);
            $bInA = in_array($b, $setA, true);
            $bInB = in_array($b, $setB, true);

            if (($aInA && $bInB) || ($aInB && $bInA)) {
                $results[] = $this->hydrate($row);
            }
        }

        return $results;
    }

    /**
     * @param list<string> $ingredients
     *
     * @return list<Interaction>
     */
    public function findAmong(array $ingredients): array
    {
        return $this->findBetween($ingredients, $ingredients);
    }

    public function upsert(string $ingredientA, string $ingredientB, string $severity, ?string $description): void
    {
        [$a, $b] = self::ordered($ingredientA, $ingredientB);

        $this->db->execute(
            'DELETE FROM hc_drug_interactions WHERE ingredient_a = ? AND ingredient_b = ?',
            [$a, $b],
        );

        $this->db->execute(
            'INSERT INTO hc_drug_interactions (ingredient_a, ingredient_b, severity, description)
             VALUES (?, ?, ?, ?)',
            [$a, $b, $severity, $description],
        );
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return Interaction
     */
    private function hydrate(array $row): array
    {
        return [
            'ingredient_a' => (string) $row['ingredient_a'],
            'ingredient_b' => (string) $row['ingredient_b'],
            'severity' => (string) $row['severity'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
        ];
    }

    /**
     * Alphabetically order a pair of ingredients for canonical storage.
     *
     * @return array{0:string, 1:string}
     */
    private static function ordered(string $a, string $b): array
    {
        $la = strtolower(trim($a));
        $lb = strtolower(trim($b));

        return $la <= $lb ? [$la, $lb] : [$lb, $la];
    }
}
