<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Database;

use HomeCare\Database\Dialect;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DialectTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Dialect}>
     */
    public static function dbTypes(): iterable
    {
        yield 'mysqli' => ['mysqli', Dialect::MySQL];
        yield 'mysql' => ['mysql', Dialect::MySQL];
        yield 'postgresql' => ['postgresql', Dialect::Postgres];
        yield 'pgsql' => ['pgsql', Dialect::Postgres];
        yield 'sqlite3' => ['sqlite3', Dialect::SQLite];
        yield 'sqlite' => ['sqlite', Dialect::SQLite];
        yield 'mixed case' => ['PostgreSQL', Dialect::Postgres];
    }

    #[DataProvider('dbTypes')]
    public function testFromDbTypeMapsSettingsValues(string $dbType, Dialect $expected): void
    {
        $this->assertSame($expected, Dialect::fromDbType($dbType));
    }

    public function testFromDbTypeRejectsUnsupportedDrivers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Dialect::fromDbType('oracle');
    }

    public function testCaseInsensitiveLikeUsesIlikeOnlyOnPostgres(): void
    {
        $this->assertSame('LIKE', Dialect::MySQL->caseInsensitiveLike());
        $this->assertSame('LIKE', Dialect::SQLite->caseInsensitiveLike());
        $this->assertSame('ILIKE', Dialect::Postgres->caseInsensitiveLike());
    }

    public function testGroupConcatPerDialect(): void
    {
        $this->assertSame(
            'GROUP_CONCAT(mi.taken_time ORDER BY mi.taken_time)',
            Dialect::MySQL->groupConcat('mi.taken_time', 'mi.taken_time'),
        );
        $this->assertSame(
            "STRING_AGG(CAST(mi.taken_time AS TEXT), ',' ORDER BY mi.taken_time)",
            Dialect::Postgres->groupConcat('mi.taken_time', 'mi.taken_time'),
        );
        $this->assertSame(
            "GROUP_CONCAT(mi.taken_time, ',' ORDER BY mi.taken_time)",
            Dialect::SQLite->groupConcat('mi.taken_time', 'mi.taken_time'),
        );
    }
}
