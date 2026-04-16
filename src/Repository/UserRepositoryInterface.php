<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Read/write contract for hc_user as consumed by the auth layer.
 *
 * @phpstan-type UserRecord array{
 *     login:string,
 *     passwd:string,
 *     is_admin:string,
 *     role:string,
 *     enabled:string,
 *     remember_token:?string,
 *     remember_token_expires:?string,
 *     failed_attempts:int,
 *     locked_until:?string,
 *     api_key_hash:?string,
 *     totp_secret:?string,
 *     totp_enabled:string,
 *     totp_recovery_codes:?string
 * }
 */
interface UserRepositoryInterface
{
    /**
     * @return UserRecord|null
     */
    public function findByLogin(string $login): ?array;

    /**
     * Look up a user by the SHA-256 hash of a remember-me token.
     *
     * @return UserRecord|null
     */
    public function findByRememberTokenHash(string $hash): ?array;

    /**
     * Look up a user by the SHA-256 hash of an API bearer token.
     *
     * @return UserRecord|null
     */
    public function findByApiKeyHash(string $hash): ?array;

    /**
     * Replace (or clear, with null) the stored API key hash.
     */
    public function updateApiKeyHash(string $login, ?string $hash): bool;

    /**
     * Replace the stored hash + expiry. Passing `null` for both clears
     * the remember-me state (used on logout).
     */
    public function updateRememberToken(string $login, ?string $hash, ?string $expiresAt): bool;

    /**
     * Upgrade the password hash (for rehash-on-verify).
     */
    public function updatePasswordHash(string $login, string $hash): bool;

    /**
     * Record a successful login's timestamp on the user row.
     */
    public function touchLastLogin(string $login, int $unixTimestamp): bool;

    /**
     * Atomically bump failed_attempts by 1 and return the new value.
     */
    public function incrementFailedAttempts(string $login): int;

    /**
     * Set an absolute DATETIME when the account becomes usable again.
     */
    public function applyLockout(string $login, string $lockedUntil): bool;

    /**
     * Zero out both failed_attempts and locked_until. Called on every
     * successful login.
     */
    public function resetLoginAttempts(string $login): bool;

    /**
     * Persist a new Base32 TOTP seed (without flipping `totp_enabled`).
     * Caller verifies a first code before calling {@see enableTotp()}.
     */
    public function setTotpSecret(string $login, string $base32Secret): bool;

    /**
     * Flip `totp_enabled` to 'Y' and (re)write the recovery-code hash list.
     *
     * @param list<string> $recoveryCodeHashes SHA-256 hex strings
     */
    public function enableTotp(string $login, array $recoveryCodeHashes): bool;

    /**
     * Wipe the TOTP seed, disable flag, and recovery codes in one shot.
     * Called when the user disables 2FA (or an admin clears it).
     */
    public function disableTotp(string $login): bool;

    /**
     * Replace the stored recovery-code hash list. Used when a recovery
     * code is consumed (the used one gets popped).
     *
     * @param list<string> $recoveryCodeHashes
     */
    public function setRecoveryCodes(string $login, array $recoveryCodeHashes): bool;
}
