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
        return 'SELECT login, passwd, is_admin, role, enabled, '
            . 'remember_token, remember_token_expires, '
            . 'failed_attempts, locked_until, api_key_hash, '
            . 'totp_secret, totp_enabled, totp_recovery_codes FROM hc_user';
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
        ];
    }
}
