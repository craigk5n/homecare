<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Config;

use HomeCare\Config\NtfyConfig;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class NtfyConfigTest extends DatabaseTestCase
{
    private NtfyConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new NtfyConfig($this->getDb());
    }

    public function testDefaultsWhenConfigEmpty(): void
    {
        $this->assertSame(NtfyConfig::DEFAULT_URL, $this->config->getUrl());
        $this->assertSame(NtfyConfig::DEFAULT_TOPIC, $this->config->getTopic());
        $this->assertFalse($this->config->isEnabled());
        $this->assertFalse($this->config->isReady());
    }

    public function testRoundTripEachSetting(): void
    {
        $this->config->setUrl('https://ntfy.example.com/');
        $this->config->setTopic('my-channel');
        $this->config->setEnabled(true);

        $this->assertSame('https://ntfy.example.com/', $this->config->getUrl());
        $this->assertSame('my-channel', $this->config->getTopic());
        $this->assertTrue($this->config->isEnabled());
        $this->assertTrue($this->config->isReady());
    }

    public function testGetAllReturnsHydratedShape(): void
    {
        $this->config->setUrl('https://push.example/');
        $this->config->setTopic('t');
        $this->config->setEnabled(true);

        $this->assertSame(
            ['url' => 'https://push.example/', 'topic' => 't', 'enabled' => true],
            $this->config->getAll(),
        );
    }

    public function testUpdatesOverwriteInPlace(): void
    {
        $this->config->setUrl('https://first/');
        $this->config->setUrl('https://second/');
        $this->config->setUrl('https://third/');

        $this->assertSame('https://third/', $this->config->getUrl());
        $rows = $this->getDb()->query(
            "SELECT COUNT(*) AS n FROM hc_config WHERE setting = 'ntfy_url'"
        );
        $this->assertSame(1, (int) $rows[0]['n'], 'no duplicate rows on re-save');
    }

    public function testEnabledRequiresExactY(): void
    {
        // Stray values shouldn't accidentally enable push.
        $this->getDb()->execute(
            "INSERT INTO hc_config (setting, value) VALUES ('ntfy_enabled', 'y')"
        );
        $this->assertFalse($this->config->isEnabled(), 'lowercase y does not count');

        $this->config->setEnabled(true);
        $this->assertTrue($this->config->isEnabled());

        $this->config->setEnabled(false);
        $this->assertFalse($this->config->isEnabled());
    }

    public function testIsReadyRequiresEnabledAndNonEmptyTopic(): void
    {
        $this->config->setEnabled(true);
        $this->assertFalse($this->config->isReady(), 'empty topic keeps isReady false');

        $this->config->setTopic('');
        $this->assertFalse($this->config->isReady());

        $this->config->setTopic('something');
        $this->assertTrue($this->config->isReady());

        $this->config->setEnabled(false);
        $this->assertFalse($this->config->isReady(), 'disabled forces isReady false');
    }
}
