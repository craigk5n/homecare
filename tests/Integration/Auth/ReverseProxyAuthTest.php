<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\ReverseProxyAuth;
use HomeCare\Config\ReverseProxyConfig;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Integration tests for HC-143 reverse-proxy authentication.
 */
final class ReverseProxyAuthTest extends DatabaseTestCase
{
    private ReverseProxyAuth $auth;
    private ReverseProxyConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->getDb();

        // Seed the config rows.
        $db->execute(
            "INSERT INTO hc_config (setting, value) VALUES ('auth_mode', 'reverse_proxy')",
            [],
        );
        $db->execute(
            "INSERT INTO hc_config (setting, value) VALUES ('reverse_proxy_header', 'X-Forwarded-User')",
            [],
        );

        // Seed a test user.
        $db->execute(
            "INSERT INTO hc_user (login, passwd, firstname, lastname, email, is_admin, role, enabled)
             VALUES ('alice', 'unused', 'Alice', 'Admin', 'alice@example.com', 'N', 'caregiver', 'Y')",
            [],
        );

        // Seed a disabled user.
        $db->execute(
            "INSERT INTO hc_user (login, passwd, firstname, lastname, email, is_admin, role, enabled)
             VALUES ('disabled_bob', 'unused', 'Bob', 'Disabled', 'bob@example.com', 'N', 'viewer', 'N')",
            [],
        );

        $this->config = new ReverseProxyConfig($db);
        $this->auth = new ReverseProxyAuth($this->config, new UserRepository($db));
    }

    public function testHeaderResolvesToCorrectUser(): void
    {
        $result = $this->auth->authenticate([
            'HTTP_X_FORWARDED_USER' => 'alice',
        ]);

        self::assertTrue($result->success);
        self::assertNotNull($result->user);
        self::assertSame('alice', $result->user['login']);
        self::assertSame('alice@example.com', $result->user['email']);
    }

    public function testMissingHeaderReturns401(): void
    {
        $result = $this->auth->authenticate([]);

        self::assertFalse($result->success);
        self::assertSame('reverse_proxy_header_missing', $result->reason);
    }

    public function testEmptyHeaderReturns401(): void
    {
        $result = $this->auth->authenticate([
            'HTTP_X_FORWARDED_USER' => '',
        ]);

        self::assertFalse($result->success);
        self::assertSame('reverse_proxy_header_missing', $result->reason);
    }

    public function testUnknownUserReturns401(): void
    {
        $result = $this->auth->authenticate([
            'HTTP_X_FORWARDED_USER' => 'nonexistent',
        ]);

        self::assertFalse($result->success);
        self::assertSame('reverse_proxy_user_not_found', $result->reason);
    }

    public function testDisabledUserReturns401(): void
    {
        $result = $this->auth->authenticate([
            'HTTP_X_FORWARDED_USER' => 'disabled_bob',
        ]);

        self::assertFalse($result->success);
        self::assertSame('account_disabled', $result->reason);
    }

    public function testCustomHeaderName(): void
    {
        // Change the configured header.
        $this->config->setHeader('X-Remote-User');

        $result = $this->auth->authenticate([
            'HTTP_X_REMOTE_USER' => 'alice',
        ]);

        self::assertTrue($result->success);
        self::assertNotNull($result->user);
        self::assertSame('alice', $result->user['login']);
    }

    public function testDefaultHeaderValueIgnoredWhenCustomSet(): void
    {
        $this->config->setHeader('X-Remote-User');

        // The old default header should NOT match.
        $result = $this->auth->authenticate([
            'HTTP_X_FORWARDED_USER' => 'alice',
        ]);

        self::assertFalse($result->success);
        self::assertSame('reverse_proxy_header_missing', $result->reason);
    }

    public function testWhitespaceInHeaderIsTrimmed(): void
    {
        $result = $this->auth->authenticate([
            'HTTP_X_FORWARDED_USER' => '  alice  ',
        ]);

        self::assertTrue($result->success);
        self::assertNotNull($result->user);
        self::assertSame('alice', $result->user['login']);
    }
}
