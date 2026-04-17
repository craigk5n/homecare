<?php

declare(strict_types=1);

namespace HomeCare\Auth;

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
 * No auto-provisioning: if the header value doesn't match an existing,
 * enabled hc_user login, authentication fails with a 401.
 */
final class ReverseProxyAuth
{
    public function __construct(
        private readonly ReverseProxyConfig $config,
        private readonly UserRepository $userRepo,
    ) {
    }

    /**
     * Attempt to authenticate from the proxy header.
     *
     * @param array<string, string> $serverVars  Typically $_SERVER
     *
     * @return AuthResult  success + user on match; failure otherwise
     */
    public function authenticate(array $serverVars): AuthResult
    {
        $headerName = $this->config->getHeader();

        // PHP converts HTTP headers to uppercase with HTTP_ prefix and
        // dashes become underscores: X-Forwarded-User → HTTP_X_FORWARDED_USER
        $phpKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));

        $login = isset($serverVars[$phpKey]) ? trim((string) $serverVars[$phpKey]) : '';

        if ($login === '') {
            return AuthResult::fail('reverse_proxy_header_missing');
        }

        $user = $this->userRepo->findByLogin($login);

        if ($user === null) {
            return AuthResult::fail('reverse_proxy_user_not_found');
        }

        if ($user['enabled'] !== 'Y') {
            return AuthResult::fail('account_disabled');
        }

        return AuthResult::ok($user);
    }
}
