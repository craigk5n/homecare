<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Config;

use HomeCare\Config\ReverseProxyConfig;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Integration tests for ReverseProxyConfig (HC-143).
 */
final class ReverseProxyConfigTest extends DatabaseTestCase
{
    private ReverseProxyConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new ReverseProxyConfig($this->getDb());
    }

    public function testDefaultsWhenNoRowsExist(): void
    {
        self::assertSame('native', $this->config->getAuthMode());
        self::assertFalse($this->config->isReverseProxyMode());
        self::assertSame('X-Forwarded-User', $this->config->getHeader());
    }

    public function testSetAndGetAuthMode(): void
    {
        $this->config->setAuthMode('reverse_proxy');

        self::assertSame('reverse_proxy', $this->config->getAuthMode());
        self::assertTrue($this->config->isReverseProxyMode());
    }

    public function testSetAuthModeBackToNative(): void
    {
        $this->config->setAuthMode('reverse_proxy');
        $this->config->setAuthMode('native');

        self::assertSame('native', $this->config->getAuthMode());
        self::assertFalse($this->config->isReverseProxyMode());
    }

    public function testInvalidAuthModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->setAuthMode('invalid');
    }

    public function testSetAndGetHeader(): void
    {
        $this->config->setHeader('X-Remote-User');

        self::assertSame('X-Remote-User', $this->config->getHeader());
    }

    public function testGetAllReturnsFullState(): void
    {
        $this->config->setAuthMode('reverse_proxy');
        $this->config->setHeader('X-Auth-User');

        $all = $this->config->getAll();

        self::assertSame('reverse_proxy', $all['auth_mode']);
        self::assertSame('X-Auth-User', $all['header']);
    }
}
