<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;

/**
 * Guards the thin procedural wrappers in includes/homecare.php that delegate
 * to HomeCare\Domain\ScheduleCalculator. Existing pages call these as plain
 * functions, so if someone accidentally drops the delegation this test fails.
 *
 * The wrappers are defined at file scope; we include the file once per
 * process. includes/homecare.php is safe to load in isolation: it only
 * pulls in vendor/autoload.php and declares functions -- no DB calls at
 * require-time.
 */
final class LegacyWrapperTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../includes/homecare.php';
    }

    public function testFrequencyToSecondsWrapperDelegates(): void
    {
        $this->assertSame(28800, frequencyToSeconds('8h'));
    }

    public function testCalculateNextDueDateWrapperDelegates(): void
    {
        $this->assertSame(
            '2026-04-13 18:00',
            calculateNextDueDate('2026-04-13 10:00:00', '8h')
        );
    }

    public function testGetIntervalSpecFromFrequencyWrapperDelegates(): void
    {
        $this->assertSame('PT12H', getIntervalSpecFromFrequency('12h'));
        $this->assertNull(getIntervalSpecFromFrequency('30m'));
    }

    public function testCalculateSecondsUntilDueWrapperDelegates(): void
    {
        $twoDaysAgo = date('Y-m-d H:i:s', time() - 2 * 86400);
        $this->assertSame(0, calculateSecondsUntilDue($twoDaysAgo, '1d'));
    }
}
