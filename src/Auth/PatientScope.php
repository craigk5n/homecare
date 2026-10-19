<?php

declare(strict_types=1);

namespace HomeCare\Auth;

/**
 * The set of patients one user may see (HC-237).
 *
 * Immutable value object produced by {@see PatientAccess}. Either
 * unrestricted (admins, users with no assignments, and system
 * contexts such as CLI/cron) or an explicit allow-list of patient ids.
 * An empty allow-list allows nothing.
 */
final class PatientScope
{
    /**
     * @param list<int>|null $ids null = unrestricted; sorted, unique, positive
     */
    private function __construct(private readonly ?array $ids) {}

    public static function unrestricted(): self
    {
        return new self(null);
    }

    /**
     * @param array<int|string,int> $patientIds
     */
    public static function only(array $patientIds): self
    {
        $ids = [];
        foreach ($patientIds as $id) {
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids);

        return new self(array_values($ids));
    }

    public function isUnrestricted(): bool
    {
        return $this->ids === null;
    }

    /**
     * @return list<int>|null null when unrestricted
     */
    public function patientIds(): ?array
    {
        return $this->ids;
    }

    public function allows(int $patientId): bool
    {
        return $this->ids === null || in_array($patientId, $this->ids, true);
    }

    /**
     * Visibility of a resource shared across patients (a catalog medicine
     * and its stock): allowed when it is linked to at least one allowed
     * patient, or when it is not linked to any patient yet.
     *
     * @param list<int> $linkedPatientIds
     */
    public function allowsShared(array $linkedPatientIds): bool
    {
        if ($this->ids === null || $linkedPatientIds === []) {
            return true;
        }
        foreach ($linkedPatientIds as $patientId) {
            if ($this->allows($patientId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only rows whose `$key` column is an allowed patient id.
     *
     * Rows without the key are dropped when restricted (fail closed).
     *
     * @template TRow of array<array-key,mixed>
     *
     * @param list<TRow> $rows
     *
     * @return list<TRow>
     */
    public function filter(array $rows, string $key = 'id'): array
    {
        if ($this->ids === null) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn(array $row): bool => isset($row[$key]) && is_numeric($row[$key]) && $this->allows((int) $row[$key]),
        ));
    }
}
