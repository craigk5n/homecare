<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Database;

use HomeCare\Database\DbiAdapter;
use HomeCare\Database\Dialect;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Exercises the legacy dbi4php `postgresql` driver and DbiAdapter on top
 * of it -- the path production pages take when settings.php says
 * `db_type: postgresql` (HC-250). Runs only with HOMECARE_TEST_DB=pgsql;
 * DatabaseTestCase provides the freshly loaded hc_phpunit schema.
 */
final class DbiPgsqlTest extends DatabaseTestCase
{
    /** @var array<string,mixed> */
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        if (!self::usingPostgres()) {
            $this->markTestSkipped('Set HOMECARE_TEST_DB=pgsql to run PostgreSQL tests.');
        }
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('pgsql extension not loaded.');
        }
        parent::setUp();

        require_once __DIR__ . '/../../../includes/dbi4php.php';

        foreach (['db_type', 'db_persistent', 'c', 'db_connection_info', 'postgresql_connection'] as $key) {
            $this->savedGlobals[$key] = $GLOBALS[$key] ?? null;
        }
        $GLOBALS['db_type'] = 'postgresql';
        $GLOBALS['db_persistent'] = false;
        $GLOBALS['db_connection_info'] = null;

        // Lazy connect, as init.php does; the first query opens the
        // connection and dbi_query() stores it in $GLOBALS['c'].
        $dsn = self::pgDsnParts();
        dbi_connect(
            ($dsn['host'] ?? '127.0.0.1') . (isset($dsn['port']) ? ':' . $dsn['port'] : ''),
            self::env('HOMECARE_TEST_PG_USER', 'postgres'),
            self::env('HOMECARE_TEST_PG_PASSWORD', ''),
            $dsn['dbname'] ?? 'homecare_test',
        );
        dbi_execute('SET search_path TO hc_phpunit');
        $this->assertInstanceOf(\PgSql\Connection::class, $GLOBALS['c']);
    }

    protected function tearDown(): void
    {
        if (self::usingPostgres() && function_exists('dbi_close') && $this->savedGlobals !== []) {
            if ($GLOBALS['c'] instanceof \PgSql\Connection) {
                pg_close($GLOBALS['c']);
            }
            foreach ($this->savedGlobals as $key => $value) {
                $GLOBALS[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testExecuteBindsParamsServerSide(): void
    {
        // A value that would break naive quoting must round-trip intact.
        $name = "O'Brien \ \"quoted\" ?";
        $this->assertNotFalse(dbi_execute('INSERT INTO hc_patients (name, is_active) VALUES (?, ?)', [$name, true]));

        $rows = self::rows('SELECT name, is_active FROM hc_patients WHERE name = ?', [$name]);
        $this->assertSame($name, $rows[0][0]);
        $this->assertSame('1', $rows[0][1]);
    }

    public function testNullAndFalseParams(): void
    {
        dbi_execute('INSERT INTO hc_patients (name, species, is_active) VALUES (?, ?, ?)', ['Kermit', null, false]);

        $rows = self::rows('SELECT species, is_active FROM hc_patients');
        $this->assertNull($rows[0][0]);
        $this->assertSame('0', $rows[0][1]);
    }

    public function testInsertId(): void
    {
        dbi_execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $first = dbi_insert_id();
        dbi_execute('INSERT INTO hc_patients (name) VALUES (?)', ['Fozzie']);
        $this->assertSame($first + 1, dbi_insert_id());
    }

    public function testSessionTimeZoneFollowsPhp(): void
    {
        $rows = self::rows('SHOW TimeZone');
        $this->assertSame(date_default_timezone_get(), $rows[0][0]);
    }

    public function testDbiAdapterOnPostgres(): void
    {
        $db = new DbiAdapter();
        $this->assertSame(Dialect::Postgres, $db->dialect());

        $this->assertTrue($db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']));
        $id = $db->lastInsertId();
        $this->assertGreaterThan(0, $id);

        $rows = $db->query('SELECT id, name FROM hc_patients WHERE id = ?', [$id]);
        $this->assertSame([['id' => (string) $id, 'name' => 'Daisy']], $rows);

        try {
            $db->transactional(static function () use ($db): void {
                $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Ghost']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(1, $db->query('SELECT id FROM hc_patients'));
    }

    /**
     * dbi_get_cached_rows() with the result shape checked.
     *
     * @param list<scalar|null> $params
     *
     * @return list<array<int,mixed>>
     */
    private static function rows(string $sql, array $params = []): array
    {
        $rows = dbi_get_cached_rows($sql, $params);
        self::assertIsArray($rows);
        $out = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $out[] = array_values($row);
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    private static function pgDsnParts(): array
    {
        $dsn = self::env('HOMECARE_TEST_PG_DSN', 'pgsql:host=127.0.0.1;port=5432;dbname=homecare_test');
        $parts = [];
        foreach (explode(';', substr($dsn, strlen('pgsql:'))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (in_array($key, ['host', 'port', 'dbname'], true)) {
                $parts[$key] = $value;
            }
        }

        return $parts;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
