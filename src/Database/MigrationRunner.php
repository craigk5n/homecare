<?php

declare(strict_types=1);

namespace HomeCare\Database;

use Closure;
use RuntimeException;

/**
 * Applies numbered .sql migrations and records them in hc_migrations.
 *
 * bin/migrate.php uses this for PostgreSQL (HC-250); the MySQL path in
 * that script predates it and is unchanged. Each dialect has its own
 * directory because the scripts are written in that dialect's DDL:
 * migrations/ (MySQL) and migrations/pgsql/ (PostgreSQL).
 *
 * Every migration runs in a transaction together with its hc_migrations
 * row, so on PostgreSQL (transactional DDL) a failing script leaves
 * neither partial changes nor a record behind.
 */
final class MigrationRunner
{
    /**
     * @param Closure(string):mixed $runScript Executes a multi-statement SQL
     *                                         script on the same connection
     *                                         as $db (e.g. PDO::exec).
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Closure $runScript,
        private readonly string $dir,
    ) {}

    public static function directoryFor(Dialect $dialect, string $root): string
    {
        return $dialect === Dialect::Postgres
            ? $root . '/migrations/pgsql'
            : $root . '/migrations';
    }

    /**
     * True when the HomeCare schema has not been loaded yet (no hc_config).
     */
    public function isEmpty(): bool
    {
        try {
            $this->db->query('SELECT setting FROM hc_config WHERE 1 = 0');

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Load the full schema file into an empty database. The schema file
     * creates hc_migrations itself and lists the migrations it already
     * includes, so they are not re-applied afterwards.
     */
    public function bootstrap(string $schemaFile): void
    {
        $sql = @file_get_contents($schemaFile);
        if ($sql === false) {
            throw new RuntimeException("Schema file not found: {$schemaFile}");
        }
        $this->db->transactional(fn() => ($this->runScript)($sql));
        $this->ensureTrackingTable();
    }

    public function ensureTrackingTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS hc_migrations (
                name VARCHAR(64) PRIMARY KEY,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }

    /**
     * @return list<string> Migration file names not yet recorded, in
     *                      natural sort order.
     */
    public function pending(): array
    {
        $applied = array_map(
            static fn(array $row): string => (string) $row['name'],
            $this->db->query('SELECT name FROM hc_migrations'),
        );

        $files = array_map('basename', glob($this->dir . '/*.sql') ?: []);
        sort($files, SORT_NATURAL);

        return array_values(array_diff($files, $applied));
    }

    public function apply(string $name): void
    {
        $sql = @file_get_contents($this->dir . '/' . $name);
        if ($sql === false) {
            throw new RuntimeException("Migration not found: {$name}");
        }

        try {
            $this->db->transactional(function () use ($sql, $name): void {
                ($this->runScript)($sql);
                $this->db->execute('INSERT INTO hc_migrations (name) VALUES (?)', [$name]);
            });
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to apply {$name}: " . $e->getMessage(), 0, $e);
        }
    }
}
