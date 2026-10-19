<?php

declare(strict_types=1);

namespace HomeCare\Config;

use HomeCare\Auth\Authorization;
use HomeCare\Auth\ProxyGroupMapper;
use HomeCare\Database\DatabaseInterface;

/**
 * Typed reader/writer for the reverse-proxy authentication settings
 * stored in `hc_config` (HC-143).
 *
 * Core settings:
 *   - `auth_mode`             'native' (default) or 'reverse_proxy'
 *   - `reverse_proxy_header`  HTTP header name (default 'X-Forwarded-User')
 *
 * Auto-provisioning + group mapping (HC-243), all opt-in:
 *   - `reverse_proxy_auto_provision`  'Y' / 'N' (default 'N', fail-closed)
 *   - `reverse_proxy_groups_header`   default 'Remote-Groups'
 *   - `reverse_proxy_{admin,caregiver,viewer}_groups`
 *                                     comma-separated group names
 *   - `reverse_proxy_default_role`    'viewer' (default), 'caregiver', or
 *                                     'none' = refuse when no group matches
 *   - `reverse_proxy_sync_role`       'Y' / 'N' (default 'N')
 *   - `reverse_proxy_name_header`, `reverse_proxy_email_header`
 *                                     optional; empty = not used
 *
 * Defaults kick in when the rows are missing, so existing installs
 * work without running the migration first.
 */
final class ReverseProxyConfig
{
    public const AUTH_MODE_NATIVE = 'native';
    public const AUTH_MODE_REVERSE_PROXY = 'reverse_proxy';
    public const DEFAULT_HEADER = 'X-Forwarded-User';
    public const DEFAULT_GROUPS_HEADER = 'Remote-Groups';

    /** `reverse_proxy_default_role` value meaning "refuse login when no group matches". */
    public const DEFAULT_ROLE_REFUSE = 'none';

    /** Width of hc_config.value. */
    public const MAX_VALUE_LENGTH = 128;

    /** Roles a group list can be configured for. */
    private const GROUP_ROLES = [
        Authorization::ROLE_ADMIN,
        Authorization::ROLE_CAREGIVER,
        Authorization::ROLE_VIEWER,
    ];

    /** Admin is deliberately absent: it must come from an explicit group. */
    private const DEFAULT_ROLES = [
        Authorization::ROLE_VIEWER,
        Authorization::ROLE_CAREGIVER,
        self::DEFAULT_ROLE_REFUSE,
    ];

    public function __construct(private readonly DatabaseInterface $db) {}

    public function getAuthMode(): string
    {
        return $this->getSetting('auth_mode') ?? self::AUTH_MODE_NATIVE;
    }

    public function isReverseProxyMode(): bool
    {
        return $this->getAuthMode() === self::AUTH_MODE_REVERSE_PROXY;
    }

    public function getHeader(): string
    {
        return $this->getSetting('reverse_proxy_header') ?? self::DEFAULT_HEADER;
    }

    public function setAuthMode(string $mode): void
    {
        if (!in_array($mode, [self::AUTH_MODE_NATIVE, self::AUTH_MODE_REVERSE_PROXY], true)) {
            throw new \InvalidArgumentException("Invalid auth_mode: {$mode}");
        }
        $this->setSetting('auth_mode', $mode);
    }

    public function setHeader(string $header): void
    {
        $this->setSetting('reverse_proxy_header', $header);
    }

    public function isAutoProvision(): bool
    {
        return $this->getSetting('reverse_proxy_auto_provision') === 'Y';
    }

    public function setAutoProvision(bool $on): void
    {
        $this->setSetting('reverse_proxy_auto_provision', $on ? 'Y' : 'N');
    }

    public function getGroupsHeader(): string
    {
        $v = $this->getSetting('reverse_proxy_groups_header');

        return $v === null || $v === '' ? self::DEFAULT_GROUPS_HEADER : $v;
    }

    public function setGroupsHeader(string $header): void
    {
        $this->setSetting('reverse_proxy_groups_header', self::normaliseHeaderName($header));
    }

    /**
     * Configured group names for one role.
     *
     * @return list<string>
     */
    public function getGroupsForRole(string $role): array
    {
        self::assertGroupRole($role);

        return ProxyGroupMapper::parseGroups(
            $this->getSetting("reverse_proxy_{$role}_groups") ?? '',
        );
    }

    /**
     * Store the group list for one role. Accepts `,` or `|` separators
     * and stores the normalised comma-joined form.
     */
    public function setGroupsForRole(string $role, string $groups): void
    {
        self::assertGroupRole($role);
        $this->setSetting(
            "reverse_proxy_{$role}_groups",
            implode(',', ProxyGroupMapper::parseGroups($groups)),
        );
    }

