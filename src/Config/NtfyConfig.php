<?php

declare(strict_types=1);

namespace HomeCare\Config;

use HomeCare\Database\DatabaseInterface;

/**
 * Typed reader/writer for the ntfy push-notification settings stored
 * in `hc_config`.
 *
 * Three settings:
 *   - `ntfy_url`     server base URL (default `https://ntfy.sh/`)
 *   - `ntfy_topic`   channel / topic name (default empty → treated as off)
 *   - `ntfy_enabled` 'Y' / 'N' master switch (default 'N', fail-closed)
 *
 * Defaults kick in when the corresponding row is missing, so new
 * installs work without a data migration -- the first admin visit to
 * the settings page can persist the real values.
 */
final class NtfyConfig
{
    public const DEFAULT_URL = 'https://ntfy.sh/';
    public const DEFAULT_TOPIC = '';
    public const DEFAULT_ENABLED = false;

    public function __construct(private readonly DatabaseInterface $db) {}

    public function getUrl(): string
    {
        return $this->getSetting('ntfy_url') ?? self::DEFAULT_URL;
    }

    public function getTopic(): string
    {
        return $this->getSetting('ntfy_topic') ?? self::DEFAULT_TOPIC;
    }

    /**
     * Master switch. Returns false both when the setting is absent and
     * when it isn't explicitly 'Y' -- a typo or stray value doesn't
     * accidentally start pushing notifications.
     */
    public function isEnabled(): bool
    {
        return $this->getSetting('ntfy_enabled') === 'Y';
    }

    public function setUrl(string $url): void
    {
        $this->setSetting('ntfy_url', $url);
    }

    public function setTopic(string $topic): void
    {
        $this->setSetting('ntfy_topic', $topic);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->setSetting('ntfy_enabled', $enabled ? 'Y' : 'N');
    }

    /**
     * All three settings in one call, with defaults applied.
     *
     * @return array{url:string,topic:string,enabled:bool}
     */
    public function getAll(): array
    {
        return [
            'url' => $this->getUrl(),
            'topic' => $this->getTopic(),
            'enabled' => $this->isEnabled(),
        ];
    }

    /**
     * True when the config is sufficient to actually push a message
     * (enabled flag on, non-empty topic). send_reminders.php short-
     * circuits on this instead of reading each field separately.
     */
    public function isReady(): bool
    {
        return $this->isEnabled() && $this->getTopic() !== '';
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
