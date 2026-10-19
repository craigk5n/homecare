<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Config;

use HomeCare\Config\ReverseProxyConfig;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Integration tests for ReverseProxyConfig (HC-143).
 */
final class ReverseProxyConfigTest extends DatabaseTestCase
{
    private ReverseProxyConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new ReverseProxyConfig($this->getDb());
    }

    public function testDefaultsWhenNoRowsExist(): void
    {
        self::assertSame('native', $this->config->getAuthMode());
        self::assertFalse($this->config->isReverseProxyMode());
        self::assertSame('X-Forwarded-User', $this->config->getHeader());
    }

    public function testSetAndGetAuthMode(): void
    {
        $this->config->setAuthMode('reverse_proxy');

        self::assertSame('reverse_proxy', $this->config->getAuthMode());
        self::assertTrue($this->config->isReverseProxyMode());
    }

    public function testSetAuthModeBackToNative(): void
    {
        $this->config->setAuthMode('reverse_proxy');
        $this->config->setAuthMode('native');

        self::assertSame('native', $this->config->getAuthMode());
        self::assertFalse($this->config->isReverseProxyMode());
    }

    public function testInvalidAuthModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->setAuthMode('invalid');
    }

    public function testSetAndGetHeader(): void
    {
        $this->config->setHeader('X-Remote-User');

        self::assertSame('X-Remote-User', $this->config->getHeader());
    }

    public function testGetAllReturnsFullState(): void
    {
        $this->config->setAuthMode('reverse_proxy');
        $this->config->setHeader('X-Auth-User');

        $all = $this->config->getAll();

        self::assertSame('reverse_proxy', $all['auth_mode']);
        self::assertSame('X-Auth-User', $all['header']);
    }

    public function testAutoProvisionDefaults(): void
    {
        self::assertFalse($this->config->isAutoProvision());
        self::assertSame('Remote-Groups', $this->config->getGroupsHeader());
        self::assertSame([], $this->config->getGroupsForRole('admin'));
        self::assertSame([], $this->config->getGroupsForRole('caregiver'));
        self::assertSame([], $this->config->getGroupsForRole('viewer'));
        self::assertSame('viewer', $this->config->getDefaultRole());
        self::assertFalse($this->config->isSyncRole());
        self::assertSame('', $this->config->getNameHeader());
        self::assertSame('', $this->config->getEmailHeader());
    }

    public function testAutoProvisionFlagIsFailClosed(): void
    {
        $this->getDb()->execute(
            "INSERT INTO hc_config (setting, value) VALUES ('reverse_proxy_auto_provision', 'yes')",
        );

        self::assertFalse($this->config->isAutoProvision());

        $this->config->setAutoProvision(true);
        self::assertTrue($this->config->isAutoProvision());
        $this->config->setAutoProvision(false);
        self::assertFalse($this->config->isAutoProvision());
    }

    public function testSyncRoleRoundTrips(): void
    {
        $this->config->setSyncRole(true);
        self::assertTrue($this->config->isSyncRole());
        $this->config->setSyncRole(false);
        self::assertFalse($this->config->isSyncRole());
    }

    public function testGroupsForRoleAreParsedFromCommaList(): void
    {
        $this->config->setGroupsForRole('admin', ' homecare-admins , it ');
        $this->config->setGroupsForRole('caregiver', 'family|carers');

        self::assertSame(['homecare-admins', 'it'], $this->config->getGroupsForRole('admin'));
        self::assertSame(['family', 'carers'], $this->config->getGroupsForRole('caregiver'));
        self::assertSame('admin', $this->config->getGroupMapper()->roleFor(['it']));
        self::assertSame('caregiver', $this->config->getGroupMapper()->roleFor(['carers']));
    }

    public function testGroupListStoredNormalised(): void
    {
        $this->config->setGroupsForRole('viewer', ' a ,, b | c ');

        $all = $this->config->getAll();
        self::assertSame('a,b,c', $all['viewer_groups']);
    }

    public function testUnknownRoleForGroupsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->setGroupsForRole('superuser', 'x');
    }

    public function testOverlongGroupListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->setGroupsForRole('admin', str_repeat('g', 129));
    }

    public function testDefaultRoleAcceptsViewerCaregiverOrNone(): void
    {
        $this->config->setDefaultRole('caregiver');
        self::assertSame('caregiver', $this->config->getDefaultRole());
        $this->config->setDefaultRole('none');
        self::assertSame('none', $this->config->getDefaultRole());
        $this->config->setDefaultRole('viewer');
        self::assertSame('viewer', $this->config->getDefaultRole());
    }

    public function testDefaultRoleRejectsAdmin(): void
    {
        // Granting admin to anyone the proxy lets through is never a
        // sensible fallback -- admin must come from an explicit group.
        $this->expectException(\InvalidArgumentException::class);
        $this->config->setDefaultRole('admin');
    }

    public function testStoredGarbageDefaultRoleFallsBackToViewer(): void
    {
        $this->getDb()->execute(
            "INSERT INTO hc_config (setting, value) VALUES ('reverse_proxy_default_role', 'admin')",
        );

        self::assertSame('viewer', $this->config->getDefaultRole());
    }

    public function testHeadersRoundTrip(): void
    {
        $this->config->setGroupsHeader('X-authentik-groups');
        $this->config->setNameHeader('X-authentik-name');
        $this->config->setEmailHeader('X-authentik-email');

        self::assertSame('X-authentik-groups', $this->config->getGroupsHeader());
        self::assertSame('X-authentik-name', $this->config->getNameHeader());
        self::assertSame('X-authentik-email', $this->config->getEmailHeader());

        // Empty clears the optional headers.
        $this->config->setNameHeader('');
        self::assertSame('', $this->config->getNameHeader());
    }

    public function testEmptyGroupsHeaderFallsBackToDefault(): void
    {
        $this->config->setGroupsHeader('');

        self::assertSame('Remote-Groups', $this->config->getGroupsHeader());
    }

    public function testInvalidHeaderNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config->setGroupsHeader("X-Groups\r\nEvil: 1");
    }
}
