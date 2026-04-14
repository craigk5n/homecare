<?php

declare(strict_types=1);

namespace HomeCare\Api;

use HomeCare\Auth\ApiKeyAuth;
use HomeCare\Auth\AuthResult;
use HomeCare\Repository\UserRepositoryInterface;

/**
 * Thin glue between the PHP request environment and {@see ApiKeyAuth}.
 *
 * Extracted so tests can call `authenticate()` with a synthetic
 * $_SERVER array instead of manipulating superglobals.
 */
final class ApiAuth
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /**
     * @param array<string,mixed> $server Typically `$_SERVER`.
     */
    public function authenticate(array $server): AuthResult
    {
        return (new ApiKeyAuth($this->users))->authenticate(self::authorizationHeader($server));
    }

    /**
     * Pull the Authorization header out of $_SERVER first, then fall back
     * to getallheaders() / apache_request_headers().
     *
     * mod_php strips the Authorization header from $_SERVER by default
     * (legacy CGI security rationale); it can be re-injected via an
     * .htaccess SetEnvIf / mod_rewrite dance, but that only works if
     * AllowOverride permits it. getallheaders() still sees the header in
     * mod_php regardless, so the fallback is the pragmatic approach.
     *
     * @param array<string,mixed> $server
     */
    public static function authorizationHeader(array $server): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($server[$key]) && is_string($server[$key]) && $server[$key] !== '') {
                return $server[$key];
            }
        }

        if (function_exists('getallheaders')) {
            // getallheaders() returns array<string,string> when the SAPI
            // supports it; no runtime check needed.
            foreach (getallheaders() as $name => $value) {
                if (is_string($name) && strcasecmp($name, 'Authorization') === 0) {
                    return is_string($value) && $value !== '' ? $value : null;
                }
            }
        }

        return null;
    }
}
