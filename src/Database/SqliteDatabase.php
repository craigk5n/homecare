<?php

declare(strict_types=1);

namespace HomeCare\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * In-memory SQLite implementation of {@see DatabaseInterface}.
 *
 * Used by the integration test suite: every test case spins up a fresh
 * `:memory:` database, loads the schema fixture, and points repositories
 * at this adapter. SQL syntax compatible with both MySQL and SQLite works
 * unchanged; anything dialect-specific belongs in the repository behind
 * a portable contract.
 */
final class SqliteDatabase implements DatabaseInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? self::openInMemory();
    }

    /**
     * Convenience factory: open `:memory:` with sensible defaults and
     * apply the schema from `tests/fixtures/schema-sqlite.sql`.
     */
    public static function withSchema(string $schemaPath): self
    {
        if (!is_file($schemaPath)) {
            throw new RuntimeException("Schema file not found: {$schemaPath}");
        }

        $db = new self();
        $db->pdo->exec((string) file_get_contents($schemaPath));

        return $db;
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('SQLite prepare failed for SQL: ' . $sql);
        }
        $stmt->execute($params);

        /** @var list<array<string,scalar|null>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function execute(string $sql, array $params = []): bool
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                throw new RuntimeException('SQLite prepare failed for SQL: ' . $sql);
            }

            return $stmt->execute($params);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'SQLite execute failed: ' . $e->getMessage() . ' -- SQL: ' . $sql,
                0,
                $e
            );
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Expose the underlying PDO for test fixtures that need to run raw SQL
     * (e.g. loading a multi-statement schema dump).
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private static function openInMemory(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
