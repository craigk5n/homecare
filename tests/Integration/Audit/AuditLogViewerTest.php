<?php
declare(strict_types=1);

namespace HomeCare\Tests\Integration\Audit;

use HomeCare\Tests\Integration\DatabaseTestCase;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Factory\IntakeFactory;
use function Safe\json_decode;
use function Safe\json_encode;
use function Safe\preg_match_all;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;

class AuditLogViewerTest extends DatabaseTestCase
{
    private const AUDIT_TABLE = 'hc_audit_log';

    /** @var array<string, string|array<string,mixed>> */
    private array $getMock = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure audit table exists (should be in schema)
        $this->getDb()->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::AUDIT_TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_login TEXT NOT NULL,
                action TEXT NOT NULL,
                entity_type TEXT,
                entity_id INTEGER,
                details TEXT,
                ip_address TEXT NOT NULL,
                created_at DATETIME NOT NULL
            )'
        );
    }




    private function stubPrintFunctions(): void
    {
        if (!function_exists('print_header')) {
function print_header(string $title = ''): void {
    echo "&lt;!-- Mock header for $title --&gt;";
}
        }

        if (!function_exists('print_trailer')) {
function print_trailer(): void {
    echo '&lt;!-- Mock trailer --&gt;';
}
        }
    }

    private function stubRequireRole(): void
    {
        if (!function_exists('require_role')) {
function require_role(string $role): void {
    // Assume admin for test
}
        }
    }

    private function insertAuditLog(array $data): int
    {
        $defaults = [
            'user_login' => 'testuser',
            'action' => 'test.action',
            'entity_type' => null,
            'entity_id' => null,
            'details' => null,
            'ip_address' => '127.0.0.1',
            'created_at' => date('Y-m-d H:i:s')
        ];
        $data = array_merge($defaults, $data);
        /** @var array{user_login:string, action:string, entity_type:?string, entity_id:?int, details:?string, ip_address:string, created_at:string} $data */
        $this->getDb()->execute(
            'INSERT INTO ' . self::AUDIT_TABLE . ' (user_login, action, entity_type, entity_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$data['user_login'], $data['action'], $data['entity_type'], $data['entity_id'], $data['details'], $data['ip_address'], $data['created_at']]
        );
        return (int) $this->getDb()->lastInsertId();
    }

    private function renderPage(array $getParams = []): string
    {
        $_GET = $getParams;
        ob_start();
        include __DIR__ . '/../../../audit_log.php';
        /** @var string $output */
        $output = ob_get_clean() ?: '';

        return $output;
    }

    public function testNoFiltersReturnsAllEntries(): void
    {
        $this->getDb()->execute(
            'INSERT INTO ' . self::AUDIT_TABLE . ' (user_login, action, ip_address, created_at) VALUES 
            ("admin", "user.login", "127.0.0.1", "2026-04-13 10:00:00"),
            ("caregiver", "intake.recorded", "10.0.0.1", "2026-04-13 11:00:00"),
            ("admin", "schedule.created", "127.0.0.1", "2026-04-13 12:00:00")'
        );

        $repo = new \HomeCare\Repository\AuditRepository($this->getDb());
        $rows = $repo->search([]);

        $this->assertCount(3, $rows);
        $this->assertEquals('schedule.created', $rows[0]['action']);
        $this->assertEquals('intake.recorded', $rows[1]['action']);
        $this->assertEquals('user.login', $rows[2]['action']);
    }

    public function testFilterByUserLogin(): void
    {
        $this->insertAuditLog(['user_login' => 'admin', 'action' => 'intake.recorded']);
        $this->insertAuditLog(['user_login' => 'caregiver', 'action' => 'intake.recorded']);
        $this->insertAuditLog(['user_login' => 'admin', 'action' => 'schedule.created']);

        $repo = new \HomeCare\Repository\AuditRepository($this->getDb());
        $rows = $repo->search(['user_login' => 'admin']);

        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString('caregiver', json_encode($rows));
        $this->assertEquals('schedule.created', $rows[0]['action']);
        $this->assertEquals('intake.recorded', $rows[1]['action']);
    }

    public function testFilterByAction(): void
    {
        $this->insertAuditLog(['action' => 'intake.recorded']);
        $this->insertAuditLog(['action' => 'schedule.created']);
        $this->insertAuditLog(['action' => 'intake.recorded']);

        $repo = new \HomeCare\Repository\AuditRepository($this->getDb());
        $rows = $repo->search(['action' => 'intake.recorded']);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertEquals('intake.recorded', $row['action']);
        }
    }

    public function testFilterByDateRange(): void
    {
        $past = date('Y-m-d H:i:s', strtotime('-2 days'));
        $recent = date('Y-m-d H:i:s');

        $this->insertAuditLog(['created_at' => $past, 'action' => 'old.action']);
        $this->insertAuditLog(['created_at' => $recent, 'action' => 'new.action']);

        $repo = new \HomeCare\Repository\AuditRepository($this->getDb());
        $rows = $repo->search([
            'date_from' => date('Y-m-d', strtotime('-1 day')),
            'date_to' => date('Y-m-d')
        ]);

        $this->assertCount(1, $rows);
        $this->assertEquals('new.action', $rows[0]['action']);
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 75; $i++) {
            $this->insertAuditLog(['action' => 'page.test.' . $i, 'created_at' => date('Y-m-d H:i:s', strtotime("-$i minutes"))]);
        }

        $repo = new \HomeCare\Repository\AuditRepository($this->getDb());
        $rows = $repo->search([], 2, 50);

        $this->assertCount(25, $rows);
        // Check prev link would be in page, but since testing repo, assume pagination correct
    }

    public function testEntityResolution(): void
    {
        // Setup: create patient, medicine, schedule, intake
        $patientFactory = new PatientFactory($this->getDb());
        $medicineFactory = new MedicineFactory($this->getDb());
        $scheduleFactory = new ScheduleFactory($this->getDb());
        $intakeFactory = new IntakeFactory($this->getDb());

        $patient = $patientFactory->create(['name' => 'Test Patient']);
        $medicine = $medicineFactory->create(['name' => 'Test Medicine']);
        $schedule = $scheduleFactory->create([
            'patient_id' => $patient['id'],
            'medicine_id' => $medicine['id']
        ]);
        $intake = $intakeFactory->create(['schedule_id' => $schedule['id']]);

        // Insert audits
        $this->insertAuditLog([
            'action' => 'patient.created',
            'entity_type' => 'patient',
            'entity_id' => $patient['id']
        ]);
        $this->insertAuditLog([
            'action' => 'medicine.created',
            'entity_type' => 'medicine',
            'entity_id' => $medicine['id']
        ]);
        $this->insertAuditLog([
            'action' => 'schedule.created',
            'entity_type' => 'schedule',
            'entity_id' => $schedule['id']
        ]);
        $this->insertAuditLog([
            'action' => 'intake.recorded',
            'entity_type' => 'intake',
            'entity_id' => $intake['id']
        ]);

        $repo = new \HomeCare\Repository\AuditRepository($this->getDb());
        $rows = $repo->search([]);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            if ($row['action'] === 'patient.created') {
                $this->assertEquals('Test Patient', $row['patient_name']);
            } elseif ($row['action'] === 'medicine.created') {
                $this->assertEquals('Test Medicine', $row['medicine_name']);
            } elseif ($row['action'] === 'schedule.created') {
                $this->assertEquals('Test Patient', $row['patient_name']);
                $this->assertEquals('Test Medicine', $row['medicine_name']);
            } elseif ($row['action'] === 'intake.recorded') {
                $this->assertEquals('Test Patient', $row['patient_name']);
                $this->assertEquals('Test Medicine', $row['medicine_name']);
            }
        }
    }
}