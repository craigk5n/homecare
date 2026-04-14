<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Migration;

use HomeCare\Database\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests migrations/002_add_role_to_hc_user.sql against a pre-migration
 * SQLite state.
 *
 * The regular integration {@see \HomeCare\Tests\Integration\DatabaseTestCase}
 * loads the POST-migration schema (role column already present), so this
 * test recreates the pre-migration hc_user shape inline, applies the
 * migration file verbatim, and asserts the transformation.
 */
final class RoleMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new SqliteDatabase();
        $this->pdo = $db->pdo();

        // Legacy hc_user schema (pre-HC-010, no role column).
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE hc_user (
              login VARCHAR(25) NOT NULL,
              passwd VARCHAR(255),
              lastname VARCHAR(25),
              firstname VARCHAR(25),
              is_admin CHAR(1) DEFAULT 'N',
              email VARCHAR(75) NULL,
              enabled CHAR(1) DEFAULT 'Y',
              telephone VARCHAR(50) NULL,
              address VARCHAR(75) NULL,
              title VARCHAR(75) NULL,
              birthday INTEGER NULL,
              last_login INTEGER NULL,
              PRIMARY KEY (login)
            )
        SQL);

        $this->pdo->exec("INSERT INTO hc_user (login, is_admin) VALUES ('root', 'Y')");
        $this->pdo->exec("INSERT INTO hc_user (login, is_admin) VALUES ('caregiver1', 'N')");
        $this->pdo->exec("INSERT INTO hc_user (login, is_admin) VALUES ('caregiver2', 'N')");
    }

    public function testAdminUserGetsAdminRole(): void
    {
        $this->applyMigration();

        $row = $this->fetchRole('root');
        $this->assertSame('admin', $row);
    }

    public function testNonAdminUsersGetCaregiverRole(): void
    {
        $this->applyMigration();

        $this->assertSame('caregiver', $this->fetchRole('caregiver1'));
        $this->assertSame('caregiver', $this->fetchRole('caregiver2'));
    }

    public function testNewUserInsertedAfterMigrationDefaultsToCaregiver(): void
    {
        $this->applyMigration();

        // A new user created after migration, without specifying role, should
        // get the column default.
        $this->pdo->exec("INSERT INTO hc_user (login, is_admin) VALUES ('newbie', 'N')");
        $this->assertSame('caregiver', $this->fetchRole('newbie'));
    }

    public function testMigrationPreservesExistingColumnData(): void
    {
        // Regression guard: the ALTER TABLE must not drop existing rows.
        $this->applyMigration();

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM hc_user');
        $this->assertNotFalse($stmt, 'COUNT query should prepare successfully');
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(3, $count);
    }

    private function applyMigration(): void
    {
        $sql = (string) file_get_contents(
            __DIR__ . '/../../../migrations/002_add_role_to_hc_user.sql'
        );
        $this->pdo->exec($sql);
    }

    private function fetchRole(string $login): ?string
    {
        $stmt = $this->pdo->prepare('SELECT role FROM hc_user WHERE login = ?');
        $stmt->execute([$login]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }
}
