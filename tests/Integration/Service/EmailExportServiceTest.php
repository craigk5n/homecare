<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Config\EmailConfig;
use HomeCare\Export\CsvIntakeExporter;
use HomeCare\Export\FhirIntakeExporter;
use HomeCare\Export\IntakeExportQuery;
use HomeCare\Report\MedicationSummaryReport;
use HomeCare\Repository\InventoryRepository;
use HomeCare\Repository\ScheduleRepository;
use HomeCare\Service\EmailExportService;
use HomeCare\Service\InventoryService;
use HomeCare\Tests\Integration\DatabaseTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Captures every Symfony Email handed in so assertions can
 * verify subject / attachment / body shape without bringing up
 * a real mail server.
 */
final class RecordingExportMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }
    }
}

final class EmailExportServiceTest extends DatabaseTestCase
{
    private RecordingExportMailer $mailer;

    /** @var list<array{action:string,entity_type:string,entity_id:?int,details:array<string,mixed>}> */
    private array $auditEvents;

    private EmailExportService $service;

    private int $patientId;

    private int $scheduleId;

    private int $currentClock;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->getDb();
        $db->execute("INSERT INTO hc_patients (name) VALUES (?)", ['Daisy']);
        $this->patientId = $db->lastInsertId();
        $db->execute(
            "INSERT INTO hc_medicines (name, dosage) VALUES (?, ?)",
            ['Tobra', '2 drops']
        );
        $medicineId = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_schedules
                (patient_id, medicine_id, start_date, frequency, unit_per_dose)
             VALUES (?, ?, ?, ?, ?)',
            [$this->patientId, $medicineId, '2026-01-01', '8h', 1.0]
        );
        $this->scheduleId = $db->lastInsertId();
        $db->execute(
            'INSERT INTO hc_medicine_intake (schedule_id, taken_time, note)
             VALUES (?, ?, ?)',
            [$this->scheduleId, '2026-04-10 08:00:00', 'morning']
        );

        // Minimal email config so isReady() passes.
        $config = new EmailConfig($db);
        $config->setDsn('null://default');
        $config->setFromAddress('no-reply@homecare.local');
        $config->setFromName('HomeCare');
        $config->setEnabled(true);

        $this->mailer = new RecordingExportMailer();
        $this->auditEvents = [];
        $this->currentClock = (int) strtotime('2026-04-16 12:00:00');

        $this->service = new EmailExportService(
            db:            $db,
            emailConfig:   $config,
            exportQuery:   new IntakeExportQuery($db),
            csvExporter:   new CsvIntakeExporter(),
            fhirExporter:  new FhirIntakeExporter(),
            summaryReport: new MedicationSummaryReport(
                $db,
                new InventoryService(new InventoryRepository($db), new ScheduleRepository($db)),
            ),
            mailer:        $this->mailer,
            clock:         fn (): string => date('Y-m-d H:i:s', $this->currentClock),
            audit:         function (string $a, string $e, ?int $id, array $d): void {
                /** @var array<string,mixed> $d */
                $this->auditEvents[] = [
                    'action' => $a,
                    'entity_type' => $e,
                    'entity_id' => $id,
                    'details' => $d,
                ];
                // Also persist to hc_audit_log so the rate-limit
                // count picks it up — the real audit_log() helper
                // does this in production.
                $this->getDb()->execute(
                    'INSERT INTO hc_audit_log
                        (user_login, action, entity_type, entity_id,
                         details, ip_address, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        'alice', $a, $e, $id,
                        json_encode($d),
                        '127.0.0.1',
                        date('Y-m-d H:i:s', $this->currentClock),
                    ]
                );
            },
        );
    }

    public function testCsvSendAttachesExpectedBytes(): void
    {
        $result = $this->service->sendCsvExport(
            'alice',
            'alice@example.org',
            $this->patientId,
            '2026-04-01',
            '2026-04-30',
        );

        $this->assertTrue($result['ok'], (string) $result['reason']);
        $this->assertGreaterThan(0, $result['size_bytes']);
        $this->assertCount(1, $this->mailer->sent);

        $email = $this->mailer->sent[0];
        $this->assertStringContainsString('Daisy', (string) $email->getSubject());
        $this->assertStringContainsString('Intake export', (string) $email->getSubject());

        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $body = $attachments[0]->getBody();
        $this->assertStringContainsString('Date,Time,Medication', $body);
        $this->assertStringContainsString('Tobra', $body);
    }

    public function testFhirSendAttachesJson(): void
    {
        $result = $this->service->sendFhirExport(
            'alice',
            'alice@example.org',
            $this->patientId,
            '2026-04-01',
            '2026-04-30',
        );

        $this->assertTrue($result['ok'], (string) $result['reason']);
        $email = $this->mailer->sent[0];
        $attachment = $email->getAttachments()[0];
        $payload = json_decode($attachment->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertSame('Bundle', $payload['resourceType'] ?? null);
    }

    public function testMedicationSummarySendsPlainTextBody(): void
    {
        $result = $this->service->sendMedicationSummary(
            'alice',
            'alice@example.org',
            $this->patientId,
        );

        $this->assertTrue($result['ok'], (string) $result['reason']);
        $email = $this->mailer->sent[0];
        $this->assertSame([], $email->getAttachments(), 'summary is inline, no attachment');
        $this->assertStringContainsString('Daisy', (string) $email->getTextBody());
        $this->assertStringContainsString('Active medications', (string) $email->getTextBody());
    }

    public function testRateLimitTriggersOnFourthSendWithinAnHour(): void
    {
        for ($i = 0; $i < EmailExportService::MAX_PER_HOUR; $i++) {
            $result = $this->service->sendCsvExport(
                'alice',
                'alice@example.org',
                $this->patientId,
                '2026-04-01',
                '2026-04-30',
            );
            $this->assertTrue($result['ok'], "send {$i} should succeed");
        }

        $fourth = $this->service->sendCsvExport(
            'alice',
            'alice@example.org',
            $this->patientId,
            '2026-04-01',
            '2026-04-30',
        );

        $this->assertFalse($fourth['ok']);
        $this->assertSame('rate_limited', $fourth['reason']);
    }

    public function testRateLimitResetsAfterAnHour(): void
    {
        for ($i = 0; $i < EmailExportService::MAX_PER_HOUR; $i++) {
            $this->service->sendCsvExport(
                'alice',
                'alice@example.org',
                $this->patientId,
                '2026-04-01',
                '2026-04-30',
            );
        }

        $this->currentClock += 3601;
        $after = $this->service->sendCsvExport(
            'alice',
            'alice@example.org',
            $this->patientId,
            '2026-04-01',
            '2026-04-30',
        );

        $this->assertTrue($after['ok'], 'rate limit should reset after 1h');
    }

    public function testRateLimitIsPerUser(): void
    {
        for ($i = 0; $i < EmailExportService::MAX_PER_HOUR; $i++) {
            $this->service->sendCsvExport(
                'alice',
                'alice@example.org',
                $this->patientId,
                '2026-04-01',
                '2026-04-30',
            );
        }

        $bobResult = $this->service->sendCsvExport(
            'bob',
            'bob@example.org',
            $this->patientId,
            '2026-04-01',
            '2026-04-30',
        );

        $this->assertTrue($bobResult['ok'],
            "alice's quota must not affect bob");
    }

    public function testInvalidRecipientIsRejected(): void
    {
        $result = $this->service->sendCsvExport(
            'alice',
            'not-an-email',
            $this->patientId,
            '2026-04-01',
            '2026-04-30',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_recipient', $result['reason']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testAuditRowCarriesExpectedDetails(): void
    {
        $this->service->sendCsvExport(
            'alice',
            'alice@example.org',
            $this->patientId,
            '2026-04-01',
            '2026-04-30',
        );

        $this->assertCount(1, $this->auditEvents);
        $event = $this->auditEvents[0];
        $this->assertSame('export.emailed', $event['action']);
        $this->assertSame('csv', $event['details']['type']);
        $this->assertSame($this->patientId, $event['details']['patient_id']);
        $this->assertSame('2026-04-01', $event['details']['start_date']);
        $this->assertSame('2026-04-30', $event['details']['end_date']);
        $this->assertGreaterThan(0, $event['details']['size_bytes']);
    }

    public function testEmailDisabledConfigIsRejected(): void
    {
        $db = $this->getDb();
        $config = new EmailConfig($db);
        $config->setEnabled(false);

        $svc = new EmailExportService(
            db:            $db,
            emailConfig:   $config,
            exportQuery:   new IntakeExportQuery($db),
            csvExporter:   new CsvIntakeExporter(),
            fhirExporter:  new FhirIntakeExporter(),
            summaryReport: new MedicationSummaryReport(
                $db,
                new InventoryService(new InventoryRepository($db), new ScheduleRepository($db)),
            ),
            mailer:        $this->mailer,
        );

        $result = $svc->sendCsvExport(
            'alice',
            'alice@example.org',
            $this->patientId,
            '2026-04-01',
            '2026-04-30',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('email_disabled', $result['reason']);
    }
}
