<?php

declare(strict_types=1);

namespace HomeCare\Notification;

use InvalidArgumentException;

/**
 * Keyed-by-name container of {@see NotificationChannel} instances.
 *
 * The reminder cron and supply-alert path both ask the registry for
 * the default channel list -- today that's just ntfy; after HC-101 /
 * HC-102 it's ntfy + email + webhook, with per-user overrides from
 * `hc_user.notification_channels` (HC-103).
 *
 * `dispatch()` fans the message out to every resolved channel that
 * is `isReady()` and returns the count of channels that accepted
 * the message. Zero-return means "not delivered anywhere" and the
 * caller can log it.
 */
final class ChannelRegistry
{
    /** @var array<string,NotificationChannel> */
    private array $channels = [];

    /** @var list<string> default channel names to try, in order */
    private array $defaults = [];

    public function register(NotificationChannel $channel, bool $isDefault = true): void
    {
        $name = $channel->name();
        $this->channels[$name] = $channel;
        if ($isDefault && !in_array($name, $this->defaults, true)) {
            $this->defaults[] = $name;
        }
    }

    public function get(string $name): NotificationChannel
    {
        if (!isset($this->channels[$name])) {
            throw new InvalidArgumentException("Unknown channel: {$name}");
        }

        return $this->channels[$name];
    }

    /**
     * @return list<string>
     */
    public function defaultChannelNames(): array
    {
        return $this->defaults;
    }

    /**
     * Send $message to every channel in $channelNames (or all defaults
     * when null). Returns the number of channels that accepted the
     * message (ready + returned true from `send`).
     *
     * @param list<string>|null $channelNames
     */
    public function dispatch(NotificationMessage $message, ?array $channelNames = null): int
    {
        $targets = $channelNames ?? $this->defaults;
        $accepted = 0;
        foreach ($targets as $name) {
            $channel = $this->channels[$name] ?? null;
            if ($channel === null || !$channel->isReady()) {
                continue;
            }
            if ($channel->send($message)) {
                $accepted++;
            }
        }

        return $accepted;
    }
}
