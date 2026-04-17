<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Read contract for hc_drug_interactions (HC-112).
 *
 * @phpstan-import-type Interaction from InteractionRepository
 */
interface InteractionRepositoryInterface
{
    /**
     * Find interactions between a set of ingredients and another set.
     *
     * @param list<string> $ingredientsA
     * @param list<string> $ingredientsB
     *
     * @return list<Interaction>
     */
    public function findBetween(array $ingredientsA, array $ingredientsB): array;

    /**
     * Find all interactions for a given ingredient list (cross-check
     * every pair within the list).
     *
     * @param list<string> $ingredients
     *
     * @return list<Interaction>
     */
    public function findAmong(array $ingredients): array;

    /**
     * Insert or replace an interaction pair.
     */
    public function upsert(string $ingredientA, string $ingredientB, string $severity, ?string $description): void;
}
