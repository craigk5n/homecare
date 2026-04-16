<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Http;

use HomeCare\Http\SessionCookieParams;
use PHPUnit\Framework\TestCase;

final class SessionCookieParamsTest extends TestCase
{
    public function testDefaultsApplyOverPlainHttp(): void
    {
        $p = SessionCookieParams::forRequest([]);

        $this->assertSame(0,      $p['lifetime']);
        $this->assertSame('/',    $p['path']);
        $this->assertFalse($p['secure']);
        $this->assertTrue($p['httponly']);
        $this->assertSame('Lax',  $p['samesite']);
    }

    public function testSecureTrueWhenHttpsOn(): void
    {
        $this->assertTrue(
            SessionCookieParams::forRequest(['HTTPS' => 'on'])['secure']
        );
        $this->assertTrue(
            SessionCookieParams::forRequest(['HTTPS' => '1'])['secure']
        );
    }

    public function testSecureFalseWhenHttpsExplicitlyOff(): void
    {
        // IIS emits 'off' literally for non-HTTPS requests.
        $this->assertFalse(
            SessionCookieParams::forRequest(['HTTPS' => 'off'])['secure']
        );
        $this->assertFalse(
            SessionCookieParams::forRequest(['HTTPS' => 'OFF'])['secure']
        );
        $this->assertFalse(
            SessionCookieParams::forRequest(['HTTPS' => ''])['secure']
        );
    }

    public function testSecureTrueWhenForwardedProtoHttps(): void
    {
        // Apache behind an HTTPS terminator: the HTTPS flag isn't set
        // on the PHP side but X-Forwarded-Proto reveals the original.
        $p = SessionCookieParams::forRequest([
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertTrue($p['secure']);
    }

    public function testForwardedProtoHttpDoesNotFlipSecure(): void
    {
        $p = SessionCookieParams::forRequest([
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ]);

        $this->assertFalse($p['secure']);
    }

    public function testCaseInsensitiveForwardedProto(): void
    {
        $p = SessionCookieParams::forRequest([
            'HTTP_X_FORWARDED_PROTO' => 'HTTPS',
        ]);

        $this->assertTrue($p['secure']);
    }
}
