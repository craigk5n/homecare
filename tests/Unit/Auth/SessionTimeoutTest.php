<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\SessionState;
use HomeCare\Auth\SessionTimeout;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SessionTimeoutTest extends TestCase
{
    public function testActiveSessionWithinTimeout(): void
    {
        $timeout = new SessionTimeout(30);
        $now = 1_000_000;
        // 5 minutes ago -- well inside the 30-minute window.
        $this->assertSame(
            SessionState::Active,
            $timeout->evaluate($now - 5 * 60, $now)
        );
    }

    public function testExpiredSession(): void
    {
        $timeout = new SessionTimeout(30);
        $now = 1_000_000;
        // 31 minutes ago -- past the window.
        $this->assertSame(
            SessionState::Expired,
            $timeout->evaluate($now - 31 * 60, $now)
        );
    }

    public function testNullLastActivityIsNewSession(): void
    {
        $timeout = new SessionTimeout(30);
        $this->assertSame(
            SessionState::New,
            $timeout->evaluate(null, 1_000_000)
        );
    }

    public function testExactlyAtBoundaryIsActive(): void
    {
        // now - lastActivity == timeoutSeconds: still active (strict >).
        $timeout = new SessionTimeout(30);
        $now = 1_000_000;
        $this->assertSame(
            SessionState::Active,
            $timeout->evaluate($now - 30 * 60, $now)
        );
    }

    public function testOneSecondPastBoundaryIsExpired(): void
    {
        $timeout = new SessionTimeout(30);
        $now = 1_000_000;
        $this->assertSame(
            SessionState::Expired,
            $timeout->evaluate($now - (30 * 60 + 1), $now)
        );
    }

    public function testDefaultTimeoutIsThirtyMinutes(): void
    {
        // Default constructor uses DEFAULT_TIMEOUT_MINUTES, and getTimeoutSeconds()
        // exposes it in seconds: 30 * 60 = 1800.
        $this->assertSame(30 * 60, (new SessionTimeout())->getTimeoutSeconds());
    }

    public function testCustomTimeoutHonoured(): void
    {
        $timeout = new SessionTimeout(5);
        $now = 1_000_000;
        $this->assertSame(
            SessionState::Active,
            $timeout->evaluate($now - 4 * 60, $now)
        );
        $this->assertSame(
            SessionState::Expired,
            $timeout->evaluate($now - 6 * 60, $now)
        );
    }

    public function testZeroOrNegativeTimeoutRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionTimeout(0);
    }

    public function testNegativeTimeoutRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionTimeout(-5);
    }
}
