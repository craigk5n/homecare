<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use InvalidArgumentException;

/**
 * Pure role-hierarchy checks: admin > caregiver > viewer.
 *
 * The class takes the current role as a constructor argument so callers
 * can wire it up however they like -- from a session, from a DB lookup
 * keyed on `$login`, or from a test fixture. No globals, no side effects.
 *
 * Unknown role strings are rejected with {@see InvalidArgumentException}
 * rather than silently degraded, so bad data surfaces immediately.
 *
 * @phpstan-type Role 'admin'|'caregiver'|'viewer'
 */
final class Authorization
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_CAREGIVER = 'caregiver';
    public const ROLE_VIEWER = 'viewer';

    /**
     * Rank table for hierarchy comparisons. Higher rank can do everything a
     * lower rank can.
     *
     * @var array<Role,int>
     */
    private const RANKS = [
        self::ROLE_VIEWER => 1,
        self::ROLE_CAREGIVER => 2,
        self::ROLE_ADMIN => 3,
    ];

    /** @var Role */
    private readonly string $role;

    public function __construct(string $role)
    {
        if (!isset(self::RANKS[$role])) {
            throw new InvalidArgumentException("Unknown role: {$role}");
        }
        /** @var Role $role */
        $this->role = $role;
    }

    public function getCurrentRole(): string
    {
        return $this->role;
    }

    /**
     * Can perform writes: caregiver or admin.
     */
    public function canWrite(): bool
    {
        return $this->satisfies(self::ROLE_CAREGIVER);
    }

    /**
     * Can perform admin-only actions (user management, settings).
     */
    public function canAdmin(): bool
    {
        return $this->satisfies(self::ROLE_ADMIN);
    }

    /**
     * True when the current role is at least as privileged as $minimumRole.
     */
    public function satisfies(string $minimumRole): bool
    {
        if (!isset(self::RANKS[$minimumRole])) {
            throw new InvalidArgumentException("Unknown minimum role: {$minimumRole}");
        }

        return self::RANKS[$this->role] >= self::RANKS[$minimumRole];
    }
}
