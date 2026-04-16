<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Notification;

use HomeCare\Notification\ChannelRegistry;
use HomeCare\Notification\NotificationMessage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ChannelRegistryTest extends TestCase
{
    public function testRegisterAndGetByName(): void
    {
        $r = new ChannelRegistry();
        $channel = new FakeChannel('ntfy');

        $r->register($channel);

        $this->assertSame($channel, $r->get('ntfy'));
    }

    public function testGetThrowsOnUnknownName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ChannelRegistry())->get('nothing');
    }

    public function testDefaultChannelsPreserveRegistrationOrder(): void
    {
        $r = new ChannelRegistry();
        $r->register(new FakeChannel('ntfy'));
        $r->register(new FakeChannel('email'));
        $r->register(new FakeChannel('webhook'));

        $this->assertSame(['ntfy', 'email', 'webhook'], $r->defaultChannelNames());
    }

    public function testNonDefaultChannelDoesNotJoinDefaults(): void
    {
        $r = new ChannelRegistry();
        $r->register(new FakeChannel('ntfy'));
        $r->register(new FakeChannel('special'), isDefault: false);

        $this->assertSame(['ntfy'], $r->defaultChannelNames());
        $this->assertSame('special', $r->get('special')->name());
    }

    public function testDispatchSendsToAllDefaults(): void
    {
        $r = new ChannelRegistry();
        $ntfy = new FakeChannel('ntfy');
        $email = new FakeChannel('email');
        $r->register($ntfy);
        $r->register($email);

        $count = $r->dispatch(new NotificationMessage('t', 'b'));

        $this->assertSame(2, $count);
        $this->assertSame(1, $ntfy->sendCalls);
        $this->assertSame(1, $email->sendCalls);
    }

    public function testDispatchSkipsChannelsThatAreNotReady(): void
    {
        $r = new ChannelRegistry();
        $ntfy = new FakeChannel('ntfy', ready: true);
        $email = new FakeChannel('email', ready: false);
        $r->register($ntfy);
        $r->register($email);

        $count = $r->dispatch(new NotificationMessage('t', 'b'));

        $this->assertSame(1, $count);
        $this->assertSame(1, $ntfy->sendCalls);
        $this->assertSame(0, $email->sendCalls, 'not-ready channel must not receive send()');
    }

    public function testDispatchHonoursExplicitChannelList(): void
    {
        $r = new ChannelRegistry();
        $ntfy = new FakeChannel('ntfy');
        $email = new FakeChannel('email');
        $r->register($ntfy);
        $r->register($email);

        $count = $r->dispatch(new NotificationMessage('t', 'b'), ['email']);

        $this->assertSame(1, $count);
        $this->assertSame(0, $ntfy->sendCalls);
        $this->assertSame(1, $email->sendCalls);
    }

    public function testDispatchIgnoresUnknownChannelNames(): void
    {
        $r = new ChannelRegistry();
        $ntfy = new FakeChannel('ntfy');
        $r->register($ntfy);

        $count = $r->dispatch(new NotificationMessage('t', 'b'), ['nope', 'ntfy']);

        $this->assertSame(1, $count);
        $this->assertSame(1, $ntfy->sendCalls);
    }

    public function testDispatchCountsOnlyAcceptedSends(): void
    {
        $r = new ChannelRegistry();
        $ok = new FakeChannel('ok', succeeds: true);
        $fail = new FakeChannel('fail', succeeds: false);
        $r->register($ok);
        $r->register($fail);

        $count = $r->dispatch(new NotificationMessage('t', 'b'));

        $this->assertSame(1, $count);
        $this->assertSame(1, $ok->sendCalls);
        $this->assertSame(1, $fail->sendCalls, 'failing channel still got called');
    }
}
