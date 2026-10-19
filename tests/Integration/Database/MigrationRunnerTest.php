<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Database;

use HomeCare\Database\Dialect;
use HomeCare\Database\MigrationRunner;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * MigrationRunner drives bin/migrate.php for PostgreSQL (HC-250). The
 * runner itself is dialect-neutral, so it runs on both test backends.
 */
final class MigrationRunnerTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hc-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testDirectoryForDialect(): void
    {
        $this->assertSame('/app/migrations', MigrationRunner::directoryFor(Dialect::MySQL, '/app'));
        $this->assertSame('/app/migrations/pgsql', MigrationRunner::directoryFor(Dialect::Postgres, '/app'));
    }

    public function testPendingListsUnappliedFilesInNaturalOrder(): void
    {
        $this->write('10_c.sql', 'SELECT 1');
        $this->write('9_b.sql', 'SELECT 1');
        $this->write('036_a.sql', 'SELECT 1');
        $this->write('README.md', 'not a migration');
        $runner = $this->runner();
        $runner->ensureTrackingTable();
        $this->getDb()->execute('INSERT INTO hc_migrations (name) VALUES (?)', ['9_b.sql']);

        $this->assertSame(['10_c.sql', '036_a.sql'], $runner->pending());
    }

    public function testApplyRunsScriptAndRecordsIt(): void
    {
        $this->write('036_add_flag.sql', "INSERT INTO hc_config (setting, value) VALUES ('flag', 'on');\n"
            . "INSERT INTO hc_config (setting, value) VALUES ('flag2', 'on');");
        $runner = $this->runner();
        $runner->ensureTrackingTable();

        $runner->apply('036_add_flag.sql');

        $this->assertCount(2, $this->getDb()->query("SELECT setting FROM hc_config WHERE value = 'on'"));
        $this->assertSame([], $runner->pending());
    }

    public function testFailedMigrationIsRolledBackAndNotRecorded(): void
    {
        $this->write('036_bad.sql', "INSERT INTO hc_config (setting, value) VALUES ('half', 'done');\n"
            . 'INSERT INTO hc_no_such_table (x) VALUES (1);');
        $runner = $this->runner();
        $runner->ensureTrackingTable();

        try {
            $runner->apply('036_bad.sql');
            $this->fail('apply should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('036_bad.sql', $e->getMessage());
        }

        $this->assertSame([], $this->getDb()->query("SELECT setting FROM hc_config WHERE setting = 'half'"));
        $this->assertSame(['036_bad.sql'], $runner->pending());
    }

    public function testBootstrapLoadsSchemaOnlyIntoEmptyDatabase(): void
    {
        $schema = $this->dir . '/schema.txt';
        file_put_contents($schema, "CREATE TABLE hc_config (setting VARCHAR(50) PRIMARY KEY, value VARCHAR(128));\n"
            . "CREATE TABLE hc_migrations (name VARCHAR(64) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);\n"
            . "INSERT INTO hc_migrations (name) VALUES ('036_in_baseline.sql');");
        $this->write('036_in_baseline.sql', 'SELECT 1');
        $this->write('037_new.sql', 'SELECT 1');
        $this->pdo()->exec('DROP TABLE hc_config');
        $this->pdo()->exec('DROP TABLE IF EXISTS hc_migrations');
        $runner = $this->runner();

        $this->assertTrue($runner->isEmpty());
        $runner->bootstrap($schema);

        $this->assertFalse($runner->isEmpty());
        $this->assertSame(['037_new.sql'], $runner->pending());
    }

    public function testRealPostgresSchemaBootstrapsAnEmptySchema(): void
    {
        if (!self::usingPostgres()) {
            $this->markTestSkipped('Set HOMECARE_TEST_DB=pgsql to run PostgreSQL tests.');
        }
        $this->pdo()->exec('DROP SCHEMA hc_phpunit CASCADE');
        $this->pdo()->exec('CREATE SCHEMA hc_phpunit');
        $runner = new MigrationRunner(
            $this->getDb(),
            fn(string $sql) => $this->pdo()->exec($sql),
            MigrationRunner::directoryFor(Dialect::Postgres, __DIR__ . '/../../..'),
        );

        $this->assertTrue($runner->isEmpty());
        $runner->bootstrap(__DIR__ . '/../../../tables-postgres.sql');

        $this->assertFalse($runner->isEmpty());
        $this->assertSame([], $runner->pending());
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner(
            $this->getDb(),
            fn(string $sql) => $this->pdo()->exec($sql),
            $this->dir,
        );
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }
}
