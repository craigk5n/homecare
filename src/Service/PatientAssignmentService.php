<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Repository\UserPatientRepositoryInterface;
use InvalidArgumentException;

/**
 * Admin save path for HC-237 patient assignments.
 *
 * Validates the target user (must exist and must not be an admin --
 * admins always see every patient), drops ids that are not real
 * patients, replaces the assignments and reports what changed so the
 * caller can write the audit entry.
 *
 * @phpstan-type AssignmentChange array{
 *     before:list<int>,
 *     after:list<int>,
 *     added:list<int>,
 *     removed:list<int>
 * }
 */
final class PatientAssignmentService
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly UserPatientRepositoryInterface $assignments,
    ) {}

    /**
     * @param list<int> $patientIds Empty = no limit (sees every patient).
     *
     * @return AssignmentChange
     *
     * @throws InvalidArgumentException unknown user or admin target
     */
    public function assign(string $login, array $patientIds): array
    {
        $rows = $this->db->query('SELECT role, is_admin FROM hc_user WHERE login = ?', [$login]);
        if ($rows === []) {
            throw new InvalidArgumentException("Unknown user: {$login}");
        }
        if ((string) $rows[0]['is_admin'] === 'Y' || (string) $rows[0]['role'] === 'admin') {
            throw new InvalidArgumentException('Admins always see every patient; nothing to assign.');
        }

        $existing = [];
        foreach ($this->db->query('SELECT id FROM hc_patients') as $row) {
            $existing[(int) $row['id']] = true;
        }
        $wanted = [];
        foreach ($patientIds as $id) {
            if (isset($existing[$id])) {
                $wanted[$id] = $id;
            }
        }
        ksort($wanted);
        $after = array_values($wanted);

        $before = $this->assignments->getPatientIds($login);
        $this->assignments->replacePatientIds($login, $after);

        return [
            'before' => $before,
            'after' => $after,
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ];
    }
}
