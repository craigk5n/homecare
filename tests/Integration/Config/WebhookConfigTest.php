<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Config;

use HomeCare\Config\WebhookConfig;
use HomeCare\Database\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class WebhookConfigTest extends TestCase
{
    private SqliteDatabase $db;

    private WebhookConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new SqliteDatabase();
        $this->db->pdo()->exec(
            'CREATE TABLE hc_config (setting VARCHAR(50) PRIMARY KEY, value VARCHAR(128))',
        );
        $this->config = new WebhookConfig($this->db);
    }

    public function testDefaultsOnBlankDatabase(): void
    {
        $this->assertSame('', $this->config->getUrl());
        $this->assertFalse($this->config->isEnabled());
        $this->assertSame(5, $this->config->getTimeoutSeconds());
        $this->assertFalse($this->config->isReady());
    }

    public function testSettersRoundTrip(): void
    {
        $this->config->setUrl('https://hook.test/homecare');
        $this->config->setEnabled(true);
        $this->config->setTimeoutSeconds(12);

        $fresh = new WebhookConfig($this->db);

        $this->assertSame('https://hook.test/homecare', $fresh->getUrl());
        $this->assertTrue($fresh->isEnabled());
        $this->assertSame(12, $fresh->getTimeoutSeconds());
        $this->assertTrue($fresh->isReady());
    }

    public function testIsReadyRequiresBothEnabledAndUrl(): void
    {
        $this->config->setEnabled(true);
        $this->assertFalse($this->config->isReady(), 'enabled but no URL');

        $this->config->setUrl('https://hook.test/');
        $this->assertTrue($this->config->isReady());

        $this->config->setEnabled(false);
        $this->assertFalse($this->config->isReady(), 'URL set but disabled');
    }

    public function testTimeoutRejectsZeroAndNegative(): void
    {
        $this->config->setTimeoutSeconds(0);
        $this->assertSame(
            1,
            $this->config->getTimeoutSeconds(),
            'setter clamps to >=1',
        );

        $this->config->setTimeoutSeconds(-5);
        $this->assertSame(1, $this->config->getTimeoutSeconds());
    }

    public function testEnabledFlagIsStrictY(): void
    {
        $this->db->execute(
            "INSERT INTO hc_config (setting, value) VALUES ('webhook_enabled', 'yes')",
        );
        $this->assertFalse($this->config->isEnabled());
    }
}
