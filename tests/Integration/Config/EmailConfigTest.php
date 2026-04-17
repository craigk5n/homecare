<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Config;

use HomeCare\Config\EmailConfig;
use HomeCare\Database\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class EmailConfigTest extends TestCase
{
    private SqliteDatabase $db;

    private EmailConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new SqliteDatabase();
        $this->db->pdo()->exec(
            'CREATE TABLE hc_config (setting VARCHAR(50) PRIMARY KEY, value VARCHAR(128))',
        );
        $this->config = new EmailConfig($this->db);
    }

    public function testDefaultsOnBlankDatabase(): void
    {
        $this->assertSame('', $this->config->getDsn());
        $this->assertSame('', $this->config->getFromAddress());
        $this->assertSame('HomeCare', $this->config->getFromName());
        $this->assertFalse($this->config->isEnabled());
        $this->assertFalse($this->config->isReady());
    }

    public function testSettersRoundTrip(): void
    {
        $this->config->setDsn('smtp://localhost:1025');
        $this->config->setFromAddress('no-reply@homecare.local');
        $this->config->setFromName('HomeCare Alerts');
        $this->config->setEnabled(true);

        $fresh = new EmailConfig($this->db);

        $this->assertSame('smtp://localhost:1025', $fresh->getDsn());
        $this->assertSame('no-reply@homecare.local', $fresh->getFromAddress());
        $this->assertSame('HomeCare Alerts', $fresh->getFromName());
        $this->assertTrue($fresh->isEnabled());
        $this->assertTrue($fresh->isReady());
    }

    public function testIsReadyRequiresEnabledDsnAndFrom(): void
    {
        $this->config->setEnabled(true);
        $this->assertFalse($this->config->isReady(), 'enabled but no DSN');

        $this->config->setDsn('smtp://localhost:1025');
        $this->assertFalse($this->config->isReady(), 'DSN set but no From');

        $this->config->setFromAddress('x@example.org');
        $this->assertTrue($this->config->isReady());
    }

    public function testEnabledFlagIsStrictY(): void
    {
        // Insert a value other than Y or N — should read as false.
        $this->db->execute(
            "INSERT INTO hc_config (setting, value) VALUES ('smtp_enabled', 'yes')",
        );
        $this->assertFalse($this->config->isEnabled());
    }

    public function testGetAllReturnsTypedArray(): void
    {
        $this->config->setDsn('smtp://localhost:1025');
        $this->config->setFromAddress('x@example.org');
        $this->config->setEnabled(true);

        $all = $this->config->getAll();

        $this->assertSame('smtp://localhost:1025', $all['dsn']);
        $this->assertSame('x@example.org', $all['from_address']);
        $this->assertSame('HomeCare', $all['from_name']);
        $this->assertTrue($all['enabled']);
    }
}
