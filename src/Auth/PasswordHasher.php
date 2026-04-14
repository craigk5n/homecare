<?php

declare(strict_types=1);

namespace HomeCare\Auth;

/**
 * Thin wrapper around PHP's native `password_*` family.
 *
 * Exists so `AuthService` can be unit-tested without touching the cost
 * parameters of bcrypt (the default) and so rehash-on-verify becomes an
 * explicit part of the API rather than a side effect hidden in the
 * middle of a login function.
 *
 * Uses `PASSWORD_DEFAULT` so we follow PHP's migration path
 * (currently bcrypt; Argon2id in a future runtime) without re-hashing
 * already-stored passwords unnecessarily.
 */
final class PasswordHasher
{
    public function hash(#[\SensitiveParameter] string $plain): string
    {
        // password_hash returns a string on PHP 8+; no error sentinel to guard.
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public function verify(#[\SensitiveParameter] string $plain, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    /**
     * True when the stored hash should be upgraded to the current
     * default algorithm -- e.g. existing bcrypt hashes when the PHP
     * runtime starts defaulting to Argon2id.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
