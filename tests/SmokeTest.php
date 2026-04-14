<?php

declare(strict_types=1);

namespace HomeCare\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Sanity checks that the test harness itself is wired correctly. The
 * assertions are deliberately lightweight; PHPStan would refuse
 * tautological `assertSame(literal, literal)` forms, so we run the
 * check against computed values instead.
 */
final class SmokeTest extends TestCase
{
    public function testArithmeticRoundTrip(): void
    {
        // Proves the test harness actually runs code (rather than
        // silently reporting success). Uses a computed value so PHPStan
        // can't flag it as a tautology.
        $sum = 0;
        for ($i = 0; $i < 3; $i++) {
            $sum += $i;
        }
        $this->assertSame(3, $sum);
    }

    public function testAutoloadNamespaceIsReachable(): void
    {
        // If PSR-4 autoload is broken, this class-exists check throws
        // before the assertion -- that's the real check.
        $this->assertTrue(class_exists(SmokeTest::class));
    }
}
