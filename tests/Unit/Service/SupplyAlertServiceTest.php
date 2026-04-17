<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Service;

use HomeCare\Service\SupplyAlertService;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage for the `shouldAlert()` decision table. No DB,
 * no mocks -- it's a static function with five inputs and a boolean out.
 */
final class SupplyAlertServiceTest extends TestCase
{
    public function testAboveThresholdDoesNotAlert(): void
    {
        $this->assertFalse(
            SupplyAlertService::shouldAlert(
                remainingDays: 10,
                thresholdDays: 7,
                lastSentAt: null,
                now: '2026-04-14 12:00:00',
            ),
        );
    }

    public function testAtThresholdAlerts(): void
    {
        // remainingDays === thresholdDays → alert (<=, not strictly <)
        $this->assertTrue(
            SupplyAlertService::shouldAlert(
                remainingDays: 7,
                thresholdDays: 7,
                lastSentAt: null,
                now: '2026-04-14 12:00:00',
            ),
        );
    }

    public function testBelowThresholdAlerts(): void
    {
        $this->assertTrue(
            SupplyAlertService::shouldAlert(
                remainingDays: 2,
                thresholdDays: 7,
                lastSentAt: null,
                now: '2026-04-14 12:00:00',
            ),
        );
    }

    public function testZeroDaysAlerts(): void
    {
        $this->assertTrue(
            SupplyAlertService::shouldAlert(
                remainingDays: 0,
                thresholdDays: 7,
                lastSentAt: null,
                now: '2026-04-14 12:00:00',
            ),
        );
    }

    public function testRecentAlertSuppressesRepeat(): void
    {
        // Last sent 6 hours ago; throttle is 24h; no new alert.
        $this->assertFalse(
            SupplyAlertService::shouldAlert(
                remainingDays: 2,
                thresholdDays: 7,
                lastSentAt: '2026-04-14 06:00:00',
                now: '2026-04-14 12:00:00',
            ),
        );
    }

    public function testOldAlertAllowsNewOne(): void
    {
        // Last sent 25 hours ago; throttle expired.
        $this->assertTrue(
            SupplyAlertService::shouldAlert(
                remainingDays: 2,
                thresholdDays: 7,
                lastSentAt: '2026-04-13 10:00:00',
                now: '2026-04-14 12:00:00',
            ),
        );
    }

    public function testCustomThrottleWindow(): void
    {
        // 2 hours ago with a 1-hour throttle: allowed.
        $this->assertTrue(
            SupplyAlertService::shouldAlert(
                remainingDays: 2,
                thresholdDays: 7,
                lastSentAt: '2026-04-14 10:00:00',
                now: '2026-04-14 12:00:00',
                throttleSeconds: 3600,
            ),
        );
        // 2 hours ago with a 3-hour throttle: suppressed.
        $this->assertFalse(
            SupplyAlertService::shouldAlert(
                remainingDays: 2,
                thresholdDays: 7,
                lastSentAt: '2026-04-14 10:00:00',
                now: '2026-04-14 12:00:00',
                throttleSeconds: 3 * 3600,
            ),
        );
    }

    public function testUnparseableTimestampFailsOpen(): void
    {
        // Better an accidental duplicate alert than silent loss.
        $this->assertTrue(
            SupplyAlertService::shouldAlert(
                remainingDays: 2,
                thresholdDays: 7,
                lastSentAt: 'not-a-date',
                now: '2026-04-14 12:00:00',
            ),
        );
    }

    public function testExactBoundaryIsSuppressed(): void
    {
        // Exactly 24 hours ago with the default throttle: the >= check
        // means this counts as "fresh enough to allow", so alerts.
        $this->assertTrue(
            SupplyAlertService::shouldAlert(
                remainingDays: 2,
                thresholdDays: 7,
                lastSentAt: '2026-04-13 12:00:00',
                now: '2026-04-14 12:00:00',
            ),
        );
        // One second under 24h: suppressed.
        $this->assertFalse(
            SupplyAlertService::shouldAlert(
                remainingDays: 2,
                thresholdDays: 7,
                lastSentAt: '2026-04-13 12:00:01',
                now: '2026-04-14 12:00:00',
            ),
        );
    }
}
