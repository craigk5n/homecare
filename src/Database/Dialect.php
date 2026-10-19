<?php

declare(strict_types=1);

namespace HomeCare\Database;

use InvalidArgumentException;

/**
 * SQL dialect of the connection behind a {@see DatabaseInterface}.
 *
 * Almost all HomeCare SQL is written once in the subset MySQL 8,
 * PostgreSQL and SQLite share. The few fragments that cannot be live
 * here, so call sites stay a one-line swap instead of growing per-driver
 * branches. Keep this list short: prefer rewriting a query portably
 * (e.g. binding a PHP-computed date instead of calling NOW()) over
 * adding a method.
 */
enum Dialect: string
{
    case MySQL = 'mysql';
    case Postgres = 'pgsql';
    case SQLite = 'sqlite';

    /**
     * Map a settings.php `db_type` value (or PDO driver name) to a dialect.
     */
    public static function fromDbType(string $dbType): self
    {
        return match (strtolower($dbType)) {
            'mysqli', 'mysql' => self::MySQL,
            'postgresql', 'pgsql' => self::Postgres,
            'sqlite', 'sqlite3' => self::SQLite,
            default => throw new InvalidArgumentException(
                "Unsupported db_type '{$dbType}' (expected mysqli or postgresql).",
            ),
        };
    }

    /**
     * Operator for a case-insensitive LIKE. MySQL's default *_ci
     * collations and SQLite's LIKE already ignore (ASCII) case;
     * PostgreSQL's LIKE does not, so it gets ILIKE.
     */
    public function caseInsensitiveLike(): string
    {
        return $this === self::Postgres ? 'ILIKE' : 'LIKE';
    }

    /**
     * Comma-joined aggregate of $expr ordered by $orderBy (MySQL's
     * GROUP_CONCAT default separator). Both arguments are SQL fragments
     * written by the caller -- never user input.
     */
    public function groupConcat(string $expr, string $orderBy): string
    {
        return match ($this) {
            self::MySQL => "GROUP_CONCAT({$expr} ORDER BY {$orderBy})",
            self::Postgres => "STRING_AGG(CAST({$expr} AS TEXT), ',' ORDER BY {$orderBy})",
            self::SQLite => "GROUP_CONCAT({$expr}, ',' ORDER BY {$orderBy})",
        };
    }
}
