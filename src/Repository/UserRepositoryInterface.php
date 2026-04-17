<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Read/write contract for hc_user as consumed by the auth layer.
 *
 * @phpstan-type UserRecord array{
 *     login:string,
 *     passwd:string,
 *     email:?string,
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
 *     totp_recovery_codes:?string,
 *     email_notifications:string,
 *     notification_channels:string,
 *     last_login_ip:?string,
 *     digest_enabled:string
 * }
 */
interface UserRepositoryInterface
{
    /**
     * @return UserRecord|null
     */
    public function findByLogin(string $login): ?array;

    /**
     * Case-insensitive email lookup. Used by the forgot-password
     * flow so a user can enter either a login or an address.
     * Returns null when no row matches — the caller renders the
     * same "check your email" message either way so the lookup
     * doesn't leak existence.
     *
     * @return UserRecord|null
     */
    public function findByEmail(string $email): ?array;

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

    /**
     * Replace the user's notification-channel preference. Pass an
     * empty list to clear and fall back to the system default.
     *
     * @param list<string> $channelNames
     */
    public function updateNotificationChannels(string $login, array $channelNames): bool;

    /**
     * Replace the user's stored email address. Pass null (or empty
     * trimmed string) to clear. Caller is responsible for validating
     * the address before calling; the repo writes what it's given.
     */
    public function updateEmail(string $login, ?string $email): bool;

    /**
     * Toggle the reminder-email opt-in flag.
     */
    public function updateEmailNotifications(string $login, bool $on): bool;

    /**
     * Return the email addresses of every user opted in to reminder
     * email AND currently enabled. Used by the reminder cron to
     * dispatch one NotificationMessage per recipient.
     *
     * @return list<string>
     */
    public function getEmailSubscribers(): array;

    /**
     * Record the IP this user just successfully logged in from.
     * Callers read the previous value first with
     * `findByLogin()['last_login_ip']` so a new-IP email can fire.
     */
    public function updateLastLoginIp(string $login, ?string $ip): bool;

    /**
     * Get the expiry of a specific remember token from the multi-device table.
     * Returns null if not found.
     */
    public function getRememberTokenExpiry(string $tokenHash): ?string;

    /**
     * Delete a specific remember token by its hash (single-device logout).
     */
    public function deleteRememberTokenByHash(string $tokenHash): bool;

    /**
     * Toggle the weekly-digest opt-in flag (HC-107).
     */
    public function updateDigestEnabled(string $login, bool $on): bool;

    /**
     * Return (login, email) tuples for every user opted in to the
     * weekly adherence digest AND currently enabled. The digest CLI
     * needs the login for body personalisation, not just the address.
     *
     * @return list<array{login:string,email:string}>
     */
    public function getDigestSubscribers(): array;
}
