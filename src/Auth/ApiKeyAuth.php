<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Repository\UserRepositoryInterface;

/**
 * Bearer-token authentication for API requests.
 *
 * The raw key is shown to the user once at generation time; the DB
 * only stores the SHA-256 hash (same pattern as the remember-me token
 * in {@see AuthService}). A read-only DB leak can't hand out live
 * tokens, and a stolen plaintext key can be rotated by clearing
 * hc_user.api_key_hash.
 *
 * Result codes follow the same convention as {@see AuthService}:
 *   - `ok` → authenticated; caller has user info + role
 *   - `missing_auth`     → no Authorization header (→ 401)
 *   - `malformed_auth`   → present but not `Bearer <value>` (→ 401)
 *   - `invalid_key`      → hash didn't match any user (→ 401)
 *   - `account_disabled` → user exists but `enabled != 'Y'` (→ 403)
 */
final class ApiKeyAuth
{
    /**
     * A minted key is 32 random bytes, hex-encoded → 64 chars.
     */
    public const KEY_BYTES = 32;

    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /**
     * Authenticate an inbound Authorization header.
     *
     * @param string|null $authorizationHeader Full value, e.g. "Bearer abc123..."
     */
    public function authenticate(?string $authorizationHeader): AuthResult
    {
        if ($authorizationHeader === null || $authorizationHeader === '') {
            return AuthResult::fail('missing_auth');
        }

        $token = self::extractBearer($authorizationHeader);
        if ($token === null) {
            return AuthResult::fail('malformed_auth');
        }

        $user = $this->users->findByApiKeyHash(self::hashKey($token));
        if ($user === null) {
            return AuthResult::fail('invalid_key');
        }
        if ($user['enabled'] !== 'Y') {
            return AuthResult::fail('account_disabled');
        }

        return AuthResult::ok($user);
    }

    /**
     * Map an {@see AuthResult} reason to the HTTP status an API endpoint
     * should emit. Convenience so controllers don't duplicate the table.
     */
    public static function httpStatusFor(AuthResult $result): int
    {
        if ($result->success) {
            return 200;
        }

        return match ($result->reason) {
            'account_disabled' => 403,
            default => 401,
        };
    }

    /**
     * Parse "Bearer <token>". RFC 6750 §2.1 makes "Bearer" case-sensitive
     * in the wild, but many clients send "bearer" lowercase -- accept
     * either. Reject multiple spaces or trailing garbage.
     */
    private static function extractBearer(string $header): ?string
    {
        if (preg_match('/^Bearer\s+(\S+)\s*$/i', trim($header), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    public static function hashKey(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * Generate a fresh random bearer token. Returns the raw value; the
     * caller is responsible for storing only {@see hashKey()} of it.
     */
    public static function generateRawKey(): string
    {
        return bin2hex(random_bytes(self::KEY_BYTES));
    }
}
