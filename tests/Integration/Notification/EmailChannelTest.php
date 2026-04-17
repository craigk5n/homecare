<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Notification;

use HomeCare\Config\EmailConfig;
use HomeCare\Database\SqliteDatabase;
use HomeCare\Notification\EmailChannel;
use HomeCare\Notification\NotificationMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Captures every Email handed to Symfony Mailer so we can assert
 * the rendered shape (subject / from / to / body) without running
 * a real SMTP transport.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }
    }
}

final class EmailChannelTest extends TestCase
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
        string $dsn = 'null://default',
        string $from = 'no-reply@homecare.local',
        string $fromName = 'HomeCare',
        bool $enabled = true,
    ): EmailConfig {
        $c = new EmailConfig($this->db);
        $c->setDsn($dsn);
        $c->setFromAddress($from);
        $c->setFromName($fromName);
        $c->setEnabled($enabled);

        return $c;
    }

    /**
     * @return array{0:EmailChannel,1:RecordingMailer}
     */
    private function channelWithRecorder(EmailConfig $config): array
    {
        $mailer = new RecordingMailer();
        $channel = new EmailChannel($config, $mailer);

        return [$channel, $mailer];
    }

    public function testNameIsEmail(): void
    {
        $channel = new EmailChannel($this->config());
        $this->assertSame('email', $channel->name());
    }

    public function testIsReadyFollowsConfig(): void
    {
        $this->assertFalse(
            (new EmailChannel($this->config(enabled: false)))->isReady(),
        );
        $this->assertFalse(
            (new EmailChannel($this->config(dsn: '')))->isReady(),
        );
        $this->assertFalse(
            (new EmailChannel($this->config(from: '')))->isReady(),
        );
        $this->assertTrue(
            (new EmailChannel($this->config()))->isReady(),
        );
    }

    public function testSendReturnsFalseWhenNotReady(): void
    {
        [$channel, $rec] = $this->channelWithRecorder($this->config(enabled: false));

        $ok = $channel->send(new NotificationMessage(
            'subject',
            'body',
            recipient: 'alice@example.org',
        ));

        $this->assertFalse($ok);
        $this->assertSame([], $rec->sent);
    }

    public function testSendReturnsFalseWhenNoRecipient(): void
    {
        [$channel, $rec] = $this->channelWithRecorder($this->config());

        $ok = $channel->send(new NotificationMessage('subject', 'body'));

        $this->assertFalse($ok);
        $this->assertSame([], $rec->sent, 'must not hand off to mailer without recipient');
    }

    public function testSendDeliversMessageThroughMailer(): void
    {
        [$channel, $rec] = $this->channelWithRecorder($this->config(
            from: 'alerts@homecare.local',
            fromName: 'HomeCare Alerts',
        ));

        $ok = $channel->send(new NotificationMessage(
            title: 'Medication Reminder',
            body: 'Tobra due in 5 min',
            recipient: 'caregiver@example.org',
        ));

        $this->assertTrue($ok);
        $this->assertCount(1, $rec->sent);

        $email = $rec->sent[0];
        $this->assertSame('Medication Reminder', $email->getSubject());
        $this->assertSame('Tobra due in 5 min', $email->getTextBody());

        $from = $email->getFrom()[0];
        $this->assertSame('alerts@homecare.local', $from->getAddress());
        $this->assertSame('HomeCare Alerts', $from->getName());

        $to = $email->getTo()[0];
        $this->assertSame('caregiver@example.org', $to->getAddress());
    }

    public function testHighPriorityAddsUrgentPrefix(): void
    {
        [$channel, $rec] = $this->channelWithRecorder($this->config());

        $channel->send(new NotificationMessage(
            title: 'Low Supply',
            body: 'ran out',
            priority: NotificationMessage::PRIORITY_HIGH,
            recipient: 'x@example.org',
        ));

        $this->assertStringStartsWith('[URGENT] ', (string) $rec->sent[0]->getSubject());
    }

    public function testTagsAppearInSubject(): void
    {
        [$channel, $rec] = $this->channelWithRecorder($this->config());

        $channel->send(new NotificationMessage(
            title: 'Reminder',
            body: 'body',
            tags: ['pill', 'daisy'],
            recipient: 'x@example.org',
        ));

        $this->assertStringContainsString('[pill,daisy]', (string) $rec->sent[0]->getSubject());
    }

    public function testSendCatchesTransportExceptions(): void
    {
        $config = $this->config();
        // Force a broken transport by constructing with a bogus DSN.
        // Don't pass a mailer — let the channel build one from DSN.
        $config->setDsn('smtp://definitely-not-a-real-host.invalid:1');

        $channel = new EmailChannel($config);

        // Send should not throw — only return false.
        $ok = @$channel->send(new NotificationMessage(
            'subject',
            'body',
            recipient: 'alice@example.org',
        ));

        $this->assertFalse($ok);
    }
}
