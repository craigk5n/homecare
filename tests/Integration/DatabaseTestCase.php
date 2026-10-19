<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Database\PgsqlDatabase;
use HomeCare\Database\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests that need a real database.
 *
 * Each test method gets a fresh in-memory SQLite database with the full
 * HomeCare schema loaded from `tests/fixtures/schema-sqlite.sql`. Because
 * the DB lives entirely in memory, the suite stays fast and tests can never
 * leak state across cases.
 *
 * Setting `HOMECARE_TEST_DB=pgsql` runs the same tests against a real
 * PostgreSQL server instead (HC-250). The `hc_phpunit` schema is loaded
 * from `tables-postgres.sql` once, then every table is emptied (and its
 * identity reset) before each test; a test that grabs the raw PDO via
 * {@see pdo()} -- typically to run DDL -- forces a full reload next time.
 * Connection settings come from:
 *
 *   HOMECARE_TEST_PG_DSN       default pgsql:host=127.0.0.1;port=5432;dbname=homecare_test
 *   HOMECARE_TEST_PG_USER      default postgres
 *   HOMECARE_TEST_PG_PASSWORD  default empty
 *
 * Only the `hc_phpunit` schema is touched (dropped and re-created), but
 * still point the DSN at a throwaway database.
 *
 * Subclasses access the database via `$this->getDb()` and treat it as any
 * other {@see DatabaseInterface}.
 */
abstract class DatabaseTestCase extends TestCase
{
    private const PG_SCHEMA = 'hc_phpunit';

    /** One PostgreSQL connection for the whole run; schemas are per test. */
    private static ?PgsqlDatabase $pgsql = null;

    /** Per-test data reset script for the loaded schema; null = (re)load needed. */
    private static ?string $pgReset = null;

    private SqliteDatabase|PgsqlDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = self::usingPostgres()
            ? self::freshPostgres()
            : SqliteDatabase::withSchema(self::schemaPath());
    }

    protected function getDb(): DatabaseInterface
    {
        return $this->db;
    }

    /**
     * Exposes the concrete SQLite adapter for tests that need to run DDL or
     * bulk-load fixtures via the raw PDO connection. Skips the test when the
     * suite runs against PostgreSQL; prefer {@see pdo()} for portable DDL.
     */
    protected function getSqliteDb(): SqliteDatabase
    {
        if (!$this->db instanceof SqliteDatabase) {
            $this->markTestSkipped('SQLite-specific test.');
        }

        return $this->db;
    }

    /**
     * Raw PDO handle of whichever backend this run uses, for DDL and
     * multi-statement fixture loads.
     */
    protected function pdo(): PDO
    {
        // Raw access usually means DDL; rebuild the PG schema afterwards.
        self::$pgReset = null;

        return $this->db->pdo();
    }

    protected static function usingPostgres(): bool
    {
        return getenv('HOMECARE_TEST_DB') === 'pgsql';
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

        if ($this->db instanceof PgsqlDatabase) {
            self::syncIdentitySequences($this->db->pdo());
        }
    }

    private static function freshPostgres(): PgsqlDatabase
    {
        if (self::$pgsql === null) {
            $dsn = getenv('HOMECARE_TEST_PG_DSN');
            $user = getenv('HOMECARE_TEST_PG_USER');
            $password = getenv('HOMECARE_TEST_PG_PASSWORD');
            self::$pgsql = PgsqlDatabase::connect(
                $dsn !== false && $dsn !== '' ? $dsn : 'pgsql:host=127.0.0.1;port=5432;dbname=homecare_test',
                $user !== false && $user !== '' ? $user : 'postgres',
                $password !== false ? $password : '',
            );
        }

        $pdo = self::$pgsql->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (self::$pgReset !== null) {
            $pdo->exec(self::$pgReset);

            return self::$pgsql;
        }

        $pdo->exec('SET client_min_messages TO WARNING');
        $pdo->exec('DROP SCHEMA IF EXISTS ' . self::PG_SCHEMA . ' CASCADE');
        $pdo->exec('CREATE SCHEMA ' . self::PG_SCHEMA);
        $pdo->exec('SET search_path TO ' . self::PG_SCHEMA);
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../tables-postgres.sql'));

        // DELETE (children first: newest table oid first) plus sequence
        // resets, rather than TRUNCATE -- TRUNCATE recreates every
        // relation file and costs ~100x more per test on small tables.
        $reset = $pdo->query(
            "SELECT string_agg(stmt, '; ' ORDER BY kind, ord DESC) FROM (
                 SELECT 1 AS kind, c.oid::bigint AS ord,
                        'DELETE FROM ' || quote_ident(c.relname) AS stmt
                   FROM pg_class c
                  WHERE c.relnamespace = current_schema()::regnamespace AND c.relkind = 'r'
                 UNION ALL
                 SELECT 2, c.oid::bigint,
                        format('SELECT setval(%L, 1, false)', quote_ident(c.relname))
                   FROM pg_class c
                  WHERE c.relnamespace = current_schema()::regnamespace AND c.relkind = 'S'
             ) s",
        );
        self::$pgReset = (string) ($reset === false ? '' : $reset->fetchColumn());

        return self::$pgsql;
    }

    /**
     * Fixtures insert explicit ids; move each identity sequence past them
     * so later INSERTs without an id don't collide (MySQL and SQLite
     * advance their counters implicitly).
     */
    private static function syncIdentitySequences(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT table_name, column_name FROM information_schema.columns
              WHERE table_schema = current_schema() AND is_identity = 'YES'",
        );
        if ($stmt === false) {
            return;
        }
        /** @var list<array{table_name:string, column_name:string}> $columns */
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            $table = $col['table_name'];
            $column = $col['column_name'];
            $pdo->exec(
                "SELECT setval(pg_get_serial_sequence('{$table}', '{$column}'),"
                . " COALESCE((SELECT MAX({$column}) FROM {$table}), 0) + 1, false)",
            );
        }
    }
}
