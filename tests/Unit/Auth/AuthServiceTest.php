<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\AuthService;
use HomeCare\Auth\PasswordHasher;
use HomeCare\Repository\UserRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    /** @var UserRepositoryInterface&MockObject */
    private UserRepositoryInterface $users;
    private PasswordHasher $hasher;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = $this->createMock(UserRepositoryInterface::class);
        $this->hasher = new PasswordHasher();
    }

    public function testAttemptLoginFailsForUnknownUser(): void
    {
        $this->users->method('findByLogin')->willReturn(null);

        $result = $this->service()->attemptLogin('ghost', 'pw');

        $this->assertFalse($result->success);
        $this->assertSame('invalid_credentials', $result->reason);
    }

    public function testAttemptLoginFailsForDisabledUser(): void
    {
        $this->users->method('findByLogin')->willReturn($this->userRow(enabled: 'N'));

        $result = $this->service()->attemptLogin('alice', 'correct');

        $this->assertFalse($result->success);
        $this->assertSame('account_disabled', $result->reason);
    }

    public function testAttemptLoginFailsForWrongPassword(): void
    {
        $this->users->method('findByLogin')->willReturn(
            $this->userRow(passwd: $this->hasher->hash('correct'))
        );

        $result = $this->service()->attemptLogin('alice', 'wrong');

        $this->assertFalse($result->success);
        $this->assertSame('invalid_credentials', $result->reason);
    }

    public function testAttemptLoginSuccessWithoutRemember(): void
    {
        $this->users->method('findByLogin')->willReturn(
            $this->userRow(passwd: $this->hasher->hash('correct'))
        );
        $this->users->expects($this->once())
            ->method('touchLastLogin')
            ->with('alice', $this->now);
        $this->users->expects($this->never())->method('updateRememberToken');

        $result = $this->service()->attemptLogin('alice', 'correct');

        $this->assertTrue($result->success);
        $this->assertNull($result->rememberToken);
        $this->assertNotNull($result->user);
        $this->assertSame('alice', $result->user['login']);
    }

    public function testAttemptLoginSuccessWithRememberPersistsHashedToken(): void
    {
        $this->users->method('findByLogin')->willReturn(
            $this->userRow(passwd: $this->hasher->hash('correct'))
        );
        $capturedHash = null;
        $capturedExpires = null;
        $this->users->expects($this->once())
            ->method('updateRememberToken')
            ->willReturnCallback(function (string $login, ?string $hash, ?string $expires)
                use (&$capturedHash, &$capturedExpires): bool {
                $this->assertSame('alice', $login);
                $capturedHash = $hash;
                $capturedExpires = $expires;

                return true;
            });

        $result = $this->service(tokenFactory: static fn () => 'deadbeef')
            ->attemptLogin('alice', 'correct', remember: true);

        $this->assertTrue($result->success);
        $this->assertSame('deadbeef', $result->rememberToken, 'raw token returned to caller');
        $this->assertSame(hash('sha256', 'deadbeef'), $capturedHash, 'hash stored in DB');
        $this->assertSame(
            $this->now + AuthService::REMEMBER_LIFETIME_SECONDS,
            $result->rememberExpires
        );
        $this->assertNotNull($capturedExpires);
    }

    public function testAttemptLoginRehashesLegacyHash(): void
    {
        // A valid bcrypt hash at cost 4 verifies successfully but needsRehash
        // flags it for upgrade (default cost is 10+). Exercises the rehash-
        // on-verify branch.
        $lowCost = password_hash('correct', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->users->method('findByLogin')->willReturn($this->userRow(passwd: $lowCost));
        $this->users->expects($this->once())
            ->method('updatePasswordHash')
            ->with('alice', self::callback(static fn (mixed $h): bool => is_string($h) && $h !== $lowCost));

        $result = $this->service()->attemptLogin('alice', 'correct');

        $this->assertTrue($result->success);
    }

    public function testLoginWithRememberTokenSuccess(): void
    {
        $rawToken = 'raw-token-value';
        $hashedToken = hash('sha256', $rawToken);
        $this->users->method('findByRememberTokenHash')
            ->with($hashedToken)
            ->willReturn($this->userRow(
                remember_token: $hashedToken,
                remember_token_expires: date('Y-m-d H:i:s', $this->now + 3600),
            ));

        $result = $this->service()->loginWithRememberToken($rawToken);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->user);
        $this->assertSame('alice', $result->user['login']);
    }

    public function testLoginWithRememberTokenRejectsExpired(): void
    {
        $rawToken = 'raw';
        $this->users->method('findByRememberTokenHash')->willReturn($this->userRow(
            remember_token: hash('sha256', $rawToken),
            remember_token_expires: date('Y-m-d H:i:s', $this->now - 3600),
        ));
        $this->users->expects($this->once())
            ->method('updateRememberToken')
            ->with('alice', null, null);

        $result = $this->service()->loginWithRememberToken($rawToken);

        $this->assertFalse($result->success);
        $this->assertSame('expired_token', $result->reason);
    }

    public function testLoginWithRememberTokenRejectsMissing(): void
    {
        $result = $this->service()->loginWithRememberToken('');
        $this->assertSame('missing_token', $result->reason);
    }

    public function testLoginWithRememberTokenRejectsUnknown(): void
    {
        $this->users->method('findByRememberTokenHash')->willReturn(null);
        $result = $this->service()->loginWithRememberToken('anything');
        $this->assertSame('invalid_token', $result->reason);
    }

    public function testLogoutClearsRememberToken(): void
    {
        $this->users->expects($this->once())
            ->method('updateRememberToken')
            ->with('alice', null, null);
        $this->service()->logout('alice');
    }

    public function testHashTokenIsStable(): void
    {
        $this->assertSame(
            hash('sha256', 'abc'),
            AuthService::hashToken('abc'),
        );
    }

    private function service(?callable $tokenFactory = null): AuthService
    {
        return new AuthService(
            $this->users,
            $this->hasher,
            fn (): int => $this->now,
            $tokenFactory ?? static fn (): string => 'fixed-token',
        );
    }

    /**
     * @return array{login:string,passwd:string,is_admin:string,role:string,enabled:string,remember_token:?string,remember_token_expires:?string,failed_attempts:int,locked_until:?string,api_key_hash:?string}
     */
    private function userRow(
        string $login = 'alice',
        string $passwd = '',
        string $is_admin = 'N',
        string $role = 'caregiver',
        string $enabled = 'Y',
        ?string $remember_token = null,
        ?string $remember_token_expires = null,
        int $failed_attempts = 0,
        ?string $locked_until = null,
        ?string $api_key_hash = null,
    ): array {
        return [
            'login' => $login,
            'passwd' => $passwd,
            'is_admin' => $is_admin,
            'role' => $role,
            'enabled' => $enabled,
            'remember_token' => $remember_token,
            'remember_token_expires' => $remember_token_expires,
            'failed_attempts' => $failed_attempts,
            'locked_until' => $locked_until,
            'api_key_hash' => $api_key_hash,
        ];
    }
}
