<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Audit;

use HomeCare\Audit\AuditLogger;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class AuditLoggerTest extends DatabaseTestCase
{
    public function testLogWritesRowWithAllFields(): void
    {
        $logger = new AuditLogger(
            $this->getDb(),
            static fn (): string => 'admin',
            static fn (): string => '10.0.0.5',
            static fn (): string => '2026-04-13 15:00:00',
        );

        $logger->log('intake.recorded', 'schedule', 42, ['source' => 'web', 'dose' => 1.5]);

        $rows = $this->getDb()->query('SELECT * FROM hc_audit_log');
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('admin', $row['user_login']);
        $this->assertSame('intake.recorded', $row['action']);
        $this->assertSame('schedule', $row['entity_type']);
        $this->assertSame(42, (int) $row['entity_id']);
        $this->assertSame('10.0.0.5', $row['ip_address']);
        $this->assertSame('2026-04-13 15:00:00', $row['created_at']);

        $details = json_decode((string) $row['details'], true);
        $this->assertSame(['source' => 'web', 'dose' => 1.5], $details);
    }

    public function testLogWithoutDetailsStoresNull(): void
    {
        $logger = new AuditLogger(
            $this->getDb(),
            static fn (): string => 'alice',
            static fn (): ?string => null,
        );
        $logger->log('user.login');

        $rows = $this->getDb()->query('SELECT details, entity_type, entity_id, ip_address FROM hc_audit_log');
        $this->assertNull($rows[0]['details']);
        $this->assertNull($rows[0]['entity_type']);
        $this->assertNull($rows[0]['entity_id']);
        $this->assertNull($rows[0]['ip_address']);
    }

    public function testAnonymousEvents(): void
    {
        // No login provider -> user_login nulled out, action still recorded.
        $logger = new AuditLogger($this->getDb());
        $logger->log('login.failed', 'user', null, ['attempted_login' => 'ghost']);

        $rows = $this->getDb()->query('SELECT user_login, action, details FROM hc_audit_log');
        $this->assertNull($rows[0]['user_login']);
        $this->assertSame('login.failed', $rows[0]['action']);
        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame(['attempted_login' => 'ghost'], $details);
    }

    public function testMultipleEventsRetainOrder(): void
    {
        $logger = new AuditLogger(
            $this->getDb(),
            static fn (): string => 'admin',
        );

        $logger->log('schedule.created', 'schedule', 1);
        $logger->log('dosage.adjusted', 'schedule', 1, ['new_frequency' => '12h']);
        $logger->log('intake.recorded', 'schedule', 1);

        $rows = $this->getDb()->query('SELECT action FROM hc_audit_log ORDER BY id');
        $actions = array_map(static fn (array $r): string => (string) $r['action'], $rows);
        $this->assertSame(
            ['schedule.created', 'dosage.adjusted', 'intake.recorded'],
            $actions
        );
    }

    public function testSwallowsDatabaseErrorsSilently(): void
    {
        // Drop the table to force the INSERT to fail. The logger must not
        // throw; it should error_log() and return normally.
        $this->getSqliteDb()->pdo()->exec('DROP TABLE hc_audit_log');

        $logger = new AuditLogger(
            $this->getDb(),
            static fn (): string => 'admin',
        );

        // Capture error_log() output -- set to a temp file for the duration.
        $tmp = tempnam(sys_get_temp_dir(), 'audit-err-');
        $this->assertNotFalse($tmp);
        $prev = ini_set('error_log', $tmp);

        try {
            // If this throws, the test fails with an uncaught exception --
            // which is exactly the contract we're guarding: log() must
            // swallow DB errors, not propagate them.
            $logger->log('intake.recorded', 'schedule', 1);
            $errContent = (string) file_get_contents($tmp);
            $this->assertStringContainsString('AuditLogger', $errContent);
        } finally {
            if ($prev !== false) {
                ini_set('error_log', $prev);
            }
            @unlink($tmp);
        }
    }
}
