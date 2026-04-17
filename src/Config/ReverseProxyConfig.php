<?php

declare(strict_types=1);

namespace HomeCare\Config;

use HomeCare\Database\DatabaseInterface;

/**
 * Typed reader/writer for the reverse-proxy authentication settings
 * stored in `hc_config` (HC-143).
 *
 * Two settings:
 *   - `auth_mode`             'native' (default) or 'reverse_proxy'
 *   - `reverse_proxy_header`  HTTP header name (default 'X-Forwarded-User')
 *
 * Defaults kick in when the rows are missing, so existing installs
 * work without running the migration first.
 */
final class ReverseProxyConfig
{
    public const AUTH_MODE_NATIVE = 'native';
    public const AUTH_MODE_REVERSE_PROXY = 'reverse_proxy';
    public const DEFAULT_HEADER = 'X-Forwarded-User';

    public function __construct(private readonly DatabaseInterface $db) {}

    public function getAuthMode(): string
    {
        return $this->getSetting('auth_mode') ?? self::AUTH_MODE_NATIVE;
    }

    public function isReverseProxyMode(): bool
    {
        return $this->getAuthMode() === self::AUTH_MODE_REVERSE_PROXY;
    }

    public function getHeader(): string
    {
        return $this->getSetting('reverse_proxy_header') ?? self::DEFAULT_HEADER;
    }

    public function setAuthMode(string $mode): void
    {
        if (!in_array($mode, [self::AUTH_MODE_NATIVE, self::AUTH_MODE_REVERSE_PROXY], true)) {
            throw new \InvalidArgumentException("Invalid auth_mode: {$mode}");
        }
        $this->setSetting('auth_mode', $mode);
    }

    public function setHeader(string $header): void
    {
        $this->setSetting('reverse_proxy_header', $header);
    }

    /**
     * @return array{auth_mode: string, header: string}
     */
    public function getAll(): array
    {
        return [
            'auth_mode' => $this->getAuthMode(),
            'header' => $this->getHeader(),
        ];
    }

    private function getSetting(string $key): ?string
    {
        $rows = $this->db->query(
            'SELECT value FROM hc_config WHERE setting = ?',
            [$key],
        );
        if ($rows === []) {
            return null;
        }
        $v = $rows[0]['value'] ?? null;

        return $v === null ? null : (string) $v;
    }

    private function setSetting(string $key, string $value): void
    {
        $existing = $this->db->query(
            'SELECT setting FROM hc_config WHERE setting = ?',
            [$key],
        );
        if ($existing === []) {
            $this->db->execute(
                'INSERT INTO hc_config (setting, value) VALUES (?, ?)',
                [$key, $value],
            );
        } else {
            $this->db->execute(
                'UPDATE hc_config SET value = ? WHERE setting = ?',
                [$value, $key],
            );
        }
    }
}
