<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Auth;

use HomeCare\Audit\AuditLogger;
use HomeCare\Auth\AuthService;
use HomeCare\Auth\PasswordHasher;
use HomeCare\Auth\ReverseProxyAuth;
use HomeCare\Config\ReverseProxyConfig;
use HomeCare\Repository\UserRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * Integration tests for HC-243: opt-in auto-provisioning of hc_user rows
 * from reverse-proxy headers, with group → role mapping.
 */
final class ReverseProxyAutoProvisionTest extends DatabaseTestCase
{
    private ReverseProxyAuth $auth;
    private ReverseProxyConfig $config;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->getDb();
        $this->config = new ReverseProxyConfig($db);
        $this->config->setAuthMode('reverse_proxy');
        $this->config->setHeader('Remote-User');
        $this->config->setGroupsForRole('admin', 'homecare-admins');
        $this->config->setGroupsForRole('caregiver', 'family, carers');
        $this->config->setGroupsForRole('viewer', 'friends');

        $db->execute(
            "INSERT INTO hc_user (login, passwd, firstname, lastname, email, role, enabled)
             VALUES ('disabled_bob', 'unused', 'Bob', 'Disabled', 'bob@example.com', 'viewer', 'N')",
        );
        $db->execute(
            "INSERT INTO hc_user (login, passwd, firstname, lastname, email, role, enabled)
             VALUES ('carol', 'unused', 'Carol', 'Manual', 'carol@example.com', 'admin', 'Y')",
        );

