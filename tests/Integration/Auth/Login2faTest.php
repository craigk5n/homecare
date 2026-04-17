<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\AuthResult;
use HomeCare\Auth\AuthService;
use HomeCare\Auth\PasswordHasher;
use HomeCare\Auth\TotpService;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;
use PragmaRX\Google2FA\Google2FA;

/**
 * End-to-end 2FA flow: password → requires_totp → verify → authenticated.
 *
 * Works against the real SQLite schema, TotpService, and UserRepository
 * -- catches any drift between the layers that unit tests with mocks
 * might miss.
 */
final class Login2faTest extends DatabaseTestCase
{
    private AuthService $auth;

    private UserRepository $users;

    private PasswordHasher $hasher;

    private TotpService $totp;

    private Google2FA $google2fa;

    private string $secret;

    /** @var list<string> */
    private array $recoveryCodes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->getDb());
        $this->hasher = new PasswordHasher();
        $this->google2fa = new Google2FA();
        $this->totp = new TotpService($this->google2fa);
        $this->auth = new AuthService($this->users, $this->hasher, totp: $this->totp);

        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, is_admin, role, enabled)
             VALUES (?, ?, 'N', 'caregiver', 'Y')",
            ['alice', $this->hasher->hash('correct-horse')],
        );

        $this->secret = $this->totp->generateSecret();
        $this->recoveryCodes = $this->totp->generateRecoveryCodes(5);
        $hashes = array_map(
            static fn(string $c): string => TotpService::hashRecoveryCode($c),
            $this->recoveryCodes,
        );

        $this->users->setTotpSecret('alice', $this->secret);
        $this->users->enableTotp('alice', $hashes);
    }

    public function testPasswordSuccessReturnsRequiresTotp(): void
    {
        $result = $this->auth->attemptLogin('alice', 'correct-horse');

        $this->assertFalse($result->success);
        $this->assertTrue($result->isTotpRequired());
        $this->assertNotNull($result->user);
        $this->assertSame('alice', $result->user['login']);
        $this->assertNull($result->rememberToken, 'must not mint remember-me before TOTP');
    }

    public function testVerifyTotpWithValidCodeCompletesLogin(): void
    {
        $this->auth->attemptLogin('alice', 'correct-horse');
        $code = $this->google2fa->getCurrentOtp($this->secret);

        $result = $this->auth->verifyTotp('alice', $code);

        $this->assertTrue($result->success, (string) $result->reason);
        $this->assertFalse($result->usedRecoveryCode);
    }

    public function testVerifyTotpRejectsInvalidCode(): void
    {
        $result = $this->auth->verifyTotp('alice', '000000');

        $this->assertFalse($result->success);
        $this->assertSame('invalid_totp', $result->reason);
    }

    public function testRecoveryCodeCompletesLoginAndIsSingleUse(): void
    {
        $code = $this->recoveryCodes[0];

        $first = $this->auth->verifyTotp('alice', $code);
        $this->assertTrue($first->success, (string) $first->reason);
        $this->assertTrue($first->usedRecoveryCode);

        // Same code cannot be replayed.
        $second = $this->auth->verifyTotp('alice', $code);
        $this->assertFalse($second->success);
        $this->assertSame('invalid_totp', $second->reason);
    }

    public function testUnusedRecoveryCodesSurviveConsumption(): void
    {
        $used = $this->recoveryCodes[0];
        $unused = $this->recoveryCodes[1];

        $this->auth->verifyTotp('alice', $used);

        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $stored = TotpService::decodeStoredRecoveryCodes($row['totp_recovery_codes']);
        $this->assertCount(4, $stored);
        $this->assertContains(TotpService::hashRecoveryCode($unused), $stored);
        $this->assertNotContains(TotpService::hashRecoveryCode($used), $stored);
    }

    public function testRememberMeCookieIssuedOnlyAfterTotp(): void
    {
        // Password step with remember=true MUST NOT mint a token.
        $pw = $this->auth->attemptLogin('alice', 'correct-horse', remember: true);
        $this->assertTrue($pw->isTotpRequired());
        $this->assertNull($pw->rememberToken);

        // TOTP step with remember=true mints the token.
        $code = $this->google2fa->getCurrentOtp($this->secret);
        $verify = $this->auth->verifyTotp('alice', $code, remember: true);

        $this->assertTrue($verify->success);
        $this->assertNotNull($verify->rememberToken);
        $this->assertNotNull($verify->rememberExpires);
    }

    public function testRememberMeCookieCannotBypassTotp(): void
    {
        // Mint a token via a full login.
        $pw = $this->auth->attemptLogin('alice', 'correct-horse', remember: true);
        $this->assertTrue($pw->isTotpRequired());
        $code = $this->google2fa->getCurrentOtp($this->secret);
        $verified = $this->auth->verifyTotp('alice', $code, remember: true);
        $this->assertTrue($verified->success);
        $this->assertNotNull($verified->rememberToken);

        // The next request arrives with just the cookie — still forced
        // through TOTP (defense-in-depth: lost device should mean lost
        // access even with a stolen remember cookie).
        $result = $this->auth->loginWithRememberToken($verified->rememberToken);

        $this->assertFalse($result->success);
        $this->assertTrue($result->isTotpRequired());
    }

    public function testFailedTotpCodeIncrementsLockoutCounter(): void
    {
        for ($i = 0; $i < AuthService::MAX_FAILED_ATTEMPTS - 1; $i++) {
            $r = $this->auth->verifyTotp('alice', '000000');
            $this->assertSame('invalid_totp', $r->reason);
        }

        // The fifth failure triggers lockout; subsequent attempts fail
        // with account_locked (even with a valid code).
        $this->auth->verifyTotp('alice', '000000');
        $validCode = $this->google2fa->getCurrentOtp($this->secret);
        $r = $this->auth->verifyTotp('alice', $validCode);

        $this->assertFalse($r->success);
        $this->assertSame('account_locked', $r->reason);
    }

    public function testVerifyTotpRefusesAccountWithoutTotpEnabled(): void
    {
        // Add a second user without 2FA.
        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, is_admin, role, enabled)
             VALUES (?, ?, 'N', 'caregiver', 'Y')",
            ['bob', $this->hasher->hash('pw')],
        );

        $result = $this->auth->verifyTotp('bob', '000000');

        $this->assertFalse($result->success);
        $this->assertSame('invalid_credentials', $result->reason);
    }

    public function testAuthResultFactoryRoundTrip(): void
    {
        $user = $this->users->findByLogin('alice');
        $this->assertNotNull($user);

        $pending = AuthResult::requiresTotp($user);

        $this->assertTrue($pending->isTotpRequired());
        $this->assertSame('alice', $pending->user['login'] ?? null);
    }
}
