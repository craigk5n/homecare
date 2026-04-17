<?php

declare(strict_types=1);

namespace HomeCare\Notification;

use InvalidArgumentException;

/**
 * Transport-agnostic message passed to a {@see NotificationChannel}.
 *
 * Each channel interprets `$recipient` differently -- ntfy reads it as
 * a topic override, email reads it as an RFC-5322 address, webhook
 * ignores it. Callers that don't care (most of them) omit it and let
 * the channel use its configured default.
 *
 * `priority` follows ntfy's 1-5 scale (5 = highest / red-card); other
 * channels map it down to their own severity if needed.
 */
final readonly class NotificationMessage
{
    public const PRIORITY_MIN = 1;
    public const PRIORITY_DEFAULT = 3;
    public const PRIORITY_HIGH = 4;
    public const PRIORITY_MAX = 5;

    /**
     * @param list<string> $tags  short arbitrary labels; channel maps to its
     *                            own concept (ntfy emoji, email subject tag)
     */
    public function __construct(
        public string $title,
        public string $body,
        public int $priority = self::PRIORITY_DEFAULT,
        public array $tags = [],
        public ?string $recipient = null,
    ) {
        if ($priority < self::PRIORITY_MIN || $priority > self::PRIORITY_MAX) {
            throw new InvalidArgumentException(
                "priority must be {$priority} between "
                . self::PRIORITY_MIN . ' and ' . self::PRIORITY_MAX,
            );
        }
    }
}
