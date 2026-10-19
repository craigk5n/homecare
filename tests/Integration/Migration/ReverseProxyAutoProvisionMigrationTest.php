<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Migration;

use HomeCare\Config\ReverseProxyConfig;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Tests migrations/036_reverse_proxy_auto_provision.sql (HC-243).
 *
 * The migration only seeds hc_config rows; the point of the test is that
 * the seeded values are the same "off" defaults ReverseProxyConfig uses
 * when the rows are missing, so applying it changes nothing.
 */
final class ReverseProxyAutoProvisionMigrationTest extends DatabaseTestCase
{
    public function testSeededValuesMatchCodeDefaults(): void
    {
        $config = new ReverseProxyConfig($this->getDb());
        $before = $config->getAll();

        $this->applyMigration();

        $rows = $this->getDb()->query(
            "SELECT COUNT(*) AS n FROM hc_config WHERE setting LIKE 'reverse_proxy_%'",
        );
        self::assertSame(9, (int) $rows[0]['n']);
        self::assertSame($before, $config->getAll());
        self::assertFalse($config->isAutoProvision());
    }

    public function testDoesNotOverwriteExistingValues(): void
    {
        $config = new ReverseProxyConfig($this->getDb());
        $config->setAutoProvision(true);
        $config->setGroupsForRole('admin', 'homecare-admins');

        $this->applyMigration();
        $this->applyMigration();

        self::assertTrue($config->isAutoProvision());
        self::assertSame(['homecare-admins'], $config->getGroupsForRole('admin'));
    }

    private function applyMigration(): void
    {
        $sql = (string) file_get_contents(
            __DIR__ . '/../../../migrations/036_reverse_proxy_auto_provision.sql',
        );
        // The file is MySQL dialect; SQLite spells it INSERT OR IGNORE.
        $this->getSqliteDb()->pdo()->exec(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql));
    }
}
