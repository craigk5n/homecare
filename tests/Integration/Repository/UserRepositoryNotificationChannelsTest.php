<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Auth\PasswordHasher;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Focused coverage for the HC-103 notification_channels column
 * round-trip. Stays narrow — the rest of UserRepository is already
 * exercised indirectly through AuthServiceIntegrationTest and
 * ApiKeyAuthTest; creating a kitchen-sink UserRepositoryTest just
 * to test one column would duplicate those.
 */
final class UserRepositoryNotificationChannelsTest extends DatabaseTestCase
{
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->getDb());
        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, is_admin, role, enabled)
             VALUES (?, ?, 'N', 'caregiver', 'Y')",
            ['alice', (new PasswordHasher())->hash('pw')]
        );
    }

    public function testDefaultIsEmptyArray(): void
    {
        $row = $this->users->findByLogin('alice');

        $this->assertNotNull($row);
        $this->assertSame('[]', $row['notification_channels']);
    }

    public function testUpdateRoundTrips(): void
    {
        $this->users->updateNotificationChannels('alice', ['ntfy', 'email']);

        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $this->assertSame(
            ['ntfy', 'email'],
            json_decode($row['notification_channels'], true)
        );
    }

    public function testUpdateClearsWithEmptyList(): void
    {
        $this->users->updateNotificationChannels('alice', ['email']);
        $this->users->updateNotificationChannels('alice', []);

        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $this->assertSame('[]', $row['notification_channels']);
    }

    public function testUpdateDedupesAndCleansEmptyEntries(): void
    {
        // Repeat names + blank strings should not appear in storage.
        $this->users->updateNotificationChannels(
            'alice',
            ['email', '', 'email', 'ntfy', '']
        );

        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $this->assertSame(
            ['email', 'ntfy'],
            json_decode($row['notification_channels'], true)
        );
    }
}
