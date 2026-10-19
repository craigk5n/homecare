<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Migration;

use HomeCare\Database\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests migrations/038_add_prn_daily_limit.sql against a pre-migration
 * SQLite state (HC-238).
 *
 * Mirrors {@see RoleMigrationTest}: recreate the pre-038
 * hc_medicine_schedules shape inline, apply the migration verbatim,
 * and assert existing rows keep "no limit" (NULL) semantics.
 */
final class PrnDailyLimitMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $db = new SqliteDatabase();
        $this->pdo = $db->pdo();

        // hc_medicine_schedules as of migration 037.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE hc_medicine_schedules (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              patient_id INTEGER NOT NULL,
              medicine_id INTEGER NOT NULL,
              start_date DATE NOT NULL,
              end_date DATE,
              frequency VARCHAR(255) NULL,
              unit_per_dose DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
              is_prn CHAR(1) NOT NULL DEFAULT 'N',
              dose_basis VARCHAR(10) NOT NULL DEFAULT 'fixed',
              cycle_on_days INTEGER NULL,
              cycle_off_days INTEGER NULL,
              wall_clock_times VARCHAR(128) NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $this->pdo->exec(
            "INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, is_prn)
             VALUES (1, 1, '2026-01-01', '8h', 'N'), (1, 2, '2026-01-01', NULL, 'Y')",
        );
    }

    public function testExistingSchedulesHaveNoLimitAfterMigration(): void
    {
        $this->applyMigration();

        $rows = $this->fetchAll('SELECT max_doses_per_24h, min_hours_between FROM hc_medicine_schedules ORDER BY id');

        $this->assertSame([
            ['max_doses_per_24h' => null, 'min_hours_between' => null],
            ['max_doses_per_24h' => null, 'min_hours_between' => null],
        ], $rows);
    }

    public function testLimitColumnsAcceptValues(): void
    {
        $this->applyMigration();

        $this->pdo->exec('UPDATE hc_medicine_schedules SET max_doses_per_24h = 4, min_hours_between = 4.5 WHERE id = 2');
        $rows = $this->fetchAll('SELECT max_doses_per_24h, min_hours_between FROM hc_medicine_schedules WHERE id = 2');

        $this->assertCount(1, $rows);
        $this->assertEquals(4, $rows[0]['max_doses_per_24h']);
        $this->assertEquals(4.5, $rows[0]['min_hours_between']);
    }

    public function testCreatesAlertLogTable(): void
    {
        $this->applyMigration();

        $this->pdo->exec(
            "INSERT INTO hc_prn_limit_alert_log (schedule_id, last_dose_at, last_level, sent_at)
             VALUES (2, '2026-04-16 08:00:00', 'reached', '2026-04-16 08:00:05')",
        );
        $rows = $this->fetchAll('SELECT COUNT(*) AS n FROM hc_prn_limit_alert_log');

        $this->assertEquals(1, $rows[0]['n']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchAll(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $this->assertNotFalse($stmt);

        /** @var list<array<string,mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function applyMigration(): void
    {
        $sql = (string) file_get_contents(
            __DIR__ . '/../../../migrations/038_add_prn_daily_limit.sql',
        );
        $this->pdo->exec($sql);
    }
}
