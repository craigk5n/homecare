<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * hc_user_patients persistence (HC-237).
 *
 * @phpstan-type AssignableUser array{
 *     login:string,
 *     firstname:?string,
 *     lastname:?string,
 *     role:string,
 *     is_admin:bool
 * }
 */
final class UserPatientRepository implements UserPatientRepositoryInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function getPatientIds(string $login): array
    {
        $rows = $this->db->query(
            'SELECT patient_id FROM hc_user_patients WHERE user_login = ? ORDER BY patient_id ASC',
            [$login],
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['patient_id'];
        }

        return $ids;
    }

    public function replacePatientIds(string $login, array $patientIds): void
    {
        $unique = array_values(array_unique(array_filter(
            $patientIds,
            static fn(int $id): bool => $id > 0,
        )));

        $this->db->transactional(function () use ($login, $unique): void {
            $this->db->execute('DELETE FROM hc_user_patients WHERE user_login = ?', [$login]);
            foreach ($unique as $patientId) {
                $this->db->execute(
                    'INSERT INTO hc_user_patients (user_login, patient_id) VALUES (?, ?)',
                    [$login, $patientId],
                );
            }
        });
    }

    /**
     * Every user, for the admin assignment screen, ordered by login.
     *
     * @return list<AssignableUser>
     */
    public function listUsers(): array
    {
        $rows = $this->db->query(
            'SELECT login, firstname, lastname, role, is_admin FROM hc_user ORDER BY login ASC',
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'login' => (string) $row['login'],
                'firstname' => $row['firstname'] === null ? null : (string) $row['firstname'],
                'lastname' => $row['lastname'] === null ? null : (string) $row['lastname'],
                'role' => (string) $row['role'],
                'is_admin' => (string) $row['is_admin'] === 'Y' || (string) $row['role'] === 'admin',
            ];
        }

        return $out;
    }

    /**
     * Number of assigned patients per login (logins with none are absent).
     *
     * @return array<string,int>
     */
    public function countsByUser(): array
    {
        $rows = $this->db->query(
            'SELECT user_login, COUNT(*) AS n FROM hc_user_patients GROUP BY user_login',
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['user_login']] = (int) $row['n'];
        }

        return $out;
    }
}
