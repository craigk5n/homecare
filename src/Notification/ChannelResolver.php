<?php

declare(strict_types=1);

namespace HomeCare\Notification;

/**
 * Resolve which channel names should receive a notification for a
 * given user, combining three sources in priority order:
 *
 *   1. User-level preference (`hc_user.notification_channels`) —
 *      non-empty JSON array overrides the system default.
 *   2. Registry default list (HC-100) — applied when the user
 *      has no preference set.
 *   3. `isReady()` — channels that haven't been configured by an
 *      admin are dropped from whatever list we end up with, so a
 *      caregiver opting into "email" without SMTP wired won't
 *      block ntfy delivery.
 *
 * Pure: no DB access, no side effects. Takes the raw JSON string
 * off `UserRecord.notification_channels` so the caller doesn't
 * have to know how the column is encoded.
 */
final class ChannelResolver
{
    public function __construct(private readonly ChannelRegistry $registry)
    {
    }

    /**
     * @return list<string> channel names, filtered to the ones
     *                      that are registered AND `isReady()`.
     */
    public function resolveFor(string $notificationChannelsJson): array
    {
        $preferred = self::decodePreference($notificationChannelsJson);
        $candidates = $preferred === []
            ? $this->registry->defaultChannelNames()
            : $preferred;

        $out = [];
        foreach ($candidates as $name) {
            try {
                $channel = $this->registry->get($name);
            } catch (\InvalidArgumentException) {
                // Stale preference referencing a deleted channel.
                continue;
            }
            if ($channel->isReady()) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function decodePreference(string $json): array
    {
        if ($json === '' || $json === '[]') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }
}
