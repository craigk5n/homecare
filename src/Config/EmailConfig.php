<?php

declare(strict_types=1);

namespace HomeCare\Config;

use HomeCare\Database\DatabaseInterface;

/**
 * Typed reader/writer for the email-channel settings stored in
 * `hc_config` (HC-101).
 *
 * Four settings:
 *   - `smtp_dsn`           Symfony Mailer DSN
 *                          (e.g. `smtp://user:pass@mail.example.com:587`)
 *   - `smtp_from_address`  envelope-from address (RFC 5322)
 *   - `smtp_from_name`     optional display name
 *   - `smtp_enabled`       'Y' / 'N' master switch (default 'N')
 *
 * Fail-closed defaults — the email channel never fires on a fresh
 * install until an admin actively configures it.
 */
final class EmailConfig
{
    public const DEFAULT_DSN = '';
    public const DEFAULT_FROM_ADDRESS = '';
    public const DEFAULT_FROM_NAME = 'HomeCare';
    public const DEFAULT_ENABLED = false;

    public function __construct(private readonly DatabaseInterface $db) {}

    public function getDsn(): string
    {
        return $this->getSetting('smtp_dsn') ?? self::DEFAULT_DSN;
    }

    public function getFromAddress(): string
    {
        return $this->getSetting('smtp_from_address') ?? self::DEFAULT_FROM_ADDRESS;
    }

    public function getFromName(): string
    {
        return $this->getSetting('smtp_from_name') ?? self::DEFAULT_FROM_NAME;
    }

    public function isEnabled(): bool
    {
        return $this->getSetting('smtp_enabled') === 'Y';
    }

    public function setDsn(string $dsn): void
    {
        $this->setSetting('smtp_dsn', $dsn);
    }

    public function setFromAddress(string $address): void
    {
        $this->setSetting('smtp_from_address', $address);
    }

    public function setFromName(string $name): void
    {
        $this->setSetting('smtp_from_name', $name);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->setSetting('smtp_enabled', $enabled ? 'Y' : 'N');
    }

    /**
     * @return array{dsn:string,from_address:string,from_name:string,enabled:bool}
     */
    public function getAll(): array
    {
        return [
            'dsn' => $this->getDsn(),
            'from_address' => $this->getFromAddress(),
            'from_name' => $this->getFromName(),
            'enabled' => $this->isEnabled(),
        ];
    }

    /**
     * True when the channel has enough configuration to attempt a send:
     * enabled flag on, non-empty DSN, non-empty From address.
     */
    public function isReady(): bool
    {
        return $this->isEnabled()
            && $this->getDsn() !== ''
            && $this->getFromAddress() !== '';
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
