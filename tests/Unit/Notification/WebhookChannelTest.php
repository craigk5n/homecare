<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Notification;

use HomeCare\Config\WebhookConfig;
use HomeCare\Database\SqliteDatabase;
use HomeCare\Notification\HttpClient;
use HomeCare\Notification\NotificationMessage;
use HomeCare\Notification\WebhookChannel;
use PHPUnit\Framework\TestCase;

/**
 * Tiny http-client recorder parameterised on a sequence of return
 * codes. `[true]` means the first attempt succeeds; `[false, true]`
 * means one retry; `[false, false, false, false]` exhausts all
 * four attempts.
 *
 * @internal
 */
final class ProgrammableHttpClient implements HttpClient
{
    /** @var list<array{url:string,body:string,headers:array<string,string>}> */
    public array $calls = [];

    /**
     * @param list<bool> $responses
     */
    public function __construct(private array $responses) {}

    public function post(string $url, string $body, array $headers): bool
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
        $i = count($this->calls) - 1;

        return $this->responses[$i] ?? false;
    }
}

final class WebhookChannelTest extends TestCase
{
    private SqliteDatabase $db;

    /** @var list<int> recorded sleep durations */
    private array $sleeps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new SqliteDatabase();
        $this->db->pdo()->exec(
            'CREATE TABLE hc_config (setting VARCHAR(50) PRIMARY KEY, value VARCHAR(128))',
        );
        $this->sleeps = [];
    }

    private function config(
        string $url = 'https://hook.test/homecare',
        bool $enabled = true,
    ): WebhookConfig {
        $c = new WebhookConfig($this->db);
        $c->setUrl($url);
        $c->setEnabled($enabled);

        return $c;
    }

    /**
     * @param list<bool> $responses
     *
     * @return array{0:WebhookChannel,1:ProgrammableHttpClient}
     */
    private function channel(
        array $responses = [true],
        ?WebhookConfig $config = null,
        string $secret = 'shared-secret',
    ): array {
        $http = new ProgrammableHttpClient($responses);
        $channel = new WebhookChannel(
            config: $config ?? $this->config(),
            secret: $secret,
            http: $http,
            sleeper: function (int $s): void {
                $this->sleeps[] = $s;
            },
            clock: static fn(): int => 1_735_689_600,
            idFactory: static fn(): string => 'hc-webhook-deadbeef',
        );

        return [$channel, $http];
    }

    public function testNameIsWebhook(): void
    {
        [$channel] = $this->channel();
        $this->assertSame('webhook', $channel->name());
    }

    public function testIsReadyFollowsConfigAndSecret(): void
    {
        [$cDisabled] = $this->channel(config: $this->config(enabled: false));
        $this->assertFalse($cDisabled->isReady());

        [$cNoUrl] = $this->channel(config: $this->config(url: ''));
        $this->assertFalse($cNoUrl->isReady());

        [$cNoSecret] = $this->channel(secret: '');
        $this->assertFalse($cNoSecret->isReady());

        [$cOk] = $this->channel();
        $this->assertTrue($cOk->isReady());
    }

    public function testSendShortCircuitsWhenNotReady(): void
    {
        [$channel, $http] = $this->channel(config: $this->config(enabled: false));

        $this->assertFalse($channel->send(new NotificationMessage('t', 'b')));
        $this->assertSame([], $http->calls);
        $this->assertSame([], $this->sleeps);
    }

    public function testSuccessfulFirstAttemptSkipsRetries(): void
    {
        [$channel, $http] = $this->channel(responses: [true]);

        $this->assertTrue($channel->send(new NotificationMessage('t', 'b')));
        $this->assertCount(1, $http->calls);
        $this->assertSame([], $this->sleeps, 'no backoff on first-try success');
    }

    public function testPayloadShapeMatchesSpec(): void
    {
        [$channel, $http] = $this->channel();

        $channel->send(new NotificationMessage(
            title: 'Medication Reminder',
            body: 'Tobra due in 5 min',
            priority: NotificationMessage::PRIORITY_HIGH,
            tags: ['pill', 'daisy'],
        ));

        $payload = json_decode($http->calls[0]['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame('Medication Reminder', $payload['title']);
        $this->assertSame('Tobra due in 5 min', $payload['body']);
        $this->assertSame(4, $payload['priority']);
        $this->assertSame(['pill', 'daisy'], $payload['tags']);
        $this->assertSame(1_735_689_600, $payload['timestamp']);
        $this->assertSame('hc-webhook-deadbeef', $payload['message_id']);
    }

    public function testSignatureHeaderIsHmacSha256OfBody(): void
    {
        [$channel, $http] = $this->channel(secret: 'shared-secret');

        $channel->send(new NotificationMessage('t', 'b'));

        $call = $http->calls[0];
        $expected = 'sha256=' . hash_hmac('sha256', $call['body'], 'shared-secret');
        $this->assertSame($expected, $call['headers']['X-HomeCare-Signature']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);
    }

    public function testRetriesWithExponentialBackoff(): void
    {
        [$channel, $http] = $this->channel(responses: [false, false, true]);

        $this->assertTrue($channel->send(new NotificationMessage('t', 'b')));
        $this->assertCount(3, $http->calls, '1 original + 2 retries');
        $this->assertSame([1, 3], $this->sleeps, 'backoff between retries 1 and 2');
    }

    public function testGivesUpAfterFourAttempts(): void
    {
        [$channel, $http] = $this->channel(responses: [false, false, false, false]);

        $this->assertFalse($channel->send(new NotificationMessage('t', 'b')));
        $this->assertCount(4, $http->calls, '1 original + 3 retries');
        $this->assertSame([1, 3, 9], $this->sleeps);
    }

    public function testSignatureIsStableAcrossRetries(): void
    {
        // Same body → same signature on every retry. A receiver that
        // idempotently processes by message_id MUST see consistent
        // bytes across attempts.
        [$channel, $http] = $this->channel(responses: [false, true]);

        $channel->send(new NotificationMessage('t', 'b'));

        $this->assertCount(2, $http->calls);
        $this->assertSame(
            $http->calls[0]['headers']['X-HomeCare-Signature'],
            $http->calls[1]['headers']['X-HomeCare-Signature'],
        );
        $this->assertSame($http->calls[0]['body'], $http->calls[1]['body']);
    }

    public function testEmptyTagsStillEmitsArray(): void
    {
        // Consumers should see `"tags": []`, not missing key, so the
        // payload shape is stable.
        [$channel, $http] = $this->channel();

        $channel->send(new NotificationMessage('t', 'b'));

        $payload = json_decode($http->calls[0]['body'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('tags', $payload);
        $this->assertSame([], $payload['tags']);
    }
}
