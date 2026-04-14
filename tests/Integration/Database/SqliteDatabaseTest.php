<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Database;

use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Smoke coverage for the SQLite adapter + schema fixture. If this passes,
 * the full schema loads cleanly and basic CRUD via the interface works.
 */
final class SqliteDatabaseTest extends DatabaseTestCase
{
    public function testSchemaCreatesAllHomeCareTables(): void
    {
        $rows = $this->getDb()->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"
        );
        $tables = array_map(static fn (array $row): string => (string) $row['name'], $rows);

        foreach (
            ['hc_caregiver_notes', 'hc_config', 'hc_medicine_intake',
                'hc_medicine_inventory', 'hc_medicine_schedules', 'hc_medicines',
                'hc_patients', 'hc_user'] as $expected
        ) {
            $this->assertContains($expected, $tables, "missing table {$expected}");
        }
    }

    public function testInsertAndQueryPatientRoundTrip(): void
    {
        $db = $this->getDb();

        $inserted = $db->execute(
            'INSERT INTO hc_patients (name, is_active) VALUES (?, ?)',
            ['Daisy', 1]
        );
        $this->assertTrue($inserted, 'insert should succeed');

        $id = $db->lastInsertId();
        $this->assertGreaterThan(0, $id);

        $rows = $db->query('SELECT id, name, is_active FROM hc_patients WHERE id = ?', [$id]);
        $this->assertCount(1, $rows);
        $this->assertSame('Daisy', $rows[0]['name']);
        $this->assertSame(1, (int) $rows[0]['is_active']);
        $this->assertSame($id, (int) $rows[0]['id']);
    }

    public function testParameterizedQueryPreventsInjection(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ["Robert'); DROP TABLE hc_patients;--"]);

        // If the parameter were interpolated, the patients table would be gone.
        $rows = $db->query('SELECT COUNT(*) AS n FROM hc_patients');
        $this->assertSame(1, (int) $rows[0]['n']);
    }

    public function testEmptyResultSetReturnsEmptyArray(): void
    {
        $rows = $this->getDb()->query('SELECT id FROM hc_patients WHERE id = ?', [99999]);
        $this->assertSame([], $rows);
    }

    public function testForeignKeyCascadeDeletes(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Fozzie']);
        $patientId = $db->lastInsertId();

        $db->execute('INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)', ['Sildenafil', '20mg']);
        $medId = $db->lastInsertId();

        $db->execute(
            'INSERT INTO hc_medicine_schedules (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$patientId, $medId, '2026-04-01', '8h', 1.0]
        );

        $this->assertCount(1, $db->query('SELECT id FROM hc_medicine_schedules'));

        $db->execute('DELETE FROM hc_patients WHERE id = ?', [$patientId]);
        $this->assertCount(0, $db->query('SELECT id FROM hc_medicine_schedules'),
            'schedule should cascade away when its patient is removed');
    }
}
