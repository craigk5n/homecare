<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Auth\PasswordHasher;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * HC-104 addressing plumbing: updateEmail /
 * updateEmailNotifications / getEmailSubscribers round-trip
 * against the real schema.
 */
final class UserRepositoryEmailSubscribersTest extends DatabaseTestCase
{
    private UserRepository $users;

    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserRepository($this->getDb());
        $this->hasher = new PasswordHasher();
    }

    private function seedUser(
        string $login,
        ?string $email = null,
        string $emailNotifications = 'N',
        string $enabled = 'Y',
    ): void {
        $this->getDb()->execute(
            "INSERT INTO hc_user (login, passwd, email, is_admin, role, enabled, email_notifications)
             VALUES (?, ?, ?, 'N', 'caregiver', ?, ?)",
            [$login, $this->hasher->hash('pw'), $email, $enabled, $emailNotifications],
        );
    }

    public function testUpdateEmailRoundTrips(): void
    {
        $this->seedUser('alice');

        $this->users->updateEmail('alice', 'alice@example.org');
        $row = $this->users->findByLogin('alice');

        $this->assertNotNull($row);
        $this->assertSame('alice@example.org', $row['email']);
    }

    public function testUpdateEmailTrimsWhitespace(): void
    {
        $this->seedUser('alice');

        $this->users->updateEmail('alice', '   alice@example.org   ');
        $row = $this->users->findByLogin('alice');

        $this->assertNotNull($row);
        $this->assertSame('alice@example.org', $row['email']);
    }

    public function testUpdateEmailClearsWithEmptyString(): void
    {
        $this->seedUser('alice', email: 'old@example.org');

        $this->users->updateEmail('alice', '');
        $row = $this->users->findByLogin('alice');

        $this->assertNotNull($row);
        $this->assertNull($row['email']);
    }

    public function testUpdateEmailClearsWithNull(): void
    {
        $this->seedUser('alice', email: 'old@example.org');

        $this->users->updateEmail('alice', null);
        $row = $this->users->findByLogin('alice');

        $this->assertNotNull($row);
        $this->assertNull($row['email']);
    }

    public function testUpdateEmailNotificationsToggle(): void
    {
        $this->seedUser('alice', email: 'alice@example.org');

        $this->users->updateEmailNotifications('alice', true);
        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $this->assertSame('Y', $row['email_notifications']);

        $this->users->updateEmailNotifications('alice', false);
        $row = $this->users->findByLogin('alice');
        $this->assertNotNull($row);
        $this->assertSame('N', $row['email_notifications']);
    }

    public function testGetEmailSubscribersReturnsOptedInUsers(): void
    {
        $this->seedUser('alice', email: 'alice@example.org', emailNotifications: 'Y');
        $this->seedUser('bob', email: 'bob@example.org', emailNotifications: 'Y');
        $this->seedUser('carol', email: 'carol@example.org', emailNotifications: 'N');

        $subscribers = $this->users->getEmailSubscribers();

        $this->assertCount(2, $subscribers);
        $this->assertContains('alice@example.org', $subscribers);
        $this->assertContains('bob@example.org', $subscribers);
        $this->assertNotContains('carol@example.org', $subscribers);
    }

    public function testGetEmailSubscribersSkipsUsersWithoutEmail(): void
    {
        // Opted-in but no email on file → not returned (no address to send to).
        $this->seedUser('alice', emailNotifications: 'Y');

        $this->assertSame([], $this->users->getEmailSubscribers());
    }

    public function testGetEmailSubscribersSkipsDisabledAccounts(): void
    {
        $this->seedUser(
            'alice',
            email: 'alice@example.org',
            emailNotifications: 'Y',
            enabled: 'N',
        );

        $this->assertSame([], $this->users->getEmailSubscribers());
    }

    public function testGetEmailSubscribersReturnsEmptyOnFreshDb(): void
    {
        $this->assertSame([], $this->users->getEmailSubscribers());
    }
}