        $this->users = new UserRepository($db);
        $this->auth = new ReverseProxyAuth(
            $this->config,
            $this->users,
            new AuditLogger($db, null, null, static fn(): string => '2026-10-19 09:00:00'),
        );
    }

    // -- off by default -------------------------------------------------

    public function testUnknownUserStillRefusedWhenAutoProvisionOff(): void
    {
        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'newbie',
            'HTTP_REMOTE_GROUPS' => 'homecare-admins',
        ]);

        self::assertFalse($result->success);
        self::assertSame('reverse_proxy_user_not_found', $result->reason);
        self::assertNull($this->users->findByLogin('newbie'));
        self::assertSame(0, $this->auditCount());
    }

    public function testExistingUserRoleUntouchedByDefault(): void
    {
        // Groups header says "friends" (viewer) but sync is off: carol's
        // manually-assigned admin role sticks.
        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'carol',
            'HTTP_REMOTE_GROUPS' => 'friends',
        ]);

        self::assertTrue($result->success);
        self::assertNotNull($result->user);
        self::assertSame('admin', $result->user['role']);
        self::assertSame('admin', $this->roleOf('carol'));
    }

    // -- provisioning ---------------------------------------------------

    public function testProvisionsUnknownUserWithMappedRole(): void
    {
        $this->config->setAutoProvision(true);

        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'dave',
            'HTTP_REMOTE_GROUPS' => 'family',
        ]);

        self::assertTrue($result->success);
        self::assertNotNull($result->user);
        self::assertSame('dave', $result->user['login']);
        self::assertSame('caregiver', $result->user['role']);
        self::assertSame('Y', $result->user['enabled']);

        $stored = $this->users->findByLogin('dave');
        self::assertNotNull($stored);
        self::assertSame('caregiver', $stored['role']);
    }

    public function testProvisionedUserHasNoUsablePassword(): void
    {
        $this->config->setAutoProvision(true);
        $this->auth->authenticate(['HTTP_REMOTE_USER' => 'dave']);

        $rows = $this->getDb()->query("SELECT passwd FROM hc_user WHERE login = 'dave'");
        self::assertNull($rows[0]['passwd']);

        // Native login must not accept an empty (or any) password.
        $native = new AuthService($this->users, new PasswordHasher());
        self::assertFalse($native->attemptLogin('dave', '')->success);
        self::assertFalse($native->attemptLogin('dave', 'dave')->success);
    }

    public function testProvisioningIsAuditLogged(): void
    {
        $this->config->setAutoProvision(true);
        $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'dave',
            'HTTP_REMOTE_GROUPS' => 'family|friends',
            'REMOTE_ADDR' => '10.0.0.5',
        ]);

        $rows = $this->getDb()->query(
            'SELECT action, entity_type, details, created_at FROM hc_audit_log',
        );
        self::assertCount(1, $rows);
        self::assertSame('user.auto_provisioned', $rows[0]['action']);
        self::assertSame('user', $rows[0]['entity_type']);
        self::assertSame('2026-10-19 09:00:00', $rows[0]['created_at']);

        $details = json_decode((string) $rows[0]['details'], true);
        self::assertIsArray($details);
        self::assertSame('dave', $details['login']);
        self::assertSame('caregiver', $details['role']);
        self::assertSame(['family', 'friends'], $details['groups']);
    }

    public function testSecondRequestDoesNotReprovision(): void
    {
        $this->config->setAutoProvision(true);
        $server = ['HTTP_REMOTE_USER' => 'dave', 'HTTP_REMOTE_GROUPS' => 'family'];

        self::assertTrue($this->auth->authenticate($server)->success);
        self::assertTrue($this->auth->authenticate($server)->success);

        self::assertSame(1, $this->auditCount());
    }

    public function testAuthentikPipeSeparatedGroupsHeader(): void
    {
        $this->config->setAutoProvision(true);
        $this->config->setHeader('X-authentik-username');
        $this->config->setGroupsHeader('X-authentik-groups');

        $result = $this->auth->authenticate([
            'HTTP_X_AUTHENTIK_USERNAME' => 'erin',
            'HTTP_X_AUTHENTIK_GROUPS' => 'authentik Admins| homecare-admins |family',
        ]);

        self::assertTrue($result->success);
        self::assertSame('admin', $this->roleOf('erin'));
    }

    public function testAutheliaCommaSeparatedGroupsHeader(): void
    {
        $this->config->setAutoProvision(true);

        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'frank',
            'HTTP_REMOTE_GROUPS' => 'friends, carers ,dev',
        ]);

        self::assertTrue($result->success);
        self::assertSame('caregiver', $this->roleOf('frank'));
    }

    public function testHighestRoleWins(): void
    {
        $this->config->setAutoProvision(true);

        $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'gina',
            'HTTP_REMOTE_GROUPS' => 'friends,family,homecare-admins',
        ]);

        self::assertSame('admin', $this->roleOf('gina'));
    }

    public function testNoMatchingGroupGetsDefaultRole(): void
    {
        $this->config->setAutoProvision(true);

        $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'hank',
            'HTTP_REMOTE_GROUPS' => 'unrelated',
        ]);

        self::assertSame('viewer', $this->roleOf('hank'));
    }

    public function testMissingGroupsHeaderGetsConfiguredDefaultRole(): void
    {
        $this->config->setAutoProvision(true);
        $this->config->setDefaultRole('caregiver');

        $this->auth->authenticate(['HTTP_REMOTE_USER' => 'hank']);

        self::assertSame('caregiver', $this->roleOf('hank'));
    }

    public function testRefuseModeRejectsUnmatchedUserAndCreatesNothing(): void
    {
        $this->config->setAutoProvision(true);
        $this->config->setDefaultRole('none');

        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'ivan',
            'HTTP_REMOTE_GROUPS' => 'unrelated',
        ]);

        self::assertFalse($result->success);
        self::assertSame('reverse_proxy_no_matching_group', $result->reason);
        self::assertNull($this->users->findByLogin('ivan'));
        self::assertSame(0, $this->auditCount());
    }

    public function testRefuseModeStillProvisionsMatchedUser(): void
    {
        $this->config->setAutoProvision(true);
        $this->config->setDefaultRole('none');

        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'ivan',
            'HTTP_REMOTE_GROUPS' => 'friends',
        ]);

        self::assertTrue($result->success);
        self::assertSame('viewer', $this->roleOf('ivan'));
    }

    public function testNameAndEmailHeadersFillNewUser(): void
    {
        $this->config->setAutoProvision(true);
        $this->config->setNameHeader('Remote-Name');
        $this->config->setEmailHeader('Remote-Email');

        $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'jane',
            'HTTP_REMOTE_NAME' => '  Jane  van der Berg ',
            'HTTP_REMOTE_EMAIL' => 'jane@example.com',
        ]);

        $rows = $this->getDb()->query(
            "SELECT firstname, lastname, email FROM hc_user WHERE login = 'jane'",
        );
        self::assertSame('Jane', $rows[0]['firstname']);
        self::assertSame('van der Berg', $rows[0]['lastname']);
        self::assertSame('jane@example.com', $rows[0]['email']);
    }

    public function testInvalidEmailAndOverlongNameAreSanitised(): void
    {
        $this->config->setAutoProvision(true);
        $this->config->setNameHeader('Remote-Name');
        $this->config->setEmailHeader('Remote-Email');

        $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'kim',
            'HTTP_REMOTE_NAME' => str_repeat('K', 40) . " \x07" . str_repeat('L', 40),
            'HTTP_REMOTE_EMAIL' => 'not-an-email',
        ]);

        $rows = $this->getDb()->query(
            "SELECT firstname, lastname, email FROM hc_user WHERE login = 'kim'",
        );
        self::assertSame(str_repeat('K', 25), $rows[0]['firstname']);
        self::assertSame(str_repeat('L', 25), $rows[0]['lastname']);
        self::assertNull($rows[0]['email']);
    }

    public function testNameAndEmailHeadersIgnoredWhenNotConfigured(): void
    {
        $this->config->setAutoProvision(true);

        $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'lee',
            'HTTP_REMOTE_NAME' => 'Lee Smith',
            'HTTP_REMOTE_EMAIL' => 'lee@example.com',
        ]);

        $rows = $this->getDb()->query(
            "SELECT firstname, lastname, email FROM hc_user WHERE login = 'lee'",
        );
        self::assertNull($rows[0]['firstname']);
        self::assertNull($rows[0]['lastname']);
        self::assertNull($rows[0]['email']);
    }

    // -- login validation -----------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function invalidLoginProvider(): array
    {
        return [
            'too long (26 chars)' => [str_repeat('a', 26)],
            'space inside' => ['bad user'],
            'quote' => ["o'brien"],
            'angle bracket' => ['<script>'],
            'slash' => ['a/b'],
            'non-ascii' => ['josé'],
            'leading dot' => ['.hidden'],
            'control char' => ["a\x00b"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLoginProvider')]
    public function testInvalidLoginIsNotProvisioned(string $login): void
    {
        $this->config->setAutoProvision(true);

        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => $login,
            'HTTP_REMOTE_GROUPS' => 'homecare-admins',
        ]);

        self::assertFalse($result->success);
        self::assertSame('reverse_proxy_invalid_login', $result->reason);
        $count = $this->getDb()->query('SELECT COUNT(*) AS n FROM hc_user');
        self::assertSame(2, (int) $count[0]['n']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validLoginProvider(): array
    {
        return [
            'plain' => ['mary'],
            'max length (25 chars)' => [str_repeat('m', 25)],
            'email-style' => ['mary.jones@example.org'],
            'dash underscore digits' => ['mary_jones-2'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validLoginProvider')]
    public function testValidLoginIsProvisioned(string $login): void
    {
        $this->config->setAutoProvision(true);

        $result = $this->auth->authenticate(['HTTP_REMOTE_USER' => $login]);

        self::assertTrue($result->success);
        self::assertNotNull($this->users->findByLogin($login));
    }

    // -- disabled users -------------------------------------------------

    public function testDisabledUserStaysRefusedWithAutoProvisionAndSync(): void
    {
        $this->config->setAutoProvision(true);
        $this->config->setSyncRole(true);

        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'disabled_bob',
            'HTTP_REMOTE_GROUPS' => 'homecare-admins',
        ]);

        self::assertFalse($result->success);
        self::assertSame('account_disabled', $result->reason);

        $bob = $this->users->findByLogin('disabled_bob');
        self::assertNotNull($bob);
        self::assertSame('N', $bob['enabled']);
        self::assertSame('viewer', $bob['role']);
        self::assertSame(0, $this->auditCount());
    }

    // -- role sync ------------------------------------------------------

    public function testSyncOnDemotesExistingUserAndAuditLogs(): void
    {
        $this->config->setSyncRole(true);

        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'carol',
            'HTTP_REMOTE_GROUPS' => 'friends',
        ]);

        self::assertTrue($result->success);
        self::assertNotNull($result->user);
        self::assertSame('viewer', $result->user['role']);
        self::assertSame('viewer', $this->roleOf('carol'));

        $rows = $this->getDb()->query('SELECT action, details FROM hc_audit_log');
        self::assertCount(1, $rows);
        self::assertSame('user.role_synced', $rows[0]['action']);
        $details = json_decode((string) $rows[0]['details'], true);
        self::assertIsArray($details);
        self::assertSame('admin', $details['old_role']);
        self::assertSame('viewer', $details['new_role']);
    }

    public function testSyncOnPromotesProvisionedUserOnLaterRequest(): void
    {
        $this->config->setAutoProvision(true);
        $this->config->setSyncRole(true);

        $this->auth->authenticate(['HTTP_REMOTE_USER' => 'dave', 'HTTP_REMOTE_GROUPS' => 'friends']);
        self::assertSame('viewer', $this->roleOf('dave'));

        $this->auth->authenticate(['HTTP_REMOTE_USER' => 'dave', 'HTTP_REMOTE_GROUPS' => 'family']);
        self::assertSame('caregiver', $this->roleOf('dave'));
    }

    public function testSyncOnUnchangedRoleWritesNoAudit(): void
    {
        $this->config->setSyncRole(true);

        $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'carol',
            'HTTP_REMOTE_GROUPS' => 'homecare-admins',
        ]);

        self::assertSame('admin', $this->roleOf('carol'));
        self::assertSame(0, $this->auditCount());
    }

    public function testSyncOffKeepsManualRoleChange(): void
    {
        $this->config->setAutoProvision(true);

        $this->auth->authenticate(['HTTP_REMOTE_USER' => 'dave', 'HTTP_REMOTE_GROUPS' => 'friends']);
        // Admin promotes dave by hand.
        $this->getDb()->execute("UPDATE hc_user SET role = 'caregiver' WHERE login = 'dave'");

        $this->auth->authenticate(['HTTP_REMOTE_USER' => 'dave', 'HTTP_REMOTE_GROUPS' => 'friends']);

        self::assertSame('caregiver', $this->roleOf('dave'));
    }

    public function testSyncOnWithRefuseModeRejectsUnmatchedExistingUser(): void
    {
        $this->config->setSyncRole(true);
        $this->config->setDefaultRole('none');

        $result = $this->auth->authenticate([
            'HTTP_REMOTE_USER' => 'carol',
            'HTTP_REMOTE_GROUPS' => 'unrelated',
        ]);

        self::assertFalse($result->success);
        self::assertSame('reverse_proxy_no_matching_group', $result->reason);
        // Refusal doesn't rewrite the stored role.
        self::assertSame('admin', $this->roleOf('carol'));
    }

    public function testWorksWithoutAuditLogger(): void
    {
        $auth = new ReverseProxyAuth($this->config, $this->users);
        $this->config->setAutoProvision(true);

        self::assertTrue($auth->authenticate(['HTTP_REMOTE_USER' => 'nolog'])->success);
        self::assertSame(0, $this->auditCount());
    }

    private function roleOf(string $login): ?string
    {
        $user = $this->users->findByLogin($login);

        return $user === null ? null : $user['role'];
    }

    private function auditCount(): int
    {
        $rows = $this->getDb()->query('SELECT COUNT(*) AS n FROM hc_audit_log');

        return (int) $rows[0]['n'];
    }
}
