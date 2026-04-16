<?php

declare(strict_types=1);

namespace HomeCare\Notification;

use HomeCare\Config\NtfyConfig;

/**
 * Ntfy.sh implementation of {@see NotificationChannel}.
 *
 * Posts a JSON payload to the configured ntfy server; keeps the
 * existing `NtfyConfig` as the source of truth for URL / topic /
 * enabled-flag so HC-041 work stays intact.
 *
 * `send()` short-circuits (and reports success) when the config is
 * not ready -- we treat "disabled" as "delivered nowhere, no error"
 * so callers can iterate a channel list without special-casing.
 */
final class NtfyChannel implements NotificationChannel
{
    public const NAME = 'ntfy';

    public function __construct(
        private readonly NtfyConfig $config,
        private readonly HttpClient $http = new CurlHttpClient(),
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isReady(): bool
    {
        return $this->config->isReady();
    }

    public function send(NotificationMessage $message): bool
    {
        if (!$this->isReady()) {
            return false;
        }

        $topic = $message->recipient ?? $this->config->getTopic();
        if ($topic === '') {
            return false;
        }

        $payload = [
            'topic'    => $topic,
            'title'    => $message->title,
            'message'  => $message->body,
            'priority' => $message->priority,
        ];
        if ($message->tags !== []) {
            $payload['tags'] = $message->tags;
        }

        $body = json_encode($payload);
        if ($body === false) {
            return false;
        }

        return $this->http->post(
            $this->config->getUrl(),
            $body,
            ['Content-Type' => 'application/json']
        );
    }
}
