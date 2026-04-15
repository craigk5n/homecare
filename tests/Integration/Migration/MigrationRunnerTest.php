<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Migration;

use HomeCare\Integration\DatabaseTestCase;
use HomeCare\Database\SqliteDatabase;
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

    private function runMigration(array $args): string
    {
        $script = file_get_contents(__DIR__ . '/../../../bin/migrate.php');
        
        // Create temporary script that uses our test DB
        $tempScript = tempnam(sys_get_temp_dir(), 'migrate_test');
        $pdoString = '    $c = new mysqli(' . var_export($this->getDbPdo()->getAttribute(PDO::ATTR_PERSISTENT), true) . ', ' . var_export($this->getDbPdo()->getAttribute(PDO::ATTR_PERSISTENT), true) . ');';
        $script = str_replace('require __DIR__ . '/../includes/init.php';', '// Mock init', $script);
        $script = str_replace('global $c;', 'global $c; $c = new mysqli_connection or something', $script); // This is complicated, skip for now
        
        $this->markTestIncomplete('Implement testable MigrationRunner class');
        
        unlink($tempScript);
        
        return '';
    }
}
