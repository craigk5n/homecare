<?php

declare(strict_types=1);

namespace HomeCare\Auth;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Notification\NotificationChannel;
use HomeCare\Notification\NotificationMessage;
use HomeCare\Repository\UserRepositoryInterface;

/**
 * Self-service password reset over a single-use emailed link.
 *
 * Three phases:
 *   1. {@see initiate()}  — user submits forgot-password form; we
 *                           mint a random 32-byte token, store its
 *                           SHA-256 hash, and email the raw token
 *                           back to them. Silent no-ops on unknown
 *                           logins / disabled accounts / missing
 *                           email so the response doesn't leak
 *                           account existence.
 *   2. {@see validate()}  — on GET of reset_password.php?token=...
 *                           we confirm the token exists, is unused,
 *                           and is within TTL. Returns the login
 *                           on success, null on any failure.
 *   3. {@see complete()}  — on POST we mark the token used FIRST
 *                           (even if the password write later fails
 *                           the token can't be replayed), then
 *                           update hc_user.passwd, clear
 *                           remember-me / failed_attempts /
 *                           locked_until.
 *
 * Rate limit: at most {@see MAX_REQUESTS_PER_HOUR} initiations per
 * login per hour. Over the limit, we silently skip the mint + send
 * (still returns void; no signal to the caller, same as unknown
 * user) and audit a `password_reset.rate_limited` row.
 */
final class PasswordResetService
{
    public const TTL_MINUTES = 60;
    public const MAX_REQUESTS_PER_HOUR = 3;

    /** @var callable():int */
    private readonly mixed $clock;

    /** @var callable():string */
    private readonly mixed $tokenFactory;

