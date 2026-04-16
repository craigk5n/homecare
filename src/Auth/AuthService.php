<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Repository\UserRepositoryInterface;

/**
 * @phpstan-import-type UserRecord from UserRepositoryInterface
 *
 * Native HomeCare authentication. Replaces the WebCalendar-derived
 * `includes/user.php` + `includes/validate.php` flow with a testable,
 * side-effect-free service.
 *
 * Responsibilities:
 * - Verify a login/password pair against hc_user.passwd.
 * - Issue a remember-me token (SHA-256 hash stored; raw value returned
 *   to the caller for cookie delivery).
 * - Validate an inbound remember-me token.
 * - Clear remember-me state on logout.
 *
 * Session storage + cookie I/O live in the wiring (login.php /
 * validate.php). Keeping them out of this class is what makes the
 * unit tests possible.
 */
final class AuthService
{
    public const REMEMBER_LIFETIME_SECONDS = 365 * 86400;
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;

    /** @var callable():int */
    private readonly mixed $clock;

    /** @var callable():string */
    private readonly mixed $tokenFactory;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasher $hasher,
        ?callable $clock = null,
        ?callable $tokenFactory = null,
        private readonly ?TotpService $totp = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->tokenFactory = $tokenFactory
            ?? static fn (): string => bin2hex(random_bytes(32));
    }

    /**
     * Verify login + password. On success, optionally mint a remember-me
     * token and persist the hash. Always returns an {@see AuthResult}; on
     * failure `reason` is a short machine-readable code.
     */
    public function attemptLogin(
        string $login,
        #[\SensitiveParameter] string $password,
        bool $remember = false,
    ): AuthResult {
        $user = $this->users->findByLogin($login);
        if ($user === null) {
            return AuthResult::fail('invalid_credentials');
        }

        $now = $this->now();

        // Lockout check happens BEFORE the disabled check and BEFORE the
        // password check so we never leak "locked" info via timing.
        if (self::isLockedAt($user['locked_until'], $now)) {
            return AuthResult::fail('account_locked');
        }

        if ($user['enabled'] !== 'Y') {
            return AuthResult::fail('account_disabled');
        }

        if (!$this->hasher->verify($password, $user['passwd'])) {
            $attempts = $this->users->incrementFailedAttempts($user['login']);
            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $this->users->applyLockout(
                    $user['login'],
                    date('Y-m-d H:i:s', $now + self::LOCKOUT_MINUTES * 60)
                );
            }

            return AuthResult::fail('invalid_credentials');
        }

        // Successful password: clear the lockout counter.
        $this->users->resetLoginAttempts($user['login']);

        // Rehash if the stored hash uses an older algorithm. Never an
        // error if the update fails; the user still authenticates.
        if ($this->hasher->needsRehash($user['passwd'])) {
            $newHash = $this->hasher->hash($password);
            $this->users->updatePasswordHash($user['login'], $newHash);
            $user['passwd'] = $newHash;
        }

        // 2FA gate: if the account has TOTP enabled, the caller must
        // run a second step (see {@see verifyTotp()}). We deliberately
        // do NOT touch last_login or mint a remember-me token here --
        // those are side effects of a *completed* authentication.
        if ($user['totp_enabled'] === 'Y' && $user['totp_secret'] !== null) {
            return AuthResult::requiresTotp($user);
        }

        return $this->finaliseLogin($user, $remember, $now);
    }

    /**
     * Second step of the login flow when TOTP is enabled.
     *
     * Accepts either a 6-digit TOTP code OR one of the user's recovery
     * codes. Recovery-code consumption is atomic: the used code is
     * popped from the stored list before the success branch returns,
     * so replay is impossible even on race.
     *
     * Caller must have stashed the login in a `pending_login` session
     * slot after {@see attemptLogin()} returned `requires_totp`; this
     * method does not trust client-supplied user context.
     */
    public function verifyTotp(
        string $login,
        #[\SensitiveParameter] string $code,
        bool $remember = false,
    ): AuthResult {
        if ($this->totp === null) {
            return AuthResult::fail('totp_unavailable');
        }

        $user = $this->users->findByLogin($login);
        if ($user === null || $user['totp_enabled'] !== 'Y' || $user['totp_secret'] === null) {
            return AuthResult::fail('invalid_credentials');
        }
        if ($user['enabled'] !== 'Y') {
            return AuthResult::fail('account_disabled');
        }

        $now = $this->now();
        if (self::isLockedAt($user['locked_until'], $now)) {
            return AuthResult::fail('account_locked');
        }

        if ($this->totp->verifyCode($user['totp_secret'], $code)) {
            return $this->finaliseLogin($user, $remember, $now);
        }

        // 6-digit TOTP didn't match — try recovery code.
        $stored = TotpService::decodeStoredRecoveryCodes($user['totp_recovery_codes']);
        if ($stored !== []) {
            $remaining = $this->totp->consumeRecoveryCode($code, $stored);
            if ($remaining !== null) {
                $this->users->setRecoveryCodes($user['login'], $remaining);

                return $this->finaliseLogin($user, $remember, $now, usedRecoveryCode: true);
            }
        }

        // Reuse the lockout counter so bruteforce against the TOTP
        // step is bounded by the same MAX_FAILED_ATTEMPTS ceiling as
        // the password step.
        $attempts = $this->users->incrementFailedAttempts($user['login']);
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->users->applyLockout(
                $user['login'],
                date('Y-m-d H:i:s', $now + self::LOCKOUT_MINUTES * 60)
            );
        }

        return AuthResult::fail('invalid_totp');
    }

    /**
     * Common tail of the successful-login path: touch last_login,
     * optionally mint a remember-me token, and return AuthResult::ok.
     *
     * @param UserRecord $user
     */
    private function finaliseLogin(
        array $user,
        bool $remember,
        int $now,
        bool $usedRecoveryCode = false,
    ): AuthResult {
        $this->users->resetLoginAttempts($user['login']);
        $this->users->touchLastLogin($user['login'], $now);

        if (!$remember) {
            return AuthResult::ok($user, usedRecoveryCode: $usedRecoveryCode);
        }

        $token = $this->newToken();
        $hash = self::hashToken($token);
        $expiresUnix = $now + self::REMEMBER_LIFETIME_SECONDS;
        $this->users->updateRememberToken(
            $user['login'],
            $hash,
            date('Y-m-d H:i:s', $expiresUnix)
        );

        return AuthResult::ok($user, $token, $expiresUnix, usedRecoveryCode: $usedRecoveryCode);
    }

    /**
     * Look up a user by a raw remember-me token. Rejects expired tokens.
     *
     * Returned `AuthResult.rememberToken` is null -- the caller already
     * has the token in a cookie; we just validate. If you want to rotate
     * the token on each hit, call {@see rotateRememberToken()} explicitly.
     */
    public function loginWithRememberToken(string $rawToken): AuthResult
    {
        if ($rawToken === '') {
            return AuthResult::fail('missing_token');
        }

        $user = $this->users->findByRememberTokenHash(self::hashToken($rawToken));
        if ($user === null) {
            return AuthResult::fail('invalid_token');
        }

        $now = $this->now();

        // A locked account can't be bypassed via the remember-me cookie.
        if (self::isLockedAt($user['locked_until'], $now)) {
            return AuthResult::fail('account_locked');
        }

        if ($user['enabled'] !== 'Y') {
            return AuthResult::fail('account_disabled');
        }

        $expires = $user['remember_token_expires'];
        if ($expires === null || strtotime($expires) === false
            || strtotime($expires) < $now) {
            // Clean up the dead token so a leaked DB doesn't keep being
            // a foothold.
            $this->users->updateRememberToken($user['login'], null, null);

            return AuthResult::fail('expired_token');
        }

        // HC-090: remember-me cannot skip TOTP. The cookie was minted
        // during a successful post-TOTP login, but the account may have
        // enabled 2FA afterward -- force a fresh TOTP prompt either way.
        if ($user['totp_enabled'] === 'Y' && $user['totp_secret'] !== null) {
            return AuthResult::requiresTotp($user);
        }

        return AuthResult::ok($user);
    }

    /**
     * Mint a fresh remember-me token for an already-authenticated user
     * and return the raw value to send to the browser.
     */
    public function issueRememberToken(string $login): AuthResult
    {
        $user = $this->users->findByLogin($login);
        if ($user === null) {
            return AuthResult::fail('unknown_user');
        }

        $token = $this->newToken();
        $expiresUnix = $this->now() + self::REMEMBER_LIFETIME_SECONDS;
        $this->users->updateRememberToken(
            $login,
            self::hashToken($token),
            date('Y-m-d H:i:s', $expiresUnix)
        );

        return AuthResult::ok($user, $token, $expiresUnix);
    }

    public function logout(string $login): void
    {
        $this->users->updateRememberToken($login, null, null);
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * True when the stored `locked_until` is in the future relative to $now.
     */
    private static function isLockedAt(?string $lockedUntil, int $now): bool
    {
        if ($lockedUntil === null) {
            return false;
        }
        $ts = strtotime($lockedUntil);

        return $ts !== false && $ts > $now;
    }

    private function now(): int
    {
        /** @var callable():int $clock */
        $clock = $this->clock;

        return ($clock)();
    }

    private function newToken(): string
    {
        /** @var callable():string $factory */
        $factory = $this->tokenFactory;

        return ($factory)();
    }
}
