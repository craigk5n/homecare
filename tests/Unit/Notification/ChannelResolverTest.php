<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Notification;

use HomeCare\Notification\ChannelRegistry;
use HomeCare\Notification\ChannelResolver;
use PHPUnit\Framework\TestCase;

final class ChannelResolverTest extends TestCase
{
    private ChannelRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ChannelRegistry();
        $this->registry->register(new FakeChannel('ntfy', ready: true));
        $this->registry->register(new FakeChannel('email', ready: true));
        $this->registry->register(new FakeChannel('webhook', ready: true));
    }

    public function testFallsBackToRegistryDefaultsWhenPreferenceIsEmpty(): void
    {
        $resolver = new ChannelResolver($this->registry);

        $this->assertSame(
            ['ntfy', 'email', 'webhook'],
            $resolver->resolveFor('[]'),
        );
        $this->assertSame(
            ['ntfy', 'email', 'webhook'],
            $resolver->resolveFor(''),
        );
    }

    public function testPrefersExplicitUserList(): void
    {
        $resolver = new ChannelResolver($this->registry);

        $this->assertSame(
            ['email'],
            $resolver->resolveFor('["email"]'),
        );
    }

    public function testPreservesUserListOrdering(): void
    {
        $resolver = new ChannelResolver($this->registry);

        $this->assertSame(
            ['email', 'ntfy'],
            $resolver->resolveFor('["email","ntfy"]'),
        );
    }

    public function testDropsChannelsThatAreNotReady(): void
    {
        // Re-register email as not-ready.
        $registry = new ChannelRegistry();
        $registry->register(new FakeChannel('ntfy', ready: true));
        $registry->register(new FakeChannel('email', ready: false));
        $resolver = new ChannelResolver($registry);

        // User explicitly wanted email, but it isn't configured —
        // drop silently, leaving an empty list. The reminder cron
        // will then log "no channel ready" for that user.
        $this->assertSame([], $resolver->resolveFor('["email"]'));
    }

    public function testDropsUnknownChannelNames(): void
    {
        $resolver = new ChannelResolver($this->registry);

        // "smoke-signals" isn't registered — ignore it silently,
        // pick up the channels that are.
        $this->assertSame(
            ['ntfy'],
            $resolver->resolveFor('["smoke-signals","ntfy"]'),
        );
    }

    public function testMalformedJsonFallsBackToDefaults(): void
    {
        $resolver = new ChannelResolver($this->registry);

        $this->assertSame(
            ['ntfy', 'email', 'webhook'],
            $resolver->resolveFor('not-json'),
        );
    }

    public function testNonStringEntriesAreIgnored(): void
    {
        $resolver = new ChannelResolver($this->registry);

        // "email", 42, null, "" → only "email" and "ntfy" survive.
        $this->assertSame(
            ['email', 'ntfy'],
            $resolver->resolveFor('["email",42,null,"","ntfy"]'),
        );
    }

    public function testEmptyListWhenNoChannelsReady(): void
    {
        $registry = new ChannelRegistry();
        $registry->register(new FakeChannel('ntfy', ready: false));
        $resolver = new ChannelResolver($registry);

        $this->assertSame([], $resolver->resolveFor('[]'));
    }
}
