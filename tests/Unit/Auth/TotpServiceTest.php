<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\TotpService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;

final class TotpServiceTest extends TestCase
{
    private TotpService $service;

    private Google2FA $google2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google2fa = new Google2FA();
        $this->service = new TotpService($this->google2fa);
    }

    public function testGenerateSecretReturnsBase32String(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertGreaterThanOrEqual(16, strlen($secret));
        // Base32 alphabet: A-Z and 2-7
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testVerifyRoundTripWithCurrentTime(): void
    {
        $secret = $this->service->generateSecret();
        $code = $this->google2fa->getCurrentOtp($secret);

        $this->assertTrue($this->service->verifyCode($secret, $code));
    }

    public function testVerifyAcceptsWithinOneStepWindow(): void
    {
        $secret = $this->service->generateSecret();
        $ts = time();

        // Code from the previous 30-second window still accepted.
        $prev = $this->google2fa->oathTotp($secret, intdiv($ts, 30) - 1);
        $this->assertTrue($this->service->verifyCode($secret, $prev));

        // Code from the next 30-second window still accepted.
        $next = $this->google2fa->oathTotp($secret, intdiv($ts, 30) + 1);
        $this->assertTrue($this->service->verifyCode($secret, $next));
    }

    public function testVerifyRejectsCodeFromTwoStepsAgo(): void
    {
        $secret = $this->service->generateSecret();
        $twoStepsAgo = $this->google2fa->oathTotp($secret, intdiv(time(), 30) - 2);

        $this->assertFalse($this->service->verifyCode($secret, $twoStepsAgo));
    }

    public function testVerifyRejectsShortOrLongCodes(): void
    {
        $secret = $this->service->generateSecret();

        $this->assertFalse($this->service->verifyCode($secret, '12345'));
        $this->assertFalse($this->service->verifyCode($secret, '1234567'));
        $this->assertFalse($this->service->verifyCode($secret, ''));
        $this->assertFalse($this->service->verifyCode($secret, 'abcdef'));
    }

    public function testVerifyRejectsEmptySecret(): void
    {
        $this->assertFalse($this->service->verifyCode('', '000000'));
    }

    public function testVerifyRejectsMalformedSecretWithoutThrowing(): void
    {
        // "!!!!" is not valid Base32; the wrapper catches the underlying
        // exception and returns false.
        $this->assertFalse($this->service->verifyCode('!!!!', '000000'));
    }

    public function testProvisioningUriEmbedsIssuerAndSecret(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $uri = $this->service->provisioningUri($secret, 'alice@example.org', 'HomeCare');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('HomeCare', $uri);
        $this->assertStringContainsString(rawurlencode('alice@example.org'), $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
    }

    public function testGenerateRecoveryCodesProducesRequestedUniqueCount(): void
    {
        $codes = $this->service->generateRecoveryCodes(10);

        $this->assertCount(10, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $code);
        }
    }

    public function testGenerateRecoveryCodesRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generateRecoveryCodes(0);
    }

    public function testRecoveryCodeHashIsCaseAndSeparatorInsensitive(): void
    {
        $this->assertSame(
            TotpService::hashRecoveryCode('ab12cd34ef'),
            TotpService::hashRecoveryCode('AB12-cd34-EF')
        );
    }

    public function testConsumeRecoveryCodeRemovesMatchingHashExactlyOnce(): void
    {
        $code = 'ab12cd34ef';
        $other = 'xy98wv76ut';
        $stored = [
            TotpService::hashRecoveryCode($other),
            TotpService::hashRecoveryCode($code),
            TotpService::hashRecoveryCode($code), // duplicate — only one pop
        ];

        $remaining = $this->service->consumeRecoveryCode($code, $stored);

        $this->assertNotNull($remaining);
        $this->assertCount(2, $remaining);
        // The other code survives, and one copy of the matched hash survives.
        $this->assertSame(TotpService::hashRecoveryCode($other), $remaining[0]);
        $this->assertSame(TotpService::hashRecoveryCode($code), $remaining[1]);
    }

    public function testConsumeRecoveryCodeReturnsNullWhenNoMatch(): void
    {
        $stored = [TotpService::hashRecoveryCode('abc123defg')];

        $this->assertNull($this->service->consumeRecoveryCode('nomatch0xy', $stored));
    }

    public function testRecoveryCodeCannotBeReplayed(): void
    {
        $code = 'ab12cd34ef';
        $stored = [TotpService::hashRecoveryCode($code)];

        $remaining = $this->service->consumeRecoveryCode($code, $stored);
        $this->assertSame([], $remaining);

        // Second attempt returns null (code already consumed).
        $this->assertNull($this->service->consumeRecoveryCode($code, $remaining));
    }

    public function testDecodeStoredRecoveryCodesTolerance(): void
    {
        $this->assertSame([], TotpService::decodeStoredRecoveryCodes(null));
        $this->assertSame([], TotpService::decodeStoredRecoveryCodes(''));
        $this->assertSame([], TotpService::decodeStoredRecoveryCodes('not-json'));
        $this->assertSame(
            ['abc', 'def'],
            TotpService::decodeStoredRecoveryCodes('["abc","def"]')
        );
        // Non-string entries silently dropped.
        $this->assertSame(
            ['abc'],
            TotpService::decodeStoredRecoveryCodes('["abc", 42, null, ""]')
        );
    }
}
