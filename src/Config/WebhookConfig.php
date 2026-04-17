<?php

declare(strict_types=1);

namespace HomeCare\Config;

use HomeCare\Database\DatabaseInterface;

/**
 * Typed reader/writer for the webhook-channel settings stored in
 * `hc_config` (HC-102).
 *
 * Three settings:
 *   - `webhook_url`              destination URL
 *   - `webhook_enabled`          'Y' / 'N' master switch (default 'N')
 *   - `webhook_timeout_seconds`  per-request timeout (default 5)
 *
 * Fail-closed: empty URL or enabled='N' both mean the channel is
 * not ready. The reminder cron short-circuits before each send on
 * `isReady()` so an uninstalled webhook costs nothing.
 */
final class WebhookConfig
{
    public const DEFAULT_URL = '';
    public const DEFAULT_ENABLED = false;
    public const DEFAULT_TIMEOUT_SECONDS = 5;

    public function __construct(private readonly DatabaseInterface $db) {}

    public function getUrl(): string
    {
        return $this->getSetting('webhook_url') ?? self::DEFAULT_URL;
    }

    public function isEnabled(): bool
    {
        return $this->getSetting('webhook_enabled') === 'Y';
    }

    public function getTimeoutSeconds(): int
    {
        $raw = $this->getSetting('webhook_timeout_seconds');
        if ($raw === null || !ctype_digit($raw)) {
            return self::DEFAULT_TIMEOUT_SECONDS;
        }
        $v = (int) $raw;

        return $v > 0 ? $v : self::DEFAULT_TIMEOUT_SECONDS;
    }

    public function setUrl(string $url): void
    {
        $this->setSetting('webhook_url', $url);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->setSetting('webhook_enabled', $enabled ? 'Y' : 'N');
    }

    public function setTimeoutSeconds(int $seconds): void
    {
        $this->setSetting('webhook_timeout_seconds', (string) max(1, $seconds));
    }

    /**
     * @return array{url:string,enabled:bool,timeout_seconds:int}
     */
    public function getAll(): array
    {
        return [
            'url'             => $this->getUrl(),
            'enabled'         => $this->isEnabled(),
            'timeout_seconds' => $this->getTimeoutSeconds(),
        ];
    }

    public function isReady(): bool
    {
        return $this->isEnabled() && $this->getUrl() !== '';
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
