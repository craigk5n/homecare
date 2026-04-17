<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Api;

use HomeCare\Api\ApiAuth;
use HomeCare\Auth\ApiKeyAuth;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Gating on API endpoints: every request without a valid Bearer key
 * must fail before any handler runs. This exercises the ApiAuth /
 * ApiKeyAuth glue that `_bootstrap.php` uses.
 */
final class AuthApiTest extends DatabaseTestCase
{
    private UserRepository $users;
    private ApiAuth $apiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->getDb());
        $this->apiAuth = new ApiAuth($this->users);

        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, is_admin, role, enabled)
             VALUES (?, '', 'N', 'caregiver', 'Y')",
            ['alice'],
        );
    }

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $result = $this->apiAuth->authenticate([]);

        $this->assertFalse($result->success);
        $this->assertSame(401, ApiKeyAuth::httpStatusFor($result));
    }

    public function testInvalidKeyReturns401(): void
    {
        $result = $this->apiAuth->authenticate([
            'HTTP_AUTHORIZATION' => 'Bearer no-such-key',
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('invalid_key', $result->reason);
        $this->assertSame(401, ApiKeyAuth::httpStatusFor($result));
    }

    public function testMalformedAuthorizationReturns401(): void
    {
        $result = $this->apiAuth->authenticate([
            'HTTP_AUTHORIZATION' => 'Basic abc==',
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('malformed_auth', $result->reason);
        $this->assertSame(401, ApiKeyAuth::httpStatusFor($result));
    }

    public function testValidKeyResolvesUser(): void
    {
        $raw = ApiKeyAuth::generateRawKey();
        $this->users->updateApiKeyHash('alice', ApiKeyAuth::hashKey($raw));

        $result = $this->apiAuth->authenticate([
            'HTTP_AUTHORIZATION' => 'Bearer ' . $raw,
        ]);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->user);
        $this->assertSame('alice', $result->user['login']);
    }

    public function testDisabledUserReturns403(): void
    {
        $raw = ApiKeyAuth::generateRawKey();
        $this->users->updateApiKeyHash('alice', ApiKeyAuth::hashKey($raw));
        $this->getDb()->execute("UPDATE hc_user SET enabled = 'N' WHERE login = 'alice'");

        $result = $this->apiAuth->authenticate([
            'HTTP_AUTHORIZATION' => 'Bearer ' . $raw,
        ]);

        $this->assertSame(403, ApiKeyAuth::httpStatusFor($result));
    }

    public function testHonoursRedirectHttpAuthorization(): void
    {
        // mod_rewrite under Apache often renames Authorization to
        // REDIRECT_HTTP_AUTHORIZATION; accept both.
        $raw = ApiKeyAuth::generateRawKey();
        $this->users->updateApiKeyHash('alice', ApiKeyAuth::hashKey($raw));

        $result = $this->apiAuth->authenticate([
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $raw,
        ]);

        $this->assertTrue($result->success);
    }
}