    public function getGroupMapper(): ProxyGroupMapper
    {
        return new ProxyGroupMapper(
            $this->getGroupsForRole(Authorization::ROLE_ADMIN),
            $this->getGroupsForRole(Authorization::ROLE_CAREGIVER),
            $this->getGroupsForRole(Authorization::ROLE_VIEWER),
        );
    }

    /**
     * Role for users whose groups match nothing: 'viewer', 'caregiver',
     * or {@see DEFAULT_ROLE_REFUSE}. Unknown stored values fall back to
     * viewer rather than escalating.
     */
    public function getDefaultRole(): string
    {
        $v = $this->getSetting('reverse_proxy_default_role');

        return $v !== null && in_array($v, self::DEFAULT_ROLES, true) ? $v : Authorization::ROLE_VIEWER;
    }

    public function setDefaultRole(string $role): void
    {
        if (!in_array($role, self::DEFAULT_ROLES, true)) {
            throw new \InvalidArgumentException("Invalid default role: {$role}");
        }
        $this->setSetting('reverse_proxy_default_role', $role);
    }

    public function isSyncRole(): bool
    {
        return $this->getSetting('reverse_proxy_sync_role') === 'Y';
    }

    public function setSyncRole(bool $on): void
    {
        $this->setSetting('reverse_proxy_sync_role', $on ? 'Y' : 'N');
    }

    /** Header carrying the display name; '' = not used. */
    public function getNameHeader(): string
    {
        return $this->getSetting('reverse_proxy_name_header') ?? '';
    }

    public function setNameHeader(string $header): void
    {
        $this->setSetting('reverse_proxy_name_header', self::normaliseHeaderName($header));
    }

    /** Header carrying the email address; '' = not used. */
    public function getEmailHeader(): string
    {
        return $this->getSetting('reverse_proxy_email_header') ?? '';
    }

    public function setEmailHeader(string $header): void
    {
        $this->setSetting('reverse_proxy_email_header', self::normaliseHeaderName($header));
    }

    /**
     * @return array{
     *     auth_mode: string,
     *     header: string,
     *     auto_provision: bool,
     *     groups_header: string,
     *     admin_groups: string,
     *     caregiver_groups: string,
     *     viewer_groups: string,
     *     default_role: string,
     *     sync_role: bool,
     *     name_header: string,
     *     email_header: string
     * }
     */
    public function getAll(): array
    {
        return [
            'auth_mode' => $this->getAuthMode(),
            'header' => $this->getHeader(),
            'auto_provision' => $this->isAutoProvision(),
            'groups_header' => $this->getGroupsHeader(),
            'admin_groups' => implode(',', $this->getGroupsForRole(Authorization::ROLE_ADMIN)),
            'caregiver_groups' => implode(',', $this->getGroupsForRole(Authorization::ROLE_CAREGIVER)),
            'viewer_groups' => implode(',', $this->getGroupsForRole(Authorization::ROLE_VIEWER)),
            'default_role' => $this->getDefaultRole(),
            'sync_role' => $this->isSyncRole(),
            'name_header' => $this->getNameHeader(),
            'email_header' => $this->getEmailHeader(),
        ];
    }

    private static function assertGroupRole(string $role): void
    {
        if (!in_array($role, self::GROUP_ROLES, true)) {
            throw new \InvalidArgumentException("Invalid role for group mapping: {$role}");
        }
    }

    /**
     * Trim, then allow only RFC 7230 token characters so CR/LF or
     * spaces can't sneak in. Empty is allowed (= unset / default).
     */
    private static function normaliseHeaderName(string $header): string
    {
        $header = trim($header);
        if ($header !== '' && preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $header) !== 1) {
            throw new \InvalidArgumentException('Invalid header name');
        }

        return $header;
    }

    private function getSetting(string $key): ?string
    {
        $rows = $this->db->query(
            'SELECT value FROM hc_config WHERE setting = ?',
            [$key],
        );
        if ($rows === []) {
            return null;
        }
        $v = $rows[0]['value'] ?? null;

        return $v === null ? null : (string) $v;
    }

    private function setSetting(string $key, string $value): void
    {
        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new \InvalidArgumentException(
                "Value for {$key} exceeds " . self::MAX_VALUE_LENGTH . ' characters',
            );
        }
        $existing = $this->db->query(
            'SELECT setting FROM hc_config WHERE setting = ?',
            [$key],
        );
        if ($existing === []) {
            $this->db->execute(
                'INSERT INTO hc_config (setting, value) VALUES (?, ?)',
                [$key, $value],
            );
        } else {
            $this->db->execute(
                'UPDATE hc_config SET value = ? WHERE setting = ?',
                [$value, $key],
            );
        }
    }
}
