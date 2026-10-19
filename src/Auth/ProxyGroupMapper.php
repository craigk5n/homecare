<?php

declare(strict_types=1);

namespace HomeCare\Auth;

/**
 * Maps identity-provider group names to a HomeCare role (HC-243).
 *
 * Proxies send group membership as a single header value: Authentik
 * separates groups with `|`, Authelia with `,`. {@see parseGroups()}
 * accepts either (or both), trims whitespace, and drops empties. The
 * admin-configured group lists use the same parser, so group names
 * containing `|` or `,` are not supported.
 *
 * Matching is case-insensitive. When a user is in groups for more than
 * one role, the highest role wins (admin > caregiver > viewer).
 */
final class ProxyGroupMapper
{
    /** @var array<string,'admin'|'caregiver'|'viewer'> lowercased group name => role */
    private readonly array $roleByGroup;

    /**
     * @param list<string> $adminGroups
     * @param list<string> $caregiverGroups
     * @param list<string> $viewerGroups
     */
    public function __construct(array $adminGroups, array $caregiverGroups, array $viewerGroups)
    {
        // Fill lowest → highest so a group listed under several roles
        // ends up mapped to the most privileged one.
        $map = [];
        foreach (
            [
                Authorization::ROLE_VIEWER => $viewerGroups,
                Authorization::ROLE_CAREGIVER => $caregiverGroups,
                Authorization::ROLE_ADMIN => $adminGroups,
            ] as $role => $groups
        ) {
            foreach ($groups as $group) {
                $map[mb_strtolower($group)] = $role;
            }
        }
        $this->roleByGroup = $map;
    }

    /**
     * Split a groups header (or a configured group list) into names.
     *
     * @return list<string>
     */
    public static function parseGroups(string $raw): array
    {
        $parts = preg_split('/[|,]/', $raw);
        if ($parts === false) {
            return [];
        }

        $out = [];
        foreach ($parts as $part) {
            $name = trim($part);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Highest role any of the user's groups maps to, or null when none
     * of them is configured.
     *
     * @param list<string> $groups
     */
    public function roleFor(array $groups): ?string
    {
        $ranks = [
            Authorization::ROLE_VIEWER => 1,
            Authorization::ROLE_CAREGIVER => 2,
            Authorization::ROLE_ADMIN => 3,
        ];

        $best = null;
        foreach ($groups as $group) {
            $role = $this->roleByGroup[mb_strtolower($group)] ?? null;
            if ($role !== null && ($best === null || $ranks[$role] > $ranks[$best])) {
                $best = $role;
            }
        }

        return $best;
    }
}