    /** @var callable(string,string):void */
    private readonly mixed $audit;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasher $hasher,
        private readonly NotificationChannel $emailChannel,
        ?callable $clock = null,
        ?callable $tokenFactory = null,
        ?callable $audit = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->tokenFactory = $tokenFactory
            ?? static fn(): string => bin2hex(random_bytes(32));
        $this->audit = $audit ?? static fn(string $action, string $details = ''): null => null;
    }

    /**
     * Accept a login or email, mint a token, send the email.
     *
     * Always returns void — the caller ALWAYS renders the same
     * "check your email" message regardless of whether an email
     * actually fired, so account existence never leaks.
     */
    public function initiate(
        string $loginOrEmail,
        string $baseUrl,
    ): void {
        $loginOrEmail = trim($loginOrEmail);
        if ($loginOrEmail === '') {
            return;
        }

        $user = str_contains($loginOrEmail, '@')
            ? $this->users->findByEmail($loginOrEmail)
            : $this->users->findByLogin($loginOrEmail);

        if ($user === null || $user['enabled'] !== 'Y') {
            $this->auditLog('password_reset.requested_unknown', "login={$loginOrEmail}");
            return;
        }

        $email = $user['email'];
        if ($email === null || $email === '') {
            // Account has no email on file → no way to deliver.
            $this->auditLog('password_reset.requested_no_email', "login={$user['login']}");
            return;
        }

        // Rate-limit: count non-stale tokens in the last hour.
        $recentCount = $this->countRecentRequests($user['login']);
        if ($recentCount >= self::MAX_REQUESTS_PER_HOUR) {
            $this->auditLog('password_reset.rate_limited', "login={$user['login']}");
            return;
        }

        $rawToken = $this->mintToken();
        $hash = self::hashToken($rawToken);
        $now = $this->now();
        $createdAt = date('Y-m-d H:i:s', $now);
        $expiresAt = date('Y-m-d H:i:s', $now + self::TTL_MINUTES * 60);

        $this->db->execute(
            'INSERT INTO hc_password_reset_tokens
                (token_hash, user_login, created_at, expires_at)
             VALUES (?, ?, ?, ?)',
            [$hash, $user['login'], $createdAt, $expiresAt],
        );

        $resetUrl = rtrim($baseUrl, '/') . '/reset_password.php?token=' . urlencode($rawToken);
        $this->emailChannel->send(new NotificationMessage(
            title: 'HomeCare password reset',
            body: $this->renderEmailBody($user['login'], $resetUrl),
            priority: NotificationMessage::PRIORITY_HIGH,
            recipient: $email,
        ));

        $this->auditLog('password_reset.requested', "login={$user['login']}");
    }

    /**
     * Look up a token and return the login if it is usable.
     * Returns null on unknown / used / expired tokens.
     */
    public function validate(
        #[\SensitiveParameter]
        string $rawToken,
    ): ?string {
        if ($rawToken === '') {
            return null;
        }

        $rows = $this->db->query(
            'SELECT user_login, used_at, expires_at
             FROM hc_password_reset_tokens WHERE token_hash = ?',
            [self::hashToken($rawToken)],
        );
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];

        if ($row['used_at'] !== null) {
            return null;
        }
        $expires = $row['expires_at'];
        if (!is_string($expires) || strtotime($expires) === false
            || strtotime($expires) < $this->now()
        ) {
            return null;
        }

        return (string) $row['user_login'];
    }

    /**
     * Consume the token and rotate the password.
     *
     * Marks the token used BEFORE hashing / writing the new
     * password so a mid-flight crash can't leave the token
     * replayable. Subsequent calls with the same token fail
     * validate() outright.
     *
     * Returns false on any pre-check failure (unknown, used,
     * expired) OR on a downstream DB error. True on success.
     */
    public function complete(
        #[\SensitiveParameter]
        string $rawToken,
        #[\SensitiveParameter]
        string $newPassword,
    ): bool {
        $login = $this->validate($rawToken);
        if ($login === null) {
            $this->auditLog('password_reset.failed_invalid_token', 'token_hash='
                . substr(self::hashToken($rawToken), 0, 8));
            return false;
        }

        $now = $this->now();
        // Consume-before-write: mark used first.
        $this->db->execute(
            'UPDATE hc_password_reset_tokens SET used_at = ? WHERE token_hash = ?',
            [date('Y-m-d H:i:s', $now), self::hashToken($rawToken)],
        );

        $hashed = $this->hasher->hash($newPassword);
        $this->users->updatePasswordHash($login, $hashed);
        $this->users->resetLoginAttempts($login);
        // Password rotation invalidates every existing remember-me
        // cookie — a leaked cookie can't outlive a known reset.
        $this->users->updateRememberToken($login, null, null);

        $this->auditLog('password_reset.completed', "login={$login}");

        return true;
    }

    /**
     * SHA-256 hex of the raw token as stored in the DB.
     */
    public static function hashToken(#[\SensitiveParameter] string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private function countRecentRequests(string $login): int
    {
        $oneHourAgo = date('Y-m-d H:i:s', $this->now() - 3600);
        $rows = $this->db->query(
            'SELECT COUNT(*) AS n FROM hc_password_reset_tokens
             WHERE user_login = ? AND created_at >= ?',
            [$login, $oneHourAgo],
        );
        if ($rows === []) {
            return 0;
        }

        return (int) ($rows[0]['n'] ?? 0);
    }

    private function renderEmailBody(string $login, string $resetUrl): string
    {
        $ttl = self::TTL_MINUTES;

        return "Hi {$login},\n\n"
            . "Someone (hopefully you) asked to reset the password for this HomeCare account.\n\n"
            . "Open this link within {$ttl} minutes to choose a new password:\n\n"
            . "    {$resetUrl}\n\n"
            . "The link is single-use — clicking it once consumes it.\n\n"
            . "If you didn't request this, ignore the email. Your password is unchanged.\n";
    }

    private function now(): int
    {
        /** @var callable():int $fn */
        $fn = $this->clock;

        return ($fn)();
    }

    private function mintToken(): string
    {
        /** @var callable():string $fn */
        $fn = $this->tokenFactory;

        return ($fn)();
    }

    private function auditLog(string $action, string $details = ''): void
    {
        /** @var callable(string,string):void $fn */
        $fn = $this->audit;
        ($fn)($action, $details);
    }
}
