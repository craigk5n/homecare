<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Database\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that need a real database.
 *
 * Each test method gets a fresh in-memory SQLite database with the full
 * HomeCare schema loaded from `tests/fixtures/schema-sqlite.sql`. Because
 * the DB lives entirely in memory, the suite stays fast and tests can never
 * leak state across cases.
 *
 * Subclasses access the database via `$this->getDb()` and treat it as any
 * other {@see DatabaseInterface}.
 */
abstract class DatabaseTestCase extends TestCase
{
    private SqliteDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = SqliteDatabase::withSchema(self::schemaPath());
    }

    protected function getDb(): DatabaseInterface
    {
        return $this->db;
    }

    /**
     * Exposes the concrete SQLite adapter for tests that need to run DDL or
     * bulk-load fixtures via the raw PDO connection.
     */
    protected function getSqliteDb(): SqliteDatabase
    {
        return $this->db;
    }

    protected static function schemaPath(): string
    {
        return __DIR__ . '/../fixtures/schema-sqlite.sql';
    }

    /**
     * Apply the standard seed fixture (Daisy/Fozzie/Kermit + three medicines
     * + schedules, inventory, and intakes). Tests that need a rich corpus
     * call this from setUp after the fresh schema load.
     */
    protected function loadSeedData(): void
    {
        $path = __DIR__ . '/../fixtures/seed-data.sql';
        $sql = (string) file_get_contents($path);
        $this->db->pdo()->exec($sql);
    }
}
