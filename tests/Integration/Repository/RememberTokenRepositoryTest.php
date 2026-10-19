<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\RememberTokenRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Covers the housekeeping queries, which used SQLite's datetime('now')
 * and so failed on MySQL and PostgreSQL (HC-250).
 */
final class RememberTokenRepositoryTest extends DatabaseTestCase
{
    private RememberTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new RememberTokenRepository($this->getDb());
    }

    public function testDeleteExpiredRemovesOnlyPastTokens(): void
    {
        $this->tokens->create('alice', str_repeat('a', 64), '2000-01-01 00:00:00');
        $this->tokens->create('alice', str_repeat('b', 64), '2999-01-01 00:00:00');

        $this->tokens->deleteExpired();

        $this->assertNull($this->tokens->findByTokenHash(str_repeat('a', 64)));
        $this->assertNotNull($this->tokens->findByTokenHash(str_repeat('b', 64)));
    }

    public function testTouchLastUsedUpdatesTimestampAndIp(): void
    {
        $id = $this->tokens->create('alice', str_repeat('c', 64), '2999-01-01 00:00:00');
        $this->getDb()->execute(
            'UPDATE hc_remember_tokens SET last_used_at = ? WHERE id = ?',
            ['2000-01-01 00:00:00', $id],
        );

        $this->assertTrue($this->tokens->touchLastUsed($id, '10.0.0.7'));

        $rows = $this->getDb()->query(
            'SELECT last_used_at, last_ip FROM hc_remember_tokens WHERE id = ?',
            [$id],
        );
        $this->assertSame('10.0.0.7', $rows[0]['last_ip']);
        $this->assertGreaterThan('2000-01-01 00:00:00', (string) $rows[0]['last_used_at']);
    }
}
