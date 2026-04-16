<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * hc_user data access for the auth layer.
 *
 * @phpstan-import-type UserRecord from UserRepositoryInterface
 */
final class UserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @return UserRecord|null
     */
    public function findByLogin(string $login): ?array
    {
        $rows = $this->db->query($this->selectColumns() . ' WHERE login = ?', [$login]);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function findByEmail(string $email): ?array
    {
        if ($email === '') {
            return null;
        }
        // Case-insensitive — email addresses are canonical lowercase.
        $rows = $this->db->query(
            $this->selectColumns() . ' WHERE LOWER(email) = LOWER(?) LIMIT 1',
            [$email]
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * @return UserRecord|null
     */
    public function findByRememberTokenHash(string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }

        $rows = $this->db->query(
            $this->selectColumns() . ' WHERE remember_token = ?',
            [$hash]
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function findByApiKeyHash(string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }

        $rows = $this->db->query(
            $this->selectColumns() . ' WHERE api_key_hash = ?',
            [$hash]
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function updateApiKeyHash(string $login, ?string $hash): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET api_key_hash = ? WHERE login = ?',
            [$hash, $login]
        );
    }

    public function updateRememberToken(string $login, ?string $hash, ?string $expiresAt): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET remember_token = ?, remember_token_expires = ? WHERE login = ?',
            [$hash, $expiresAt, $login]
        );
    }

    public function updatePasswordHash(string $login, string $hash): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET passwd = ? WHERE login = ?',
            [$hash, $login]
        );
    }

    public function touchLastLogin(string $login, int $unixTimestamp): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET last_login = ? WHERE login = ?',
            [$unixTimestamp, $login]
        );
    }

    public function incrementFailedAttempts(string $login): int
    {
        // Bump atomically so a racing parallel request can't undercount.
        $this->db->execute(
            'UPDATE hc_user SET failed_attempts = failed_attempts + 1 WHERE login = ?',
            [$login]
        );

        $rows = $this->db->query(
            'SELECT failed_attempts FROM hc_user WHERE login = ?',
            [$login]
        );
        if ($rows === []) {
            return 0;
        }

        return (int) $rows[0]['failed_attempts'];
    }

    public function applyLockout(string $login, string $lockedUntil): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET locked_until = ? WHERE login = ?',
            [$lockedUntil, $login]
        );
    }

    public function resetLoginAttempts(string $login): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET failed_attempts = 0, locked_until = NULL WHERE login = ?',
            [$login]
        );
    }

    public function setTotpSecret(string $login, string $base32Secret): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET totp_secret = ? WHERE login = ?',
            [$base32Secret, $login]
        );
    }

    public function enableTotp(string $login, array $recoveryCodeHashes): bool
    {
        return $this->db->execute(
            "UPDATE hc_user SET totp_enabled = 'Y', totp_recovery_codes = ? WHERE login = ?",
            [self::encodeRecoveryCodes($recoveryCodeHashes), $login]
        );
    }

    public function disableTotp(string $login): bool
    {
        return $this->db->execute(
            "UPDATE hc_user SET totp_secret = NULL, totp_enabled = 'N', totp_recovery_codes = NULL WHERE login = ?",
            [$login]
        );
    }

    public function setRecoveryCodes(string $login, array $recoveryCodeHashes): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET totp_recovery_codes = ? WHERE login = ?',
            [self::encodeRecoveryCodes($recoveryCodeHashes), $login]
        );
    }

    public function updateEmail(string $login, ?string $email): bool
    {
        // Null / empty-after-trim both clear the column. Trusting the
        // caller to pre-validate — the repo is intentionally
        // write-what-you-give-me so tests can poke edge cases.
        $stored = $email === null || trim($email) === '' ? null : trim($email);

        return $this->db->execute(
            'UPDATE hc_user SET email = ? WHERE login = ?',
            [$stored, $login]
        );
    }

    public function updateEmailNotifications(string $login, bool $on): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET email_notifications = ? WHERE login = ?',
            [$on ? 'Y' : 'N', $login]
        );
    }

    public function getEmailSubscribers(): array
    {
        $rows = $this->db->query(
            "SELECT email FROM hc_user
             WHERE email_notifications = 'Y'
               AND email IS NOT NULL AND email <> ''
               AND enabled = 'Y'"
        );

        return array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['email'] ?? ''),
            $rows
        ), static fn (string $v): bool => $v !== ''));
    }

    public function updateLastLoginIp(string $login, ?string $ip): bool
    {
        $stored = $ip === null || trim($ip) === '' ? null : trim($ip);

        return $this->db->execute(
            'UPDATE hc_user SET last_login_ip = ? WHERE login = ?',
            [$stored, $login]
        );
    }

    public function updateDigestEnabled(string $login, bool $on): bool
    {
        return $this->db->execute(
            'UPDATE hc_user SET digest_enabled = ? WHERE login = ?',
            [$on ? 'Y' : 'N', $login]
        );
    }

    public function getDigestSubscribers(): array
    {
        $rows = $this->db->query(
            "SELECT login, email FROM hc_user
             WHERE digest_enabled = 'Y'
               AND email IS NOT NULL AND email <> ''
               AND enabled = 'Y'"
        );

        $out = [];
        foreach ($rows as $r) {
            $login = (string) ($r['login'] ?? '');
            $email = (string) ($r['email'] ?? '');
            if ($login !== '' && $email !== '') {
                $out[] = ['login' => $login, 'email' => $email];
            }
        }

        return $out;
    }

    public function updateNotificationChannels(string $login, array $channelNames): bool
    {
        // Defensive: filter to strings, drop duplicates, reindex so the
        // stored JSON is always a clean `["ntfy","email"]`-shaped list
        // regardless of caller hygiene.
        $clean = array_values(array_unique(array_filter(
            $channelNames,
            static fn (string $name): bool => $name !== ''
        )));
        $json = json_encode($clean);
        if ($json === false) {
            $json = '[]';
        }

        return $this->db->execute(
            'UPDATE hc_user SET notification_channels = ? WHERE login = ?',
            [$json, $login]
        );
    }

    /**
     * @param list<string> $hashes
     */
    private static function encodeRecoveryCodes(array $hashes): ?string
    {
        if ($hashes === []) {
            return null;
        }
        $encoded = json_encode($hashes);

        return $encoded === false ? null : $encoded;
    }

    private function selectColumns(): string
    {
        return 'SELECT login, passwd, email, is_admin, role, enabled, '
            . 'remember_token, remember_token_expires, '
            . 'failed_attempts, locked_until, api_key_hash, '
            . 'totp_secret, totp_enabled, totp_recovery_codes, '
            . 'email_notifications, notification_channels, '
            . 'last_login_ip, digest_enabled FROM hc_user';
    }

    /**
     * @param array<string,scalar|null> $row
     *
     * @return UserRecord
     */
    private static function hydrate(array $row): array
    {
        return [
            'login' => (string) $row['login'],
            'passwd' => (string) ($row['passwd'] ?? ''),
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'is_admin' => (string) ($row['is_admin'] ?? 'N'),
            'role' => (string) ($row['role'] ?? 'caregiver'),
            'enabled' => (string) ($row['enabled'] ?? 'Y'),
            'remember_token' => $row['remember_token'] === null ? null : (string) $row['remember_token'],
            'remember_token_expires' => $row['remember_token_expires'] === null
                ? null : (string) $row['remember_token_expires'],
            'failed_attempts' => (int) ($row['failed_attempts'] ?? 0),
            'locked_until' => $row['locked_until'] === null ? null : (string) $row['locked_until'],
            'api_key_hash' => $row['api_key_hash'] === null ? null : (string) $row['api_key_hash'],
            'totp_secret' => $row['totp_secret'] === null ? null : (string) $row['totp_secret'],
            'totp_enabled' => (string) ($row['totp_enabled'] ?? 'N'),
            'totp_recovery_codes' => $row['totp_recovery_codes'] === null
                ? null : (string) $row['totp_recovery_codes'],
            'email_notifications' => (string) ($row['email_notifications'] ?? 'N'),
            'notification_channels' => (string) ($row['notification_channels'] ?? '[]'),
            'last_login_ip' => $row['last_login_ip'] === null
                ? null : (string) $row['last_login_ip'],
            'digest_enabled' => (string) ($row['digest_enabled'] ?? 'N'),
        ];
    }
}
