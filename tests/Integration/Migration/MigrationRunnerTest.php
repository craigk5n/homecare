<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Migration;

use HomeCare\Tests\Integration\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversNothing; // CLI script

#[CoversNothing]
final class MigrationRunnerTest extends DatabaseTestCase
{
    private string $testMigrationPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testMigrationPath = sys_get_temp_dir() . '/test_migration_010.sql';

        // Create test migration file
        file_put_contents($this->testMigrationPath, '-- Test migration
CREATE TABLE IF NOT EXISTS test_table (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL
);
INSERT INTO test_table (name) VALUES (\'test\');');

        // Clean hc_migrations
        $this->getDb()->execute('DROP TABLE IF EXISTS hc_migrations');
    }

    protected function tearDown(): void
    {
        @unlink($this->testMigrationPath);
        parent::tearDown();
    }

    public function testDryRunListsPendingMigrations(): void
    {
        $output = $this->runMigration(['--dry-run']);

        $this->assertStringContainsString('Pending migrations (1):', $output);
        $this->assertStringContainsString('010_test_migration.sql', $output);
        $this->assertStringNotContainsString('Applied', $output);

        // No table created
        $result = $this->getDb()->query('SELECT name FROM sqlite_master WHERE type=\'table\' AND name=\'test_table\'');
        $this->assertEmpty($result);
    }

    public function testAppliesPendingMigration(): void
    {
        $output = $this->runMigration([]);

        $this->assertStringContainsString('Applied migration: 010_test_migration.sql', $output);
        $this->assertStringContainsString('Successfully applied 1 migrations', $output);

        // Table created
        $result = $this->getDb()->query('SELECT name FROM sqlite_master WHERE type=\'table\' AND name=\'test_table\'');
        $this->assertNotEmpty($result);

        // Data inserted
        $data = $this->getDb()->query('SELECT name FROM test_table');
        $this->assertNotEmpty($data);
        $this->assertEquals('test', $data[0]['name']);

        // Recorded in hc_migrations
        $applied = $this->getDb()->query('SELECT name FROM hc_migrations WHERE name = \'010_test_migration.sql\'');
        $this->assertNotEmpty($applied);
    }

    public function testIsIdempotent(): void
    {
        $this->runMigration([]);

        $output = $this->runMigration([]);

        $this->assertStringContainsString('No pending migrations', $output);
    }

    /**
     * @param list<string> $args
     */
    private function runMigration(array $args): string
    {
        $this->markTestIncomplete('Migration runner integration test pending full implementation');
    }
}
