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
     * Returned when password authentication succeeded but TOTP 2FA is
     * enabled for the account and the login flow must prompt for a
     * code before marking the session authenticated.
     */
    public const REASON_REQUIRES_TOTP = 'requires_totp';

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
        public readonly bool $usedRecoveryCode = false,
        /**
         * True when THIS attempt is the one that tripped the
         * failed-attempts lockout (i.e. the Nth failure that
         * applied `locked_until`). Callers use it to fire a
         * one-shot "your account was just locked" security
         * email without re-firing on every subsequent attempt.
         */
        public readonly bool $justLockedOut = false,
    ) {}

    /**
     * @param UserRecord $user
     */
    public static function ok(
        array $user,
        ?string $rememberToken = null,
        ?int $rememberExpires = null,
        bool $usedRecoveryCode = false,
    ): self {
        return new self(true, null, $user, $rememberToken, $rememberExpires, $usedRecoveryCode);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * Password matched but 2FA is required before the session is
     * considered authenticated. Caller stashes `user.login` in a
     * `pending_login` session slot and renders the TOTP prompt.
     *
     * @param UserRecord $user
     */
    public static function requiresTotp(array $user): self
    {
        return new self(false, self::REASON_REQUIRES_TOTP, $user);
    }

    public function isTotpRequired(): bool
    {
        return $this->reason === self::REASON_REQUIRES_TOTP;
    }
}
