<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\Authorization;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuthorizationTest extends TestCase
{
    public function testGetCurrentRoleRoundTrips(): void
    {
        $this->assertSame('admin', (new Authorization('admin'))->getCurrentRole());
        $this->assertSame('caregiver', (new Authorization('caregiver'))->getCurrentRole());
        $this->assertSame('viewer', (new Authorization('viewer'))->getCurrentRole());
    }

    public function testAdminCanWriteAndAdmin(): void
    {
        $auth = new Authorization('admin');
        $this->assertTrue($auth->canWrite());
        $this->assertTrue($auth->canAdmin());
    }

    public function testCaregiverCanWriteButNotAdmin(): void
    {
        $auth = new Authorization('caregiver');
        $this->assertTrue($auth->canWrite());
        $this->assertFalse($auth->canAdmin());
    }

    public function testViewerCannotWriteOrAdmin(): void
    {
        $auth = new Authorization('viewer');
        $this->assertFalse($auth->canWrite());
        $this->assertFalse($auth->canAdmin());
    }

    public function testHierarchyAdminBeatsCaregiverBeatsViewer(): void
    {
        $admin = new Authorization('admin');
        $caregiver = new Authorization('caregiver');
        $viewer = new Authorization('viewer');

        $this->assertTrue($admin->satisfies('viewer'));
        $this->assertTrue($admin->satisfies('caregiver'));
        $this->assertTrue($admin->satisfies('admin'));

        $this->assertTrue($caregiver->satisfies('viewer'));
        $this->assertTrue($caregiver->satisfies('caregiver'));
        $this->assertFalse($caregiver->satisfies('admin'));

        $this->assertTrue($viewer->satisfies('viewer'));
        $this->assertFalse($viewer->satisfies('caregiver'));
        $this->assertFalse($viewer->satisfies('admin'));
    }

    public function testUnknownRoleRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown role: superuser');

        new Authorization('superuser');
    }

    public function testUnknownMinimumRoleRejected(): void
    {
        $auth = new Authorization('admin');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown minimum role: god');

        $auth->satisfies('god');
    }

    public function testRoleConstantsAreDistinct(): void
    {
        // The literal values are already statically verified (PHPStan max
        // sees them as type literals). This test guards uniqueness: no
        // two roles may share a string, or the rank table in
        // Authorization::RANKS would collapse.
        $roles = [
            Authorization::ROLE_ADMIN,
            Authorization::ROLE_CAREGIVER,
            Authorization::ROLE_VIEWER,
        ];
        $this->assertSame($roles, array_values(array_unique($roles)));
    }
}
