<?php

declare(strict_types=1);

namespace HomeCare\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO pgsql implementation of {@see DatabaseInterface}.
 *
 * Production pages reach PostgreSQL through dbi4php + {@see DbiAdapter};
 * this adapter exists so the integration suite can run against a real
 * PostgreSQL server (HOMECARE_TEST_DB=pgsql, see DatabaseTestCase) and
 * for CLI tooling such as bin/migrate.php.
 *
 * Prepared statements are native (no client-side emulation), so the
 * server sees `$n` parameters exactly as `pg_query_params()` sends them
 * from dbi4php.
 */
final class PgsqlDatabase implements DatabaseInterface
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Open a connection with the attributes the rest of the class relies
     * on (exceptions on error, associative fetches, native prepares).
     */
    public static function connect(string $dsn, ?string $user = null, ?string $password = null): self
    {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Column defaults (CURRENT_TIMESTAMP) are evaluated in the session
        // time zone; align it with PHP so DB-filled and PHP-computed
        // timestamps agree, as they do on a same-host MySQL.
        $pdo->prepare("SELECT set_config('TimeZone', ?, false)")
            ->execute([date_default_timezone_get()]);

        return new self($pdo);
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();

        /** @var list<array<string,scalar|null>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function execute(string $sql, array $params = []): bool
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bind($stmt, $params);

            return $stmt->execute();
        } catch (PDOException $e) {
            throw new RuntimeException(
                'PostgreSQL execute failed: ' . $e->getMessage() . ' -- SQL: ' . $sql,
                0,
                $e,
            );
        }
    }

    /**
     * PostgreSQL has no per-connection "insert id"; lastval() returns the
     * value most recently produced by any sequence (identity columns
     * included) in this session, which is the INSERT the caller just ran.
     */
    public function lastInsertId(): int
    {
        try {
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException) {
            // lastval() is undefined until a sequence has been used.
            return 0;
        }
    }

    public function transactional(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function dialect(): Dialect
    {
        return Dialect::Postgres;
    }

    /**
     * Expose the underlying PDO for multi-statement scripts (schema
     * loads, migrations) and test fixtures.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Bind positional params. Booleans go in as 0/1 -- the schema stores
     * flags as SMALLINT, and PDO would otherwise send false as ''.
     *
     * @param list<scalar|null> $params
     */
    private function bind(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $i => $value) {
            if (is_bool($value)) {
                $value = (int) $value;
            }
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, is_float($value) ? (string) $value : $value, $type);
        }
    }
}
