<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Export;

use PHPUnit\Framework\TestCase;

/**
 * `parse_export_date()` is a function under includes/homecare.php (it's
 * a page helper, not a class method, per the codebase's WebCalendar-
 * derived convention — CLAUDE.md: "new HomeCare-specific helpers go in
 * includes/homecare.php").
 *
 * These tests load the function once via require_once so the unit suite
 * stays fast and doesn't need init.php's full bootstrap.
 */
final class ParseExportDateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The function sits in the global namespace; load it once.
        $file = __DIR__ . '/../../../includes/homecare.php';
        // includes/homecare.php expects composer autoload to have set up
        // the HomeCare\ classes it type-hints; phpunit's bootstrap already
        // does that via vendor/autoload.php.
        if (!function_exists('parse_export_date')) {
            require_once $file;
        }
    }

    /** @dataProvider validDateCases */
    public function testValidFormatsNormalizeToHyphenated(string $input, string $expected): void
    {
        $this->assertSame($expected, parse_export_date($input, '1999-01-01'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function validDateCases(): iterable
    {
        yield 'hyphenated'           => ['2026-02-14', '2026-02-14'];
        yield 'compact YYYYMMDD'     => ['20260214',   '2026-02-14'];
        yield 'leap-day hyphenated'  => ['2024-02-29', '2024-02-29'];
        yield 'leap-day compact'     => ['20240229',   '2024-02-29'];
        yield 'trailing whitespace'  => ['20260214  ', '2026-02-14'];
        yield 'leading whitespace'   => ['  20260214', '2026-02-14'];
    }

    /** @dataProvider invalidDateCases */
    public function testInvalidOrNonStringFallsBackToDefault(mixed $input): void
    {
        $this->assertSame('1999-01-01', parse_export_date($input, '1999-01-01'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDateCases(): iterable
    {
        yield 'empty'               => [''];
        yield 'non-string null'     => [null];
        yield 'non-string array'    => [['2026-02-14']];
        yield 'nonsense letters'    => ['hello'];
        yield 'malformed hyphens'   => ['2026/02/14'];
        yield 'too short'           => ['202602'];
        yield 'too long'            => ['20260214T'];
        yield 'invalid month'       => ['2026-13-01'];
        yield 'invalid day'         => ['2026-02-30'];
        yield 'invalid compact day' => ['20260230'];
        yield 'non-leap year Feb 29' => ['20260229'];
    }
}
