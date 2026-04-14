<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Repository\UserRepositoryInterface;

/**
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

        $this->users->touchLastLogin($user['login'], $now);

        if ($remember) {
            $token = $this->newToken();
            $hash = self::hashToken($token);
            $expiresUnix = $now + self::REMEMBER_LIFETIME_SECONDS;
            $this->users->updateRememberToken(
                $user['login'],
                $hash,
                date('Y-m-d H:i:s', $expiresUnix)
            );

            return AuthResult::ok($user, $token, $expiresUnix);
        }

        return AuthResult::ok($user);
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
