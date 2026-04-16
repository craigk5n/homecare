<?php

declare(strict_types=1);

namespace HomeCare\Notification;

/**
 * Contract every outbound notification surface implements.
 *
 * Today: ntfy only (HC-100). Email (HC-101) and webhooks (HC-102)
 * plug in behind this interface so `send_reminders.php` and the
 * supply-alert path don't grow a new branch each time.
 *
 * Implementations MUST be side-effect-free when `isReady()` returns
 * false -- reminder cron runs every minute, and a misconfigured
 * channel shouldn't spam the log.
 */
interface NotificationChannel
{
    /**
     * Short machine-readable name, e.g. 'ntfy' / 'email' / 'webhook'.
     * Used as the registry key.
     */
    public function name(): string;

    /**
     * True when the channel has enough configuration to actually send.
     * Callers short-circuit on false rather than handing the message
     * to an unready channel.
     */
    public function isReady(): bool;

    /**
     * Deliver the message. Returns true on apparent success.
     *
     * "Apparent" because most channels are fire-and-forget over HTTP
     * or SMTP; a transport error that arrives after handoff still
     * produces a `true` return. Implementations MUST NOT throw on
     * transport failures -- reminder cron keeps running even when
     * one channel is sick.
     */
    public function send(NotificationMessage $message): bool;
}
