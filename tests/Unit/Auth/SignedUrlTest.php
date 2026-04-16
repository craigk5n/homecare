<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\SignedUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignedUrl::class)]
final class SignedUrlTest extends TestCase
{
    private const TEST_SECRET = 'test-secret-2026-for-hmac-sha256';

    private SignedUrl $signedUrl;

    protected function setUp(): void
    {
        $this->signedUrl = new SignedUrl(self::TEST_SECRET);
    }

    public function testSignGeneratesValidToken(): void
    {
        $params = ['patient_id' => 1, 'start_date' => '2026-04-01', 'end_date' => '2026-04-30'];
        $ttl = 86400; // 1 day

        $token = $this->signedUrl->sign($params, $ttl);

        $this->assertGreaterThan(0, strlen($token));
        $this->assertStringContainsString('.', $token);
    }

    public function testVerifyAcceptsValidToken(): void
    {
        $params = ['patient_id' => 1, 'start_date' => '2026-04-01'];
        $ttl = 3600;
        $token = $this->signedUrl->sign($params, $ttl);

        $valid = $this->signedUrl->verify($token);

        $this->assertTrue($valid);
    }

    public function testVerifyRejectsInvalidToken(): void
    {
        $invalidToken = 'invalid.token.here';

        $valid = $this->signedUrl->verify($invalidToken);

        $this->assertFalse($valid);
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        $params = ['patient_id' => 1];
        $ttl = -3600; // past
        $token = $this->signedUrl->sign($params, $ttl);

        $valid = $this->signedUrl->verify($token);

        $this->assertFalse($valid);
    }

    public function testGetParamsReturnsDecodedForValidToken(): void
    {
        $params = ['patient_id' => 1, 'start_date' => '2026-04-01', 'custom' => 'value'];
        $ttl = 3600;
        $token = $this->signedUrl->sign($params, $ttl);

        $decoded = $this->signedUrl->getParams($token);

        $this->assertIsArray($decoded);
        $this->assertEquals(1, $decoded['patient_id']);
        $this->assertEquals('2026-04-01', $decoded['start_date']);
        $this->assertEquals('value', $decoded['custom']);
        $this->assertArrayHasKey('exp', $decoded);
        $this->assertGreaterThan(time(), $decoded['exp']);
    }

    public function testGetParamsReturnsNullForInvalidToken(): void
    {
        $decoded = $this->signedUrl->getParams('invalid');

        $this->assertNull($decoded);
    }

    // testInstanceReturnsValidObject moved to tests/Integration/Auth/SignedUrlTest.php
    // because SignedUrl::instance() requires the DB bootstrap stack.
}
