<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Service;

use HomeCare\Service\LateDoseAlertService;
use PHPUnit\Framework\TestCase;

/**
 * Pure decision-function coverage. DB-backed flow is in the
 * Integration/Service counterpart.
 */
final class LateDoseAlertServiceTest extends TestCase
{
    public function testFeatureOffWhenThresholdIsZero(): void
    {
        $this->assertFalse(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 08:00:00',
            frequency: '8h',
            thresholdMinutes: 0,
            lastAlertDueAt: null,
            now: '2026-04-17 08:00:00',
        ));
    }

    public function testFeatureOffWhenThresholdNegative(): void
    {
        $this->assertFalse(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 08:00:00',
            frequency: '8h',
            thresholdMinutes: -10,
            lastAlertDueAt: null,
            now: '2026-04-17 08:00:00',
        ));
    }

    public function testNotLateEnoughWithinThreshold(): void
    {
        // Last at 08:00, 8h frequency → due at 16:00. With 60-min
        // threshold, alert fires at 17:00. At 16:59 we're not ready.
        $this->assertFalse(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 08:00:00',
            frequency: '8h',
            thresholdMinutes: 60,
            lastAlertDueAt: null,
            now: '2026-04-16 16:59:59',
        ));
    }

    public function testBoundaryExactlyAtThreshold(): void
    {
        // 17:00 sharp — due + threshold = now. Criterion is `>= alertAt`.
        $this->assertTrue(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 08:00:00',
            frequency: '8h',
            thresholdMinutes: 60,
            lastAlertDueAt: null,
            now: '2026-04-16 17:00:00',
        ));
    }

    public function testAlertsPastThreshold(): void
    {
        $this->assertTrue(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 08:00:00',
            frequency: '8h',
            thresholdMinutes: 60,
            lastAlertDueAt: null,
            now: '2026-04-16 18:30:00',
        ));
    }

    public function testSuppressesReplayForSameDueInstant(): void
    {
        $this->assertFalse(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 08:00:00',
            frequency: '8h',
            thresholdMinutes: 60,
            // Already alerted about the 16:00 dose.
            lastAlertDueAt: '2026-04-16 16:00:00',
            now: '2026-04-16 18:30:00',
        ));
    }

    public function testNewDueInstantReArmsAlert(): void
    {
        // Caregiver finally logged the 16:00 dose at 19:00; next
        // dose is due at 03:00 the following day. Simulate the
        // NEXT cron tick 2 hours after that = 05:00.
        // lastAlertDueAt is the prior alerted instant.
        $this->assertTrue(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 19:00:00',
            frequency: '8h',
            thresholdMinutes: 60,
            lastAlertDueAt: '2026-04-16 16:00:00',
            now: '2026-04-17 05:00:00',
        ));
    }

    public function testUnparseableFrequencyReturnsFalse(): void
    {
        $this->assertFalse(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 08:00:00',
            frequency: 'nonsense',
            thresholdMinutes: 60,
            lastAlertDueAt: null,
            now: '2026-04-16 18:00:00',
        ));
    }

    public function testUnparseableLastTakenReturnsFalse(): void
    {
        $this->assertFalse(LateDoseAlertService::shouldAlert(
            lastTaken: 'yesterday-ish',
            frequency: '8h',
            thresholdMinutes: 60,
            lastAlertDueAt: null,
            now: '2026-04-16 18:00:00',
        ));
    }

    public function testMinuteScaleFrequencyStillWorks(): void
    {
        // 30-minute frequency. Due 30 min after last taken; with a
        // 15-min threshold, alert fires at +45 min.
        $this->assertTrue(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 08:00:00',
            frequency: '30m',
            thresholdMinutes: 15,
            lastAlertDueAt: null,
            now: '2026-04-16 08:45:00',
        ));
        $this->assertFalse(LateDoseAlertService::shouldAlert(
            lastTaken: '2026-04-16 08:00:00',
            frequency: '30m',
            thresholdMinutes: 15,
            lastAlertDueAt: null,
            now: '2026-04-16 08:44:00',
        ));
    }
}
