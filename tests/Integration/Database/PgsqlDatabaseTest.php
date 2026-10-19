<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Database;

use HomeCare\Database\Dialect;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * PostgreSQL-only checks for PgsqlDatabase + tables-postgres.sql (HC-250).
 * Runs when HOMECARE_TEST_DB=pgsql; skipped on the default SQLite run.
 */
final class PgsqlDatabaseTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        if (!self::usingPostgres()) {
            $this->markTestSkipped('Set HOMECARE_TEST_DB=pgsql to run PostgreSQL tests.');
        }
        parent::setUp();
    }

    public function testSchemaCreatesEveryTableFromTheMysqlSchema(): void
    {
        $rows = $this->getDb()->query(
            'SELECT table_name FROM information_schema.tables
              WHERE table_schema = current_schema() ORDER BY table_name',
        );
        $tables = array_map(static fn(array $row): string => (string) $row['table_name'], $rows);

        preg_match_all(
            '/CREATE TABLE `?(hc_[a-z_]+)`?/i',
            (string) file_get_contents(__DIR__ . '/../../../tables-mysql.sql'),
            $m,
        );
        foreach ($m[1] as $expected) {
            $this->assertContains($expected, $tables, "missing table {$expected}");
        }
        $this->assertContains('hc_api_rate_limit', $tables);
        $this->assertContains('hc_migrations', $tables);
    }

    public function testDialectIsPostgres(): void
    {
        $this->assertSame(Dialect::Postgres, $this->getDb()->dialect());
    }

    public function testLastInsertIdReturnsIdentityValue(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $first = $db->lastInsertId();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Fozzie']);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first + 1, $db->lastInsertId());
    }

    public function testBooleanParamsBindAsSmallint(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name, is_active) VALUES (?, ?)', ['Kermit', false]);
        $db->execute('INSERT INTO hc_patients (name, is_active) VALUES (?, ?)', ['Daisy', true]);

        $rows = $db->query('SELECT name FROM hc_patients WHERE is_active = ? ORDER BY name', [1]);
        $this->assertSame([['name' => 'Daisy']], $rows);
    }

    public function testTimestampsReadBackWithoutFractionalSeconds(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);

        $rows = $db->query('SELECT created_at FROM hc_patients');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $rows[0]['created_at'],
        );
    }

    public function testUpdatedAtTriggerMirrorsMysqlOnUpdate(): void
    {
        $db = $this->getDb();
        $db->execute(
            'INSERT INTO hc_patients (name, updated_at) VALUES (?, ?)',
            ['Daisy', '2000-01-01 00:00:00'],
        );
        $id = $db->lastInsertId();

        $db->execute('UPDATE hc_patients SET name = ? WHERE id = ?', ['Daisy II', $id]);

        $rows = $db->query('SELECT updated_at FROM hc_patients WHERE id = ?', [$id]);
        $this->assertGreaterThan('2000-01-01 00:00:00', (string) $rows[0]['updated_at']);
    }

    public function testTransactionalRollsBackOnThrow(): void
    {
        $db = $this->getDb();
        try {
            $db->transactional(static function () use ($db): void {
                $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Ghost']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $db->query('SELECT id FROM hc_patients'));
    }

    public function testSeedFixtureLeavesSequencesPastExplicitIds(): void
    {
        $this->loadSeedData();
        $db = $this->getDb();

        // Seed uses ids 1..3; a fresh insert must not collide.
        $this->assertTrue($db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['New']));
        $this->assertGreaterThan(3, $db->lastInsertId());
    }
}
