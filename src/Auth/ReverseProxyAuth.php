<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Audit\AuditLogger;
use HomeCare\Config\ReverseProxyConfig;
use HomeCare\Repository\UserRepository;

/**
 * Authenticates requests via a trusted reverse-proxy header (HC-143).
 *
 * When `auth_mode = 'reverse_proxy'` in hc_config, the proxy (Authelia,
 * Authentik, Caddy, Traefik, etc.) sets a header like `X-Forwarded-User`
 * with the authenticated username. This service reads that header and
 * resolves the user through UserRepository.
 *
 * By default there is no auto-provisioning: if the header value doesn't
 * match an existing, enabled hc_user login, authentication fails with a
 * 401. HC-243 adds two opt-in behaviours driven by a groups header:
 *
 *   - auto-provision: an unknown (but well-formed) login is created as
 *     an enabled user with no usable password, its role taken from the
 *     group mapping (highest match wins) or the configured default;
 *   - role sync: an existing user's role is re-derived from the groups
 *     on every request.
 *
 * Disabled users are always refused; nothing here ever re-enables one.
 */
final class ReverseProxyAuth
{
    /**
     * Logins we're willing to create: ASCII letters, digits and
     * `. _ @ + -`, starting with a letter or digit, at most 25 chars
     * (the width of hc_user.login). Covers plain usernames and
     * email-style logins without admitting quotes, spaces or markup.
     */
    private const PROVISION_LOGIN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._@+-]{0,24}$/';

    /** Width of hc_user.firstname / lastname. */
    private const NAME_MAX_LENGTH = 25;

    /** Width of hc_user.email. */
    private const EMAIL_MAX_LENGTH = 75;

    public function __construct(
        private readonly ReverseProxyConfig $config,
        private readonly UserRepository $userRepo,
        private readonly ?AuditLogger $audit = null,
    ) {}

    /**
     * Attempt to authenticate from the proxy header.
     *
     * @param array<string, string> $serverVars  Typically $_SERVER
     *
     * @return AuthResult  success + user on match; failure otherwise
     */
    public function authenticate(array $serverVars): AuthResult
    {
        $login = self::headerValue($serverVars, $this->config->getHeader());

        if ($login === '') {
            return AuthResult::fail('reverse_proxy_header_missing');
        }

        $user = $this->userRepo->findByLogin($login);

        if ($user === null) {
            if (!$this->config->isAutoProvision()) {
                return AuthResult::fail('reverse_proxy_user_not_found');
            }

            return $this->provision($login, $serverVars);
        }

        if ($user['enabled'] !== 'Y') {
            return AuthResult::fail('account_disabled');
        }

        if ($this->config->isSyncRole()) {
            $role = $this->roleFromGroups($serverVars);
            if ($role === null) {
                return AuthResult::fail('reverse_proxy_no_matching_group');
            }
            if ($role !== $user['role']) {
                $this->userRepo->updateRole($user['login'], $role);
                $this->audit?->log('user.role_synced', 'user', null, [
                    'login' => $user['login'],
                    'old_role' => $user['role'],
                    'new_role' => $role,
                    'groups' => $this->groups($serverVars),
                ]);
                $user['role'] = $role;
            }
        }

        return AuthResult::ok($user);
    }

    /**
     * Create the hc_user row for a first-time proxy login.
     *
     * @param array<string, string> $serverVars
     */
    private function provision(string $login, array $serverVars): AuthResult
    {
        if (preg_match(self::PROVISION_LOGIN_PATTERN, $login) !== 1) {
            return AuthResult::fail('reverse_proxy_invalid_login');
        }

        $role = $this->roleFromGroups($serverVars);
        if ($role === null) {
            return AuthResult::fail('reverse_proxy_no_matching_group');
        }

        [$firstname, $lastname] = $this->nameFromHeader($serverVars);
        $email = $this->emailFromHeader($serverVars);

        try {
            $created = $this->userRepo->createProxyUser($login, $role, $firstname, $lastname, $email);
        } catch (\Throwable $e) {
            // Most likely a concurrent first request won the INSERT race;
            // the re-read below picks up its row.
            error_log('ReverseProxyAuth: provisioning failed: ' . $e->getMessage());
            $created = false;
        }

        $user = $this->userRepo->findByLogin($login);
        if ($user === null) {
            return AuthResult::fail('reverse_proxy_provision_failed');
        }
        if ($user['enabled'] !== 'Y') {
            return AuthResult::fail('account_disabled');
        }

        if ($created) {
            $this->audit?->log('user.auto_provisioned', 'user', null, [
                'login' => $login,
                'role' => $role,
                'groups' => $this->groups($serverVars),
            ]);
        }

        return AuthResult::ok($user);
    }

    /**
     * Role from the groups header, falling back to the configured
     * default. Null means "refuse" (default role = none, no match).
     *
     * @param array<string, string> $serverVars
     */
    private function roleFromGroups(array $serverVars): ?string
    {
        $role = $this->config->getGroupMapper()->roleFor($this->groups($serverVars));
        if ($role !== null) {
            return $role;
        }

        $default = $this->config->getDefaultRole();

        return $default === ReverseProxyConfig::DEFAULT_ROLE_REFUSE ? null : $default;
    }

    /**
     * @param array<string, string> $serverVars
     *
     * @return list<string>
     */
    private function groups(array $serverVars): array
    {
        return ProxyGroupMapper::parseGroups(
            self::headerValue($serverVars, $this->config->getGroupsHeader()),
        );
    }

    /**
     * Split a display name on the first space: "Jane van der Berg" →
     * ["Jane", "van der Berg"]. Each part is clipped to the column width.
     *
     * @param array<string, string> $serverVars
     *
     * @return array{?string, ?string}
     */
    private function nameFromHeader(array $serverVars): array
    {
        $header = $this->config->getNameHeader();
        if ($header === '') {
            return [null, null];
        }

        $name = (string) preg_replace('/[[:cntrl:]]/', '', self::headerValue($serverVars, $header));
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return [null, null];
        }

        $parts = explode(' ', $name, 2);
        $first = mb_substr($parts[0], 0, self::NAME_MAX_LENGTH);
        $last = isset($parts[1]) ? mb_substr($parts[1], 0, self::NAME_MAX_LENGTH) : null;

        return [$first, $last === '' ? null : $last];
    }

    /**
     * @param array<string, string> $serverVars
     */
    private function emailFromHeader(array $serverVars): ?string
    {
        $header = $this->config->getEmailHeader();
        if ($header === '') {
            return null;
        }

        $email = self::headerValue($serverVars, $header);
        if (
            $email === ''
            || strlen($email) > self::EMAIL_MAX_LENGTH
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return null;
        }

        return $email;
    }

    /**
     * PHP converts HTTP headers to uppercase with HTTP_ prefix and
     * dashes become underscores: X-Forwarded-User → HTTP_X_FORWARDED_USER
     *
     * @param array<string, string> $serverVars
     */
    private static function headerValue(array $serverVars, string $headerName): string
    {
        $phpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));

        return isset($serverVars[$phpKey]) ? trim((string) $serverVars[$phpKey]) : '';
    }
}
