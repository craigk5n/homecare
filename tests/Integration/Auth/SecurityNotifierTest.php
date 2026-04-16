<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\PasswordHasher;
use HomeCare\Auth\SecurityNotifier;
use HomeCare\Notification\NotificationChannel;
use HomeCare\Notification\NotificationMessage;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class SilentChannel implements NotificationChannel
{
    /** @var list<NotificationMessage> */
    public array $sent = [];

    public function __construct(public bool $ready = true)
    {
    }

    public function name(): string
    {
        return 'silent';
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function send(NotificationMessage $message): bool
    {
        $this->sent[] = $message;

        return true;
    }
}

/**
 * HC-106 security-event emails: trigger, gating, and templating.
 */
final class SecurityNotifierTest extends DatabaseTestCase
{
    private UserRepository $users;

    private SilentChannel $channel;

    private SecurityNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->getDb());
        $this->channel = new SilentChannel();
        $this->notifier = new SecurityNotifier(
            $this->getDb(),
            $this->users,
            $this->channel,
            baseUrl: 'https://app.test',
        );

        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, email, is_admin, role, enabled)
             VALUES (?, ?, ?, 'N', 'caregiver', 'Y')",
            ['alice', (new PasswordHasher())->hash('pw'), 'alice@example.org']
        );
    }

    public function testPasswordChangedDispatches(): void
    {
        $this->notifier->notify('alice', SecurityNotifier::EVENT_PASSWORD_CHANGED);

        $this->assertCount(1, $this->channel->sent);
        $msg = $this->channel->sent[0];
        $this->assertSame('alice@example.org', $msg->recipient);
        $this->assertStringContainsString('password was just changed', $msg->body);
        $this->assertStringContainsString('https://app.test/settings.php', $msg->body);
        $this->assertSame(NotificationMessage::PRIORITY_HIGH, $msg->priority);
        $this->assertContains('security', $msg->tags);
    }

    public function testTotpDisabledDispatches(): void
    {
        $this->notifier->notify('alice', SecurityNotifier::EVENT_TOTP_DISABLED);

        $this->assertCount(1, $this->channel->sent);
        $this->assertStringContainsString('Two-factor authentication was just turned off',
            $this->channel->sent[0]->body);
    }

    public function testApikeyGeneratedDispatches(): void
    {
        $this->notifier->notify('alice', SecurityNotifier::EVENT_APIKEY_GENERATED);

        $this->assertCount(1, $this->channel->sent);
        $this->assertStringContainsString('new API key', $this->channel->sent[0]->body);
    }

    public function testApikeyRevokedDispatches(): void
    {
        $this->notifier->notify('alice', SecurityNotifier::EVENT_APIKEY_REVOKED);

        $this->assertCount(1, $this->channel->sent);
        $this->assertStringContainsString('API key was just revoked',
            $this->channel->sent[0]->body);
    }

    public function testLockoutDispatches(): void
    {
        $this->notifier->notify('alice', SecurityNotifier::EVENT_LOGIN_LOCKOUT);

        $this->assertCount(1, $this->channel->sent);
        $this->assertStringContainsString('locked', $this->channel->sent[0]->body);
        $this->assertStringContainsString('15 minutes', $this->channel->sent[0]->body);
    }

    public function testNewIpQuotesBothAddresses(): void
    {
        $this->notifier->notify(
            'alice',
            SecurityNotifier::EVENT_LOGIN_NEW_IP,
            ['ip' => '203.0.113.7', 'previous_ip' => '192.0.2.1']
        );

        $this->assertCount(1, $this->channel->sent);
        $body = $this->channel->sent[0]->body;
        $this->assertStringContainsString('203.0.113.7', $body);
        $this->assertStringContainsString('192.0.2.1', $body);
    }

    public function testMasterToggleOffSuppressesDispatch(): void
    {
        $this->getDb()->execute(
            "INSERT INTO hc_config (setting, value) VALUES ('security_email_enabled', 'N')"
        );

        $this->notifier->notify('alice', SecurityNotifier::EVENT_PASSWORD_CHANGED);

        $this->assertSame([], $this->channel->sent);
    }

    public function testMissingEmailSuppressesDispatch(): void
    {
        $this->getDb()->execute('UPDATE hc_user SET email = NULL WHERE login = ?', ['alice']);

        $this->notifier->notify('alice', SecurityNotifier::EVENT_PASSWORD_CHANGED);

        $this->assertSame([], $this->channel->sent);
    }

    public function testUnknownUserSuppressesDispatch(): void
    {
        $this->notifier->notify('nobody', SecurityNotifier::EVENT_PASSWORD_CHANGED);

        $this->assertSame([], $this->channel->sent);
    }

    public function testUnknownEventDoesNothing(): void
    {
        $this->notifier->notify('alice', 'not_a_real_event');

        $this->assertSame([], $this->channel->sent);
    }

    public function testTransportFailureIsSwallowed(): void
    {
        $throwingChannel = new class implements NotificationChannel {
            public function name(): string { return 'bad'; }
            public function isReady(): bool { return true; }
            public function send(NotificationMessage $message): bool
            {
                throw new \RuntimeException('SMTP down');
            }
        };

        $notifier = new SecurityNotifier(
            $this->getDb(),
            $this->users,
            $throwingChannel,
            baseUrl: 'https://app.test',
        );

        // MUST NOT throw — fire-and-forget contract.
        $notifier->notify('alice', SecurityNotifier::EVENT_PASSWORD_CHANGED);
        $this->expectNotToPerformAssertions();
    }
}
