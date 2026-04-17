<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\ApiKeyAuth;
use HomeCare\Repository\UserRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ApiKeyAuthTest extends TestCase
{
    /** @var UserRepositoryInterface&MockObject */
    private UserRepositoryInterface $users;
    private ApiKeyAuth $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->auth = new ApiKeyAuth($this->users);
    }

    public function testMissingHeaderFails(): void
    {
        $this->users->expects($this->never())->method('findByApiKeyHash');

        $result = $this->auth->authenticate(null);

        $this->assertFalse($result->success);
        $this->assertSame('missing_auth', $result->reason);
        $this->assertSame(401, ApiKeyAuth::httpStatusFor($result));
    }

    public function testEmptyHeaderFails(): void
    {
        $this->assertSame('missing_auth', $this->auth->authenticate('')->reason);
    }

    public function testMalformedHeaderFails(): void
    {
        $this->assertSame('malformed_auth', $this->auth->authenticate('not-a-bearer')->reason);
        $this->assertSame('malformed_auth', $this->auth->authenticate('Basic abc==')->reason);
        $this->assertSame('malformed_auth', $this->auth->authenticate('Bearer')->reason);
    }

    public function testCaseInsensitiveBearerScheme(): void
    {
        $raw = 'abc123';
        $this->users->method('findByApiKeyHash')
            ->with(ApiKeyAuth::hashKey($raw))
            ->willReturn($this->userRow());

        $this->assertTrue($this->auth->authenticate('Bearer ' . $raw)->success);
        $this->assertTrue($this->auth->authenticate('bearer ' . $raw)->success);
        $this->assertTrue($this->auth->authenticate('BEARER ' . $raw)->success);
    }

    public function testInvalidKeyFails(): void
    {
        $this->users->method('findByApiKeyHash')->willReturn(null);

        $result = $this->auth->authenticate('Bearer whatever');

        $this->assertFalse($result->success);
        $this->assertSame('invalid_key', $result->reason);
        $this->assertSame(401, ApiKeyAuth::httpStatusFor($result));
    }

    public function testDisabledUserYields403(): void
    {
        $this->users->method('findByApiKeyHash')->willReturn($this->userRow(enabled: 'N'));

        $result = $this->auth->authenticate('Bearer whatever');

        $this->assertFalse($result->success);
        $this->assertSame('account_disabled', $result->reason);
        $this->assertSame(403, ApiKeyAuth::httpStatusFor($result));
    }

    public function testValidKeyReturnsUserInfo(): void
    {
        $raw = 'my-secret-token';
        $this->users->method('findByApiKeyHash')
            ->with(ApiKeyAuth::hashKey($raw))
            ->willReturn($this->userRow(login: 'alice', role: 'admin'));

        $result = $this->auth->authenticate('Bearer ' . $raw);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->user);
        $this->assertSame('alice', $result->user['login']);
        $this->assertSame('admin', $result->user['role']);
        $this->assertSame(200, ApiKeyAuth::httpStatusFor($result));
    }

    public function testHashKeyIsStableSha256(): void
    {
        $this->assertSame(hash('sha256', 'x'), ApiKeyAuth::hashKey('x'));
    }

    public function testGenerateRawKeyIsHexAndLongEnough(): void
    {
        $k = ApiKeyAuth::generateRawKey();
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $k);
        $this->assertSame(ApiKeyAuth::KEY_BYTES * 2, strlen($k));
        // Two calls must not collide.
        $this->assertNotSame($k, ApiKeyAuth::generateRawKey());
    }

    /**
     * @return array{login:string,passwd:string,is_admin:string,role:string,enabled:string,remember_token:?string,remember_token_expires:?string,failed_attempts:int,locked_until:?string,api_key_hash:?string}
     */
    private function userRow(
        string $login = 'alice',
        string $role = 'caregiver',
        string $enabled = 'Y',
    ): array {
        return [
            'login' => $login,
            'passwd' => '',
            'is_admin' => $role === 'admin' ? 'Y' : 'N',
            'role' => $role,
            'enabled' => $enabled,
            'remember_token' => null,
            'remember_token_expires' => null,
            'failed_attempts' => 0,
            'locked_until' => null,
            'api_key_hash' => 'stored-hash',
        ];
    }
}
