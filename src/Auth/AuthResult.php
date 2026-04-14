<?php

declare(strict_types=1);

namespace HomeCare\Auth;

/**
 * Result of a login attempt.
 *
 * @phpstan-import-type UserRecord from \HomeCare\Repository\UserRepositoryInterface
 */
final class AuthResult
{
    /**
     * @param UserRecord|null $user       Hydrated hc_user row on success, null otherwise.
     * @param string|null     $rememberToken Raw (unhashed) token to send to the browser,
     *                                    or null when remember-me was not requested.
     * @param int|null        $rememberExpires Unix timestamp the cookie should expire at.
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $reason = null,
        public readonly ?array $user = null,
        public readonly ?string $rememberToken = null,
        public readonly ?int $rememberExpires = null,
    ) {
    }

    /**
     * @param UserRecord $user
     */
    public static function ok(
        array $user,
        ?string $rememberToken = null,
        ?int $rememberExpires = null,
    ): self {
        return new self(true, null, $user, $rememberToken, $rememberExpires);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
