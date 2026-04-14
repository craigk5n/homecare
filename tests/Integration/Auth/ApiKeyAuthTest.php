<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\ApiKeyAuth;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * End-to-end API key auth against the real schema.
 *
 * Verifies the full round-trip: generate a raw key → store the hash →
 * authenticate a "Bearer <raw>" header → rotate/clear.
 */
final class ApiKeyAuthTest extends DatabaseTestCase
{
    private UserRepository $users;
    private ApiKeyAuth $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->getDb());
        $this->auth = new ApiKeyAuth($this->users);

        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, is_admin, role, enabled)
             VALUES (?, '', 'N', 'caregiver', 'Y')",
            ['alice']
        );
    }

    public function testValidKeyReturnsUserInfo(): void
    {
        $raw = ApiKeyAuth::generateRawKey();
        $this->users->updateApiKeyHash('alice', ApiKeyAuth::hashKey($raw));

        $result = $this->auth->authenticate('Bearer ' . $raw);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->user);
        $this->assertSame('alice', $result->user['login']);
        $this->assertSame(200, ApiKeyAuth::httpStatusFor($result));
    }

    public function testInvalidKeyReturns401(): void
    {
        $raw = ApiKeyAuth::generateRawKey();
        $this->users->updateApiKeyHash('alice', ApiKeyAuth::hashKey($raw));

        $result = $this->auth->authenticate('Bearer definitely-not-' . $raw);

        $this->assertFalse($result->success);
        $this->assertSame('invalid_key', $result->reason);
        $this->assertSame(401, ApiKeyAuth::httpStatusFor($result));
    }

    public function testMissingHeaderReturns401(): void
    {
        $result = $this->auth->authenticate(null);
        $this->assertSame(401, ApiKeyAuth::httpStatusFor($result));
    }

    public function testKeyForDisabledUserReturns403(): void
    {
        $raw = ApiKeyAuth::generateRawKey();
        $this->users->updateApiKeyHash('alice', ApiKeyAuth::hashKey($raw));
        $this->getDb()->execute("UPDATE hc_user SET enabled = 'N' WHERE login = 'alice'");

        $result = $this->auth->authenticate('Bearer ' . $raw);

        $this->assertFalse($result->success);
        $this->assertSame('account_disabled', $result->reason);
        $this->assertSame(403, ApiKeyAuth::httpStatusFor($result));
    }

    public function testClearingHashRevokesKey(): void
    {
        $raw = ApiKeyAuth::generateRawKey();
        $this->users->updateApiKeyHash('alice', ApiKeyAuth::hashKey($raw));

        $this->assertTrue($this->auth->authenticate('Bearer ' . $raw)->success);

        $this->users->updateApiKeyHash('alice', null);

        $this->assertFalse($this->auth->authenticate('Bearer ' . $raw)->success);
    }

    public function testStoredHashNeverEqualsRawKey(): void
    {
        // Guard against accidentally storing the raw token.
        $raw = ApiKeyAuth::generateRawKey();
        $this->users->updateApiKeyHash('alice', ApiKeyAuth::hashKey($raw));

        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $this->assertNotSame($raw, $row['api_key_hash']);
        $this->assertSame(64, strlen((string) $row['api_key_hash']), 'SHA-256 hex is 64 chars');
    }
}
