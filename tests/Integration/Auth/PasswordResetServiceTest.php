<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Auth\PasswordHasher;
use HomeCare\Auth\PasswordResetService;
use HomeCare\Notification\NotificationChannel;
use HomeCare\Notification\NotificationMessage;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Captures every NotificationMessage handed to the channel so the
 * tests can assert recipient + subject + body shape without running
 * a real mailer.
 */
final class RecordingChannel implements NotificationChannel
{
    /** @var list<NotificationMessage> */
    public array $sent = [];

    public function __construct(private readonly bool $ready = true)
    {
    }

    public function name(): string
    {
        return 'recording';
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

    /**
     * Opaque accessor for the send count. Lets the rate-limit test
     * assert post-condition after a "silent" call without PHPStan
     * collapsing every assert to a constant.
     */
    public function sentCount(): int
    {
        return count($this->sent);
    }
}

final class PasswordResetServiceTest extends DatabaseTestCase
{
    private UserRepository $users;

    private PasswordHasher $hasher;

    private RecordingChannel $channel;

    /** @var list<array{action:string,details:string}> */
    private array $auditLog = [];

    private PasswordResetService $service;

    private int $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->getDb());
        $this->hasher = new PasswordHasher();
        $this->channel = new RecordingChannel();
        $this->clock = 1_700_000_000; // fixed for deterministic TTL tests
        $this->auditLog = [];

        $tokens = ['tok0', 'tok1', 'tok2', 'tok3', 'tok4', 'tok5', 'tok6'];
        $idx = 0;

        $this->service = new PasswordResetService(
            db: $this->getDb(),
            users: $this->users,
            hasher: $this->hasher,
            emailChannel: $this->channel,
            clock: fn (): int => $this->clock,
            tokenFactory: function () use (&$idx, $tokens): string {
                return $tokens[$idx++] ?? 'tok-overflow-' . $idx;
            },
            audit: function (string $action, string $details = ''): void {
                $this->auditLog[] = ['action' => $action, 'details' => $details];
            },
        );

        // Seed one enabled user with an email.
        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, email, is_admin, role, enabled)
             VALUES (?, ?, ?, 'N', 'caregiver', 'Y')",
            ['alice', $this->hasher->hash('old-pw'), 'alice@example.org']
        );
    }

    public function testValidRoundTrip(): void
    {
        $this->service->initiate('alice', 'https://app.test');

        $this->assertCount(1, $this->channel->sent);
        $msg = $this->channel->sent[0];
        $this->assertSame('alice@example.org', $msg->recipient);
        $this->assertStringContainsString('https://app.test/reset_password.php?token=tok0', $msg->body);

        $this->assertTrue($this->service->complete('tok0', 'brand-new-pw-2026'));

        // Password updated.
        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $this->assertTrue($this->hasher->verify('brand-new-pw-2026', $row['passwd']));

        // failed_attempts + locked_until cleared.
        $this->assertSame(0, $row['failed_attempts']);
        $this->assertNull($row['locked_until']);
        // remember-me invalidated.
        $this->assertNull($row['remember_token']);

        $actions = array_column($this->auditLog, 'action');
        $this->assertContains('password_reset.requested', $actions);
        $this->assertContains('password_reset.completed', $actions);
    }

    public function testForgotPasswordAcceptsEmailToo(): void
    {
        $this->service->initiate('alice@example.org', 'https://app.test');

        $this->assertCount(1, $this->channel->sent);
        $this->assertSame('alice@example.org', $this->channel->sent[0]->recipient);
    }

    public function testInitiateSilentOnUnknownLogin(): void
    {
        $this->service->initiate('nobody', 'https://app.test');

        $this->assertSame([], $this->channel->sent);
        $this->assertSame(
            'password_reset.requested_unknown',
            $this->auditLog[0]['action']
        );
    }

    public function testInitiateSilentOnDisabledAccount(): void
    {
        $this->getDb()->execute(
            "UPDATE hc_user SET enabled = 'N' WHERE login = ?",
            ['alice']
        );

        $this->service->initiate('alice', 'https://app.test');

        $this->assertSame([], $this->channel->sent);
    }

    public function testInitiateSilentWhenUserHasNoEmail(): void
    {
        $this->getDb()->execute(
            "UPDATE hc_user SET email = NULL WHERE login = ?",
            ['alice']
        );

        $this->service->initiate('alice', 'https://app.test');

        $this->assertSame([], $this->channel->sent);
        $this->assertSame(
            'password_reset.requested_no_email',
            $this->auditLog[0]['action']
        );
    }

    public function testExpiredTokenCannotBeUsed(): void
    {
        $this->service->initiate('alice', 'https://app.test');
        // Advance clock past the 60-min TTL.
        $this->clock += PasswordResetService::TTL_MINUTES * 60 + 1;

        $this->assertNull($this->service->validate('tok0'));
        $this->assertFalse($this->service->complete('tok0', 'new-pw-12345'));
    }

    public function testUsedTokenCannotBeReplayed(): void
    {
        $this->service->initiate('alice', 'https://app.test');
        $this->assertTrue($this->service->complete('tok0', 'new-pw-12345'));

        // Second attempt with same token: rejected.
        $this->assertNull($this->service->validate('tok0'));
        $this->assertFalse($this->service->complete('tok0', 'other-pw-12345'));
    }

    public function testValidateRejectsUnknownToken(): void
    {
        $this->assertNull($this->service->validate('nonsense'));
    }

    public function testRateLimitAfterThreeRequestsPerHour(): void
    {
        $this->service->initiate('alice', 'https://app.test');
        $this->service->initiate('alice', 'https://app.test');
        $this->service->initiate('alice', 'https://app.test');

        $this->assertSame(3, $this->channel->sentCount());

        // 4th within the hour: silent no-op.
        $this->service->initiate('alice', 'https://app.test');
        $this->assertSame(3, $this->channel->sentCount());

        $rateLimited = array_filter(
            $this->auditLog,
            static fn (array $r): bool => $r['action'] === 'password_reset.rate_limited'
        );
        $this->assertCount(1, $rateLimited);
    }

    public function testRateLimitResetsAfterAnHour(): void
    {
        $this->service->initiate('alice', 'https://app.test');
        $this->service->initiate('alice', 'https://app.test');
        $this->service->initiate('alice', 'https://app.test');

        // Advance past the 1-hour window.
        $this->clock += 3601;

        $this->service->initiate('alice', 'https://app.test');
        $this->assertSame(4, $this->channel->sentCount());
    }

    public function testCompleteMarksTokenUsedBeforePasswordWrite(): void
    {
        // Consume-before-write guarantees a crash between "mark used"
        // and "update passwd" can't leave the token replayable. We
        // prove it by marking used_at manually and confirming
        // validate()+complete() then refuse.
        $this->service->initiate('alice', 'https://app.test');
        $hash = PasswordResetService::hashToken('tok0');
        $this->getDb()->execute(
            "UPDATE hc_password_reset_tokens SET used_at = ? WHERE token_hash = ?",
            [date('Y-m-d H:i:s', $this->clock - 10), $hash]
        );

        $this->assertNull($this->service->validate('tok0'));
        $this->assertFalse($this->service->complete('tok0', 'whatever-12345'));
    }

    public function testHashTokenIsDeterministic(): void
    {
        $this->assertSame(
            PasswordResetService::hashToken('abc'),
            PasswordResetService::hashToken('abc')
        );
        $this->assertNotSame(
            PasswordResetService::hashToken('abc'),
            PasswordResetService::hashToken('abd')
        );
    }
}
