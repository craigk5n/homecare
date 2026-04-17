<?php

declare(strict_types=1);

namespace HomeCare\Database;

use mysqli;
use mysqli_result;
use RuntimeException;

/**
 * Production {@see DatabaseInterface} backed by the legacy dbi4php layer.
 *
 * Pages still call `dbi_execute()` / `dbi_get_cached_rows()` directly and
 * will continue to work unchanged -- this adapter exists so the new
 * repository classes can accept a narrow, testable contract while still
 * sharing the same mysqli connection (`$GLOBALS['c']` -- the variable
 * name dbi4php.php itself uses when dbi_connect() stashes the handle).
 *
 * The adapter wraps `dbi_execute()` to issue parameterized SQL, then uses
 * the underlying mysqli result to fetch associative rows. `dbi_fetch_row()`
 * returns numeric-indexed arrays, which do not round-trip across dialects;
 * repositories read columns by name via the associative shape instead.
 *
 * Only the mysqli backend is supported here. Other `$db_type` values in
 * `dbi4php.php` are legacy WebCalendar ports that HomeCare does not deploy.
 */
final class DbiAdapter implements DatabaseInterface
{
    public function query(string $sql, array $params = []): array
    {
        $result = $this->issue($sql, $params);
        if (is_bool($result)) {
            // dbi_execute returns boolean for non-SELECT statements.
            return [];
        }

        /** @var list<array<string,scalar|null>> $rows */
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            /** @var array<string,scalar|null> $row */
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    public function execute(string $sql, array $params = []): bool
    {
        $result = $this->issue($sql, $params);

        // dbi_execute returns true/false for writes, a result handle for reads.
        return $result !== false;
    }

    public function transactional(callable $fn): mixed
    {
        $conn = $GLOBALS['c'] ?? null;
        if (!$conn instanceof mysqli) {
            throw new RuntimeException(
                'No active mysqli connection; cannot start transaction.',
            );
        }

        $conn->begin_transaction();
        try {
            $result = $fn();
            $conn->commit();

            return $result;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public function lastInsertId(): int
    {
        // dbi4php.php stashes the mysqli handle in $GLOBALS['c'] when
        // dbi_connect() runs. We read from there rather than calling
        // mysqli_insert_id() on a locally-held handle so forks of the
        // adapter stay compatible with other legacy call sites that
        // may have already consumed the same global.
        $conn = $GLOBALS['c'] ?? null;
        if (!$conn instanceof mysqli) {
            throw new RuntimeException(
                'No active mysqli connection; cannot read insert ID.',
            );
        }

        return (int) $conn->insert_id;
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return mysqli_result|bool Result handle for SELECT, bool for writes.
     */
    private function issue(string $sql, array $params): mysqli_result|bool
    {
        if (!function_exists('dbi_execute')) {
            throw new RuntimeException(
                'dbi4php.php is not loaded; DbiAdapter requires the legacy db layer to be bootstrapped.',
            );
        }

        /** @var mysqli_result|bool $result */
        $result = dbi_execute($sql, $params, false, false);

        return $result;
    }
}
