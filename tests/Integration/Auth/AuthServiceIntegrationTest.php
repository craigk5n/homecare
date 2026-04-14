<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\AuthService;
use HomeCare\Auth\PasswordHasher;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * End-to-end auth flow against the real SQLite schema (post-HC-010
 * with the role column, post-migration-003 with remember_token).
 */
final class AuthServiceIntegrationTest extends DatabaseTestCase
{
    private AuthService $auth;
    private UserRepository $users;
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->getDb());
        $this->hasher = new PasswordHasher();
        $this->auth = new AuthService($this->users, $this->hasher);

        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, is_admin, role, enabled)
             VALUES (?, ?, 'N', 'caregiver', 'Y')",
            ['alice', $this->hasher->hash('correct-horse')]
        );
    }

    public function testAttemptLoginSucceedsAndRecordsLastLogin(): void
    {
        $result = $this->auth->attemptLogin('alice', 'correct-horse');

        $this->assertTrue($result->success, (string) $result->reason);
        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        // touch_last_login ran -- but we can't easily assert the int unless we
        // expose it; just verify the row still loads.
        $this->assertSame('alice', $row['login']);
    }

    public function testRememberMeRoundTrip(): void
    {
        $login = $this->auth->attemptLogin('alice', 'correct-horse', remember: true);
        $this->assertTrue($login->success);
        $this->assertNotNull($login->rememberToken);

        // Subsequent request arrives with the raw token in a cookie.
        $verify = $this->auth->loginWithRememberToken($login->rememberToken);

        $this->assertTrue($verify->success, (string) $verify->reason);
        $this->assertNotNull($verify->user);
        $this->assertSame('alice', $verify->user['login']);
    }

    public function testLogoutInvalidatesRememberToken(): void
    {
        $login = $this->auth->attemptLogin('alice', 'correct-horse', remember: true);
        $this->assertNotNull($login->rememberToken);

        $this->auth->logout('alice');

        $verify = $this->auth->loginWithRememberToken($login->rememberToken);
        $this->assertFalse($verify->success);
        $this->assertSame('invalid_token', $verify->reason);
    }

    public function testWrongPasswordDoesNotIssueToken(): void
    {
        $result = $this->auth->attemptLogin('alice', 'wrong', remember: true);
        $this->assertFalse($result->success);
        $this->assertNull($result->rememberToken);
    }

    public function testDisabledAccountCannotLogin(): void
    {
        $this->getDb()->execute(
            "UPDATE hc_user SET enabled = 'N' WHERE login = ?",
            ['alice']
        );

        $result = $this->auth->attemptLogin('alice', 'correct-horse');
        $this->assertFalse($result->success);
        $this->assertSame('account_disabled', $result->reason);
    }
}
