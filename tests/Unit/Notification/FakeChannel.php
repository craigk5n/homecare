<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Notification;

use HomeCare\Notification\NotificationChannel;
use HomeCare\Notification\NotificationMessage;

/**
 * Test double for {@see NotificationChannel}. Records every send()
 * for assertion and exposes `setReady()` so a test can toggle
 * readiness between steps.
 *
 * Lives in its own file (not inside a test class) so PSR-4 autoload
 * resolves it when other test files use it — multiple test classes
 * now share it (ChannelRegistryTest, ChannelResolverTest).
 */
final class FakeChannel implements NotificationChannel
{
    public int $sendCalls = 0;

    /** @var list<NotificationMessage> */
    public array $messages = [];

    public function __construct(
        private readonly string $name,
        private bool $ready = true,
        private readonly bool $succeeds = true,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function setReady(bool $ready): void
    {
        $this->ready = $ready;
    }

    public function send(NotificationMessage $message): bool
    {
        $this->sendCalls++;
        $this->messages[] = $message;

        return $this->succeeds;
    }
}
