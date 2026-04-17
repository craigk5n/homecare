<?php

declare(strict_types=1);

namespace HomeCare\Notification;

use HomeCare\Config\WebhookConfig;

/**
 * Generic webhook implementation of {@see NotificationChannel}.
 *
 * POSTs a JSON envelope to an operator-configured URL. Any consumer
 * that speaks JSON + HMAC-SHA256 (Home Assistant, Slack, Discord,
 * n8n, Zapier, a custom Bash one-liner) plugs in without HomeCare
 * learning about them.
 *
 * Payload shape:
 *     {
 *       "title":      "Medication Reminder",
 *       "body":       "Tobra due in 5 min for Daisy",
 *       "priority":   3,
 *       "tags":       ["pill"],
 *       "timestamp":  1735689600,
 *       "message_id": "hc-webhook-..."
 *     }
 *
 * Signature: every POST carries `X-HomeCare-Signature: sha256=<hex>`
 * computed as HMAC-SHA256 over the raw request body using the
 * shared per-deploy secret from {@see \HomeCare\Auth\SignedUrl::getSecret()}.
 * Receivers verify by recomputing the HMAC with their copy of the
 * secret -- forged payloads fail the equality check.
 *
 * Retries: one initial attempt plus three retries with 1s / 3s / 9s
 * backoff between. Worst-case blocking is bounded (timeout×4 +
 * backoff sum). `CurlHttpClient` owns the per-request timeout;
 * retries give up on the first success or after the last attempt.
 */
final class WebhookChannel implements NotificationChannel
{
    public const NAME = 'webhook';

    /** How many retries follow the initial attempt. */
    public const RETRY_ATTEMPTS = 3;

    /**
     * Backoff seconds BEFORE retry #N (1-indexed). A slow endpoint
     * that needs more than a few seconds to recover should be
     * failing loudly elsewhere -- the cron will still re-enter on
     * the next minute.
     *
     * @var list<int>
     */
    private const BACKOFF_SECONDS = [1, 3, 9];

    /** @var callable(int):void */
    private readonly mixed $sleeper;

    /** @var callable():int */
    private readonly mixed $clock;

    /** @var callable():string */
    private readonly mixed $idFactory;

    public function __construct(
        private readonly WebhookConfig $config,
        #[\SensitiveParameter]
        private readonly string $secret,
        private readonly HttpClient $http,
        ?callable $sleeper = null,
        ?callable $clock = null,
        ?callable $idFactory = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
        $this->clock = $clock ?? static fn(): int => time();
        $this->idFactory = $idFactory
            ?? static fn(): string => 'hc-webhook-' . bin2hex(random_bytes(8));
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isReady(): bool
    {
        return $this->config->isReady() && $this->secret !== '';
    }

    public function send(NotificationMessage $message): bool
    {
        if (!$this->isReady()) {
            return false;
        }

        $payload = [
            'title'      => $message->title,
            'body'       => $message->body,
            'priority'   => $message->priority,
            'tags'       => $message->tags,
            'timestamp'  => $this->now(),
            'message_id' => $this->newId(),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return false;
        }

        $signature = hash_hmac('sha256', $body, $this->secret);
        $headers = [
            'Content-Type'         => 'application/json',
            'X-HomeCare-Signature' => 'sha256=' . $signature,
        ];

        $url = $this->config->getUrl();
        $totalAttempts = self::RETRY_ATTEMPTS + 1;

        for ($attempt = 0; $attempt < $totalAttempts; $attempt++) {
            if ($attempt > 0) {
                $this->sleep(self::BACKOFF_SECONDS[$attempt - 1]);
            }
            if ($this->http->post($url, $body, $headers)) {
                return true;
            }
        }

        return false;
    }

    private function now(): int
    {
        /** @var callable():int $fn */
        $fn = $this->clock;

        return ($fn)();
    }

    private function newId(): string
    {
        /** @var callable():string $fn */
        $fn = $this->idFactory;

        return ($fn)();
    }

    private function sleep(int $seconds): void
    {
        /** @var callable(int):void $fn */
        $fn = $this->sleeper;
        ($fn)($seconds);
    }
}
