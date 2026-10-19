<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Migration;

use HomeCare\Database\SqliteDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Tests migrations/037_add_user_patients.sql (HC-237) against a
 * pre-migration SQLite state.
 *
 * The migration is MySQL DDL; the only non-portable part is the
 * trailing `ENGINE=... CHARSET=... COLLATE=...` table options, which
 * are stripped before the file is applied to SQLite.
 */
final class UserPatientsMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = (new SqliteDatabase())->pdo();
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE hc_patients (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name VARCHAR(255) NOT NULL,
              is_active BOOLEAN NOT NULL DEFAULT 1
            )
        SQL);
        $this->pdo->exec("INSERT INTO hc_patients (name) VALUES ('Daisy'), ('Fozzie')");
    }

    public function testCreatesEmptyTable(): void
    {
        $this->applyMigration();

        $this->assertSame(0, $this->rowCount());
    }

    public function testCompositePrimaryKeyRejectsDuplicates(): void
    {
        $this->applyMigration();
        $this->pdo->exec("INSERT INTO hc_user_patients (user_login, patient_id) VALUES ('carol', 1)");

        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO hc_user_patients (user_login, patient_id) VALUES ('carol', 1)");
    }

    public function testDeletingPatientCascadesAssignments(): void
    {
        $this->applyMigration();
        $this->pdo->exec("INSERT INTO hc_user_patients (user_login, patient_id) VALUES ('carol', 1), ('carol', 2)");

        $this->pdo->exec('DELETE FROM hc_patients WHERE id = 1');

        $this->assertSame(1, $this->rowCount());
    }

    public function testMigrationIsIdempotentForTheTable(): void
    {
        // CREATE TABLE IF NOT EXISTS: re-running the table DDL must not fail.
        $this->applyMigration();
        $this->pdo->exec($this->tableDdlOnly());

        $this->assertSame(0, $this->rowCount());
    }

    private function applyMigration(): void
    {
        $this->pdo->exec($this->portableSql());
    }

    private function portableSql(): string
    {
        $sql = (string) file_get_contents(__DIR__ . '/../../../migrations/037_add_user_patients.sql');

        return (string) preg_replace('/\)\s*ENGINE=[^;]*;/', ');', $sql);
    }

    private function tableDdlOnly(): string
    {
        $sql = $this->portableSql();
        $end = strpos($sql, ');');

        return substr($sql, 0, $end === false ? 0 : $end + 2);
    }

    private function rowCount(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM hc_user_patients');
        $this->assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }
}
