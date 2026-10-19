<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Service;

use HomeCare\Notification\NotificationMessage;
use HomeCare\Service\PrnLimitAlert;
use HomeCare\Service\PrnLimitAlertService;
use PHPUnit\Framework\TestCase;

/**
 * Pure decision-function + value-object coverage for HC-238. The
 * DB-backed walk is in the Integration/Service counterpart.
 */
final class PrnLimitAlertServiceTest extends TestCase
{
    public function testNoAlertBelowLimit(): void
    {
        $this->assertFalse(PrnLimitAlertService::shouldAlert(
            level: null,
            lastDoseAt: '2026-04-16 12:00:00',
            lastAlertDoseAt: null,
            lastAlertLevel: null,
        ));
    }

    public function testFirstTimeReachingLimitAlerts(): void
    {
        $this->assertTrue(PrnLimitAlertService::shouldAlert(
            level: 'reached',
            lastDoseAt: '2026-04-16 12:00:00',
            lastAlertDoseAt: null,
            lastAlertLevel: null,
        ));
    }

    public function testSameDoseSameLevelIsSuppressed(): void
    {
        // Cron ticks every minute; the window is unchanged.
        $this->assertFalse(PrnLimitAlertService::shouldAlert(
            level: 'reached',
            lastDoseAt: '2026-04-16 12:00:00',
            lastAlertDoseAt: '2026-04-16 12:00:00',
            lastAlertLevel: 'reached',
        ));
    }

    public function testEscalationFromReachedToExceededAlertsAgain(): void
    {
        $this->assertTrue(PrnLimitAlertService::shouldAlert(
            level: 'exceeded',
            lastDoseAt: '2026-04-16 15:00:00',
            lastAlertDoseAt: '2026-04-16 12:00:00',
            lastAlertLevel: 'reached',
        ));
    }

    public function testFurtherOverLimitDosesInSameEpisodeAreSuppressed(): void
    {
        $this->assertFalse(PrnLimitAlertService::shouldAlert(
            level: 'exceeded',
            lastDoseAt: '2026-04-16 18:00:00',
            lastAlertDoseAt: '2026-04-16 15:00:00',
            lastAlertLevel: 'exceeded',
        ));
    }

    public function testDropBackToReachedAfterExceededIsSuppressed(): void
    {
        $this->assertFalse(PrnLimitAlertService::shouldAlert(
            level: 'reached',
            lastDoseAt: '2026-04-16 18:00:00',
            lastAlertDoseAt: '2026-04-16 15:00:00',
            lastAlertLevel: 'exceeded',
        ));
    }

    public function testNewEpisode24hLaterReArms(): void
    {
        $this->assertTrue(PrnLimitAlertService::shouldAlert(
            level: 'reached',
            lastDoseAt: '2026-04-17 12:00:00',
            lastAlertDoseAt: '2026-04-16 12:00:00',
            lastAlertLevel: 'exceeded',
        ));
    }

    public function testJustUnder24hLaterStillSameEpisode(): void
    {
        $this->assertFalse(PrnLimitAlertService::shouldAlert(
            level: 'reached',
            lastDoseAt: '2026-04-17 11:59:59',
            lastAlertDoseAt: '2026-04-16 12:00:00',
            lastAlertLevel: 'reached',
        ));
    }

    public function testUnparseableLogTimestampFailsOpen(): void
    {
        $this->assertTrue(PrnLimitAlertService::shouldAlert(
            level: 'reached',
            lastDoseAt: '2026-04-16 12:00:00',
            lastAlertDoseAt: 'garbage',
            lastAlertLevel: 'reached',
        ));
    }

    public function testReachedAlertMessage(): void
    {
        $alert = new PrnLimitAlert(
            scheduleId: 7,
            medicineName: 'Gabapentin',
            patientName: 'Daisy',
            level: 'reached',
            dosesInWindow: 3,
            maxDoses: 3,
            lastDoseAt: '2026-04-16 14:05:00',
            nextAllowedAt: '2026-04-17 08:00:00',
        );

        $this->assertSame('PRN limit reached: Gabapentin', $alert->title());
        $this->assertSame(NotificationMessage::PRIORITY_DEFAULT, $alert->priority());
        $this->assertSame(
            'Gabapentin for Daisy: 3 of 3 doses in the last 24 hours (last at 14:05). '
            . 'Next dose allowed at 2026-04-17 08:00.',
            $alert->message(),
        );
    }

    public function testExceededAlertMessageIsHighPriority(): void
    {
        $alert = new PrnLimitAlert(
            scheduleId: 7,
            medicineName: 'Gabapentin',
            patientName: 'Daisy',
            level: 'exceeded',
            dosesInWindow: 4,
            maxDoses: 3,
            lastDoseAt: '2026-04-16 15:00:00',
            nextAllowedAt: null,
        );

        $this->assertSame('PRN limit exceeded: Gabapentin', $alert->title());
        $this->assertSame(NotificationMessage::PRIORITY_HIGH, $alert->priority());
        $this->assertSame(
            'Gabapentin for Daisy: 4 doses in the last 24 hours, over the limit of 3 (last at 15:00).',
            $alert->message(),
        );
    }
}
