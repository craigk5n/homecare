<?php

declare(strict_types=1);

namespace HomeCare\Http;

/**
 * OWASP-aligned session-cookie params for `session_set_cookie_params()`.
 *
 * Pure function of the request environment: `$_SERVER['HTTPS']`
 * decides the `secure` flag, nothing else. Extracted into a class
 * purely for testability -- the whole thing is one array literal.
 *
 * Defaults per OWASP Session Management Cheat Sheet:
 *   - HttpOnly=true   — JS can't read the cookie
 *   - Secure=true     — only over HTTPS (skipped on plain HTTP so
 *                       local development still works)
 *   - SameSite=Lax    — blocks CSRF on cross-site POSTs but
 *                       preserves top-level navigation
 *   - Path=/          — covers the whole app
 *   - lifetime=0      — session cookie; browser discards on close
 */
final class SessionCookieParams
{
    /**
     * @param array<string,mixed> $server usually `$_SERVER`
     *
     * @return array{lifetime:int,path:string,secure:bool,httponly:bool,samesite:string}
     */
    public static function forRequest(array $server): array
    {
        return [
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => self::isHttps($server),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function isHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        // Reverse-proxy case: Apache behind an HTTPS terminator forwards
        // the original scheme via `X-Forwarded-Proto`. We trust the
        // header because the operator controls both hops.
        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? '';

        return is_string($proto) && strtolower($proto) === 'https';
    }
}
