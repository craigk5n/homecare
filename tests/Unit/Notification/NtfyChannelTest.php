<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Notification;

use HomeCare\Config\NtfyConfig;
use HomeCare\Database\SqliteDatabase;
use HomeCare\Notification\HttpClient;
use HomeCare\Notification\NotificationMessage;
use HomeCare\Notification\NtfyChannel;
use PHPUnit\Framework\TestCase;

/**
 * Records every HttpClient::post() call so assertions can inspect
 * shape. Returns true (success) by default; tests override via
 * the constructor when they need a failure path.
 */
final class RecordingHttpClient implements HttpClient
{
    /** @var list<array{url:string,body:string,headers:array<string,string>}> */
    public array $calls = [];

    public function __construct(private readonly bool $succeeds = true) {}

    public function post(string $url, string $body, array $headers): bool
    {
        $this->calls[] = [
            'url' => $url,
            'body' => $body,
            'headers' => $headers,
        ];

        return $this->succeeds;
    }
}

final class NtfyChannelTest extends TestCase
{
    private SqliteDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new SqliteDatabase();
        $this->db->pdo()->exec(
            'CREATE TABLE hc_config (setting VARCHAR(50) PRIMARY KEY, value VARCHAR(128))',
        );
    }

    private function config(
        string $url = 'https://ntfy.test/',
        string $topic = 'home',
        bool $enabled = true,
    ): NtfyConfig {
        $cfg = new NtfyConfig($this->db);
        $cfg->setUrl($url);
        $cfg->setTopic($topic);
        $cfg->setEnabled($enabled);

        return $cfg;
    }

    public function testNameIsNtfy(): void
    {
        $http = new RecordingHttpClient();
        $channel = new NtfyChannel($this->config(), $http);

        $this->assertSame('ntfy', $channel->name());
    }

    public function testIsReadyFollowsConfig(): void
    {
        $http = new RecordingHttpClient();

        $this->assertTrue(
            (new NtfyChannel($this->config(enabled: true), $http))->isReady(),
        );
        $this->assertFalse(
            (new NtfyChannel($this->config(enabled: false), $http))->isReady(),
        );
        $this->assertFalse(
            (new NtfyChannel($this->config(topic: ''), $http))->isReady(),
        );
    }

    public function testSendShortCircuitsWhenNotReady(): void
    {
        $http = new RecordingHttpClient();
        $channel = new NtfyChannel($this->config(enabled: false), $http);

        $this->assertFalse($channel->send(
            new NotificationMessage('t', 'b'),
        ));
        $this->assertSame([], $http->calls, 'http client must not be called');
    }

    public function testSendPostsJsonPayloadToConfiguredUrl(): void
    {
        $http = new RecordingHttpClient();
        $channel = new NtfyChannel($this->config(
            url: 'https://ntfy.home.lan/',
            topic: 'patient-daisy',
        ), $http);

        $ok = $channel->send(new NotificationMessage(
            title: 'Medication Reminder',
            body: 'Tobra due in 5 min',
            priority: NotificationMessage::PRIORITY_HIGH,
            tags: ['pill'],
        ));

        $this->assertTrue($ok);
        $this->assertCount(1, $http->calls);

        $call = $http->calls[0];
        $this->assertSame('https://ntfy.home.lan/', $call['url']);
        $this->assertSame('application/json', $call['headers']['Content-Type']);

        $payload = json_decode($call['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame('patient-daisy', $payload['topic']);
        $this->assertSame('Medication Reminder', $payload['title']);
        $this->assertSame('Tobra due in 5 min', $payload['message']);
        $this->assertSame(4, $payload['priority']);
        $this->assertSame(['pill'], $payload['tags']);
    }

    public function testRecipientOverridesConfiguredTopic(): void
    {
        $http = new RecordingHttpClient();
        $channel = new NtfyChannel($this->config(topic: 'default'), $http);

        $channel->send(new NotificationMessage(
            title: 't',
            body: 'b',
            recipient: 'override',
        ));

        $payload = json_decode($http->calls[0]['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame('override', $payload['topic']);
    }

    public function testSendPropagatesHttpFailure(): void
    {
        $http = new RecordingHttpClient(succeeds: false);
        $channel = new NtfyChannel($this->config(), $http);

        $this->assertFalse($channel->send(new NotificationMessage('t', 'b')));
    }

    public function testTagsOmittedWhenEmpty(): void
    {
        $http = new RecordingHttpClient();
        $channel = new NtfyChannel($this->config(), $http);

        $channel->send(new NotificationMessage('t', 'b'));

        $payload = json_decode($http->calls[0]['body'], true);
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('tags', $payload);
    }
}
