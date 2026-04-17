<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Multi-device remember-me token storage (replaces single-column on hc_user).
 *
 * Each row in hc_remember_tokens represents one device's remember-me cookie.
 * Multiple devices can hold valid tokens simultaneously.
 */
final class RememberTokenRepository
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Store a new token for a device. Does not invalidate other devices.
     */
    public function create(
        string $login,
        string $tokenHash,
        string $expiresAt,
        ?string $deviceName = null,
        ?string $ip = null,
    ): int {
        $this->db->execute(
            'INSERT INTO hc_remember_tokens (user_login, token_hash, expires_at, device_name, last_ip)
             VALUES (?, ?, ?, ?, ?)',
            [$login, $tokenHash, $expiresAt, $deviceName, $ip]
        );

        return $this->db->lastInsertId();
    }

    /**
     * Find the user login associated with a token hash.
     *
     * @return array{user_login:string, expires_at:string, id:int}|null
     */
    public function findByTokenHash(string $hash): ?array
    {
        if ($hash === '') {
            return null;
        }

        $rows = $this->db->query(
            'SELECT id, user_login, expires_at FROM hc_remember_tokens WHERE token_hash = ?',
            [$hash]
        );

        if ($rows === []) {
            return null;
        }

        return [
            'id' => (int) $rows[0]['id'],
            'user_login' => (string) $rows[0]['user_login'],
            'expires_at' => (string) $rows[0]['expires_at'],
        ];
    }

    /**
     * Delete a single token by its hash (logout on one device).
     */
    public function deleteByTokenHash(string $hash): bool
    {
        return $this->db->execute(
            'DELETE FROM hc_remember_tokens WHERE token_hash = ?',
            [$hash]
        );
    }

    /**
     * Delete all tokens for a user (logout everywhere).
     */
    public function deleteAllForUser(string $login): bool
    {
        return $this->db->execute(
            'DELETE FROM hc_remember_tokens WHERE user_login = ?',
            [$login]
        );
    }

    /**
     * Delete expired tokens (housekeeping).
     */
    public function deleteExpired(): int
    {
        $this->db->execute(
            "DELETE FROM hc_remember_tokens WHERE expires_at < datetime('now')"
        );

        return 0;
    }

    /**
     * Touch the last_used_at timestamp when a token is validated.
     */
    public function touchLastUsed(int $id, ?string $ip = null): bool
    {
        return $this->db->execute(
            "UPDATE hc_remember_tokens SET last_used_at = datetime('now'), last_ip = ? WHERE id = ?",
            [$ip, $id]
        );
    }
}
