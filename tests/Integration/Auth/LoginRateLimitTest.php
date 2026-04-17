<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\AuthService;
use HomeCare\Auth\PasswordHasher;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Rate-limit flow for password logins (HC-014).
 *
 * Uses an injectable clock so "after the lockout expires" doesn't
 * require the test suite to actually sleep.
 */
final class LoginRateLimitTest extends DatabaseTestCase
{
    private UserRepository $users;
    private PasswordHasher $hasher;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->getDb());
        $this->hasher = new PasswordHasher();

        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, is_admin, role, enabled)
             VALUES (?, ?, 'N', 'caregiver', 'Y')",
            ['alice', $this->hasher->hash('correct-horse')],
        );
    }

    public function testFourFailuresDoNotLockAccount(): void
    {
        $auth = $this->auth();

        for ($i = 0; $i < 4; $i++) {
            $result = $auth->attemptLogin('alice', 'wrong');
            $this->assertSame('invalid_credentials', $result->reason);
        }

        // The correct password should still work.
        $ok = $auth->attemptLogin('alice', 'correct-horse');
        $this->assertTrue($ok->success);
    }

    public function testFifthFailureLocksAccount(): void
    {
        $auth = $this->auth();

        for ($i = 0; $i < 5; $i++) {
            $auth->attemptLogin('alice', 'wrong');
        }

        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $this->assertSame(5, $row['failed_attempts']);
        $this->assertNotNull($row['locked_until']);
    }

    public function testJustLockedOutFlagIsEdgeTriggered(): void
    {
        $auth = $this->auth();

        // Attempts 1–4: failed, not yet locked.
        for ($i = 0; $i < 4; $i++) {
            $r = $auth->attemptLogin('alice', 'wrong');
            $this->assertFalse(
                $r->justLockedOut,
                "attempt {$i} should not be flagged as just-locked-out",
            );
        }

        // Attempt 5: this is the one that trips the lockout.
        $fifth = $auth->attemptLogin('alice', 'wrong');
        $this->assertTrue(
            $fifth->justLockedOut,
            'the Nth failure that trips applyLockout must be edge-triggered',
        );

        // Subsequent attempts while locked must NOT re-fire the flag,
        // otherwise the security email would fire on every attempt.
        $sixth = $auth->attemptLogin('alice', 'wrong');
        $this->assertFalse(
            $sixth->justLockedOut,
            'already-locked attempts must not re-fire justLockedOut',
        );
    }

    public function testLoginAttemptWhileLockedIsRejected(): void
    {
        $auth = $this->auth();

        for ($i = 0; $i < 5; $i++) {
            $auth->attemptLogin('alice', 'wrong');
        }

        // Now the correct password is also rejected -- account is locked.
        $result = $auth->attemptLogin('alice', 'correct-horse');
        $this->assertFalse($result->success);
        $this->assertSame('account_locked', $result->reason);
    }

    public function testAfterLockoutExpiresLoginSucceeds(): void
    {
        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $auth->attemptLogin('alice', 'wrong');
        }

        // Advance the clock past the lockout window.
        $this->now += AuthService::LOCKOUT_MINUTES * 60 + 1;

        $result = $this->auth()->attemptLogin('alice', 'correct-horse');
        $this->assertTrue($result->success, (string) $result->reason);
    }

    public function testSuccessfulLoginResetsCounter(): void
    {
        $auth = $this->auth();

        // Three misses.
        for ($i = 0; $i < 3; $i++) {
            $auth->attemptLogin('alice', 'wrong');
        }

        // Then success -- counter clears.
        $auth->attemptLogin('alice', 'correct-horse');

        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $this->assertSame(0, $row['failed_attempts']);
        $this->assertNull($row['locked_until']);
    }

    public function testLockedAccountCannotBeBypassedViaRememberToken(): void
    {
        // Give alice a valid remember-me cookie, then trigger the lockout.
        $issued = $this->auth()->attemptLogin('alice', 'correct-horse', remember: true);
        $this->assertTrue($issued->success);
        $this->assertNotNull($issued->rememberToken);

        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $auth->attemptLogin('alice', 'wrong');
        }

        $result = $auth->loginWithRememberToken($issued->rememberToken);
        $this->assertFalse($result->success);
        $this->assertSame('account_locked', $result->reason);
    }

    public function testUnknownUserReturnsInvalidCredentialsNotLocked(): void
    {
        // Information-leakage guard: locked-status lookup must not happen
        // for non-existent users.
        $result = $this->auth()->attemptLogin('ghost', 'wrong');
        $this->assertSame('invalid_credentials', $result->reason);
    }

    private function auth(): AuthService
    {
        return new AuthService(
            $this->users,
            $this->hasher,
            fn(): int => $this->now,
        );
    }
}
