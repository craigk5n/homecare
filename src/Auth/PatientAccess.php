<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Repository\PatientLinkRepository;
use HomeCare\Repository\PatientLinkRepositoryInterface;
use HomeCare\Repository\UserPatientRepository;
use HomeCare\Repository\UserPatientRepositoryInterface;
use HomeCare\Repository\UserRepository;

/**
 * Per-patient access control (HC-237).
 *
 * Rules, in order:
 *   1. Admins see every patient. Their hc_user_patients rows, if any,
 *      are ignored.
 *   2. A caregiver or viewer with NO rows in hc_user_patients sees every
 *      patient. This is the pre-HC-237 behaviour, so existing installs
 *      are unchanged until an admin assigns someone.
 *   3. A caregiver or viewer with one or more rows sees only those
 *      patients.
 *   4. An unknown login sees nothing (fail closed).
 *
 * Role checks (admin > caregiver > viewer, {@see Authorization}) still
 * apply on top: this class only answers "which patients", never "which
 * actions". System contexts with no logged-in user (CLI/cron reminders,
 * digests) do not go through this class at all.
 *
 * Indirect ids are resolved through {@see PatientLinkRepositoryInterface}.
 * For a restricted user, an id that resolves to no patient is denied, so
 * a missing row can never be used to probe around the allow-list.
 * Medicines are a shared catalog: a restricted user may see a medicine's
 * inventory when it is scheduled for at least one allowed patient, or
 * when it is not scheduled for anyone yet.
 */
final class PatientAccess
{
    /** @var callable(string):?string */
    private readonly mixed $roleOf;

    /** @var array<string,PatientScope> */
    private array $cache = [];

    /**
     * @param callable(string):?string $roleOf Effective role for a login
     *                                         ('admin'|'caregiver'|'viewer'),
     *                                         null when the login is unknown.
     */
    public function __construct(
        private readonly UserPatientRepositoryInterface $assignments,
        private readonly PatientLinkRepositoryInterface $links,
        callable $roleOf,
    ) {
        $this->roleOf = $roleOf;
    }

    /**
     * Wire the SQL repositories against one connection. The role lookup
     * mirrors getCurrentUserRole(): legacy is_admin='Y' always promotes.
     */
    public static function create(DatabaseInterface $db): self
    {
        $users = new UserRepository($db);

        return new self(
            new UserPatientRepository($db),
            new PatientLinkRepository($db),
            static function (string $login) use ($users): ?string {
                $user = $users->findByLogin($login);
                if ($user === null) {
                    return null;
                }
                if ($user['is_admin'] === 'Y') {
                    return Authorization::ROLE_ADMIN;
                }

                return $user['role'] === '' ? Authorization::ROLE_CAREGIVER : $user['role'];
            },
        );
    }

    public function scopeFor(string $login): PatientScope
    {
        if (isset($this->cache[$login])) {
            return $this->cache[$login];
        }

        $role = $login === '' ? null : ($this->roleOf)($login);
        if ($role === null) {
            $scope = PatientScope::only([]);
        } elseif ($role === Authorization::ROLE_ADMIN) {
            $scope = PatientScope::unrestricted();
        } else {
            $ids = $this->assignments->getPatientIds($login);
            $scope = $ids === [] ? PatientScope::unrestricted() : PatientScope::only($ids);
        }

        return $this->cache[$login] = $scope;
    }

    /**
     * @return list<int>|null null = every patient
     */
    public function allowedPatientIds(string $login): ?array
    {
        return $this->scopeFor($login)->patientIds();
    }

    public function canAccess(string $login, int $patientId): bool
    {
        return $this->scopeFor($login)->allows($patientId);
    }

    /**
     * Keep only the rows (patients, or anything carrying a patient id
     * column) the user may see.
     *
     * @template TRow of array<array-key,mixed>
     *
     * @param list<TRow> $rows
     *
     * @return list<TRow>
     */
    public function filterPatients(string $login, array $rows, string $key = 'id'): array
    {
        return $this->scopeFor($login)->filter($rows, $key);
    }

    public function canAccessSchedule(string $login, int $scheduleId): bool
    {
        return $this->canAccessResolved($login, fn(): ?int => $this->links->patientIdForSchedule($scheduleId));
    }

    public function canAccessIntake(string $login, int $intakeId): bool
    {
        return $this->canAccessResolved($login, fn(): ?int => $this->links->patientIdForIntake($intakeId));
    }

    public function canAccessNote(string $login, int $noteId): bool
    {
        return $this->canAccessResolved($login, fn(): ?int => $this->links->patientIdForNote($noteId));
    }

    public function canAccessAttachment(string $login, int $attachmentId): bool
    {
        return $this->canAccessResolved($login, fn(): ?int => $this->links->patientIdForAttachment($attachmentId));
    }

    public function canAccessAttachmentOwner(string $login, string $ownerType, int $ownerId): bool
    {
        return $this->canAccessResolved($login, fn(): ?int => $this->links->patientIdForOwner($ownerType, $ownerId));
    }

    public function canAccessMedicine(string $login, int $medicineId): bool
    {
        $scope = $this->scopeFor($login);
        if ($scope->isUnrestricted()) {
            return true;
        }

        return $scope->allowsShared($this->links->patientIdsForMedicine($medicineId));
    }

    /**
     * Keep only the medicine rows whose inventory the user may see.
     *
     * @template TRow of array<array-key,mixed>
     *
     * @param list<TRow> $rows
     *
     * @return list<TRow>
     */
    public function filterMedicines(string $login, array $rows, string $key = 'medicine_id'): array
    {
        $scope = $this->scopeFor($login);
        if ($scope->isUnrestricted()) {
            return $rows;
        }

        $map = $this->links->patientIdsByMedicine();

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => isset($row[$key]) && is_numeric($row[$key])
                && $scope->allowsShared($map[(int) $row[$key]] ?? []),
        ));
    }

    /**
     * @param callable():?int $resolve
     */
    private function canAccessResolved(string $login, callable $resolve): bool
    {
        $scope = $this->scopeFor($login);
        if ($scope->isUnrestricted()) {
            return true;
        }
        $patientId = $resolve();

        return $patientId !== null && $scope->allows($patientId);
    }
}
