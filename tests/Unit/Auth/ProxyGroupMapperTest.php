<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\ProxyGroupMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProxyGroupMapperTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function groupHeaderProvider(): array
    {
        return [
            'authentik pipe-separated' => ['homecare-admins|family', ['homecare-admins', 'family']],
            'authelia comma-separated' => ['homecare-admins,family', ['homecare-admins', 'family']],
            'whitespace around values' => ['  admins , family  ,  viewers ', ['admins', 'family', 'viewers']],
            'whitespace around pipes' => [' admins | family ', ['admins', 'family']],
            'mixed separators' => ['admins|family,viewers', ['admins', 'family', 'viewers']],
            'empty segments dropped' => ['admins,,|family,', ['admins', 'family']],
            'duplicates collapsed' => ['admins,admins|family', ['admins', 'family']],
            'empty string' => ['', []],
            'only separators' => [' , | ', []],
            'single group' => ['family', ['family']],
            'spaces inside a name kept' => ['Home Care Admins|family', ['Home Care Admins', 'family']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('groupHeaderProvider')]
    public function testParseGroups(string $raw, array $expected): void
    {
        self::assertSame($expected, ProxyGroupMapper::parseGroups($raw));
    }

    public function testNoMatchReturnsNull(): void
    {
        $mapper = new ProxyGroupMapper(['admins'], ['carers'], ['viewers']);

        self::assertNull($mapper->roleFor(['strangers']));
        self::assertNull($mapper->roleFor([]));
    }

    public function testEachRoleMapsIndividually(): void
    {
        $mapper = new ProxyGroupMapper(['admins'], ['carers'], ['viewers']);

        self::assertSame('admin', $mapper->roleFor(['admins']));
        self::assertSame('caregiver', $mapper->roleFor(['carers']));
        self::assertSame('viewer', $mapper->roleFor(['viewers']));
    }

    public function testHighestRoleWins(): void
    {
        $mapper = new ProxyGroupMapper(['admins'], ['carers'], ['viewers']);

        self::assertSame('admin', $mapper->roleFor(['viewers', 'carers', 'admins']));
        self::assertSame('caregiver', $mapper->roleFor(['viewers', 'carers']));
        self::assertSame('caregiver', $mapper->roleFor(['carers', 'viewers', 'other']));
    }

    public function testSameGroupInSeveralListsGetsHighestRole(): void
    {
        $mapper = new ProxyGroupMapper(['family'], ['family'], ['family']);

        self::assertSame('admin', $mapper->roleFor(['family']));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $mapper = new ProxyGroupMapper(['HomeCare-Admins'], [], []);

        self::assertSame('admin', $mapper->roleFor(['homecare-admins']));
    }

    public function testEmptyMappingNeverMatches(): void
    {
        $mapper = new ProxyGroupMapper([], [], []);

        self::assertNull($mapper->roleFor(['admins', '']));
    }
}
