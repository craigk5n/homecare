<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Import;

use HomeCare\Import\NoteTimeParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NoteTimeParserTest extends TestCase
{
    private NoteTimeParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NoteTimeParser();
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function validInputs(): iterable
    {
        yield 'ISO with T and seconds'  => ['2026-04-01T14:30:00', '2026-04-01 14:30:00'];
        yield 'ISO with T no seconds'   => ['2026-04-01T14:30',    '2026-04-01 14:30:00'];
        yield 'ISO with space'          => ['2026-04-01 14:30',    '2026-04-01 14:30:00'];
        yield 'ISO with space+seconds'  => ['2026-04-01 14:30:00', '2026-04-01 14:30:00'];
        yield 'US AM'                   => ['4/1/2026 2:30 AM',    '2026-04-01 02:30:00'];
        yield 'US PM'                   => ['4/1/2026 2:30 PM',    '2026-04-01 14:30:00'];
        yield 'US zero-padded 24h'      => ['04/01/2026 14:30',    '2026-04-01 14:30:00'];
        yield 'US lowercase pm'         => ['4/1/2026 2:30 pm',    '2026-04-01 14:30:00'];
        yield 'ISO date only'           => ['2026-04-01',          '2026-04-01 00:00:00'];
        yield 'US date only'            => ['4/1/2026',            '2026-04-01 00:00:00'];
        yield 'US date only zero-pad'   => ['04/01/2026',          '2026-04-01 00:00:00'];
        yield 'trims whitespace'        => ['  2026-04-01  ',      '2026-04-01 00:00:00'];
    }

    /**
     * @dataProvider validInputs
     */
    public function testParsesValidFormats(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->parser->parse($input));
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty'               => [''];
        yield 'whitespace only'     => ['   '];
        yield 'impossible date'     => ['2026-02-30'];
        yield 'impossible month'    => ['13/1/2026'];
        yield 'trailing garbage'    => ['2026-04-01 banana'];
        yield 'non-date string'     => ['yesterday afternoon'];
        yield 'US with weekday'     => ['Mon 4/1/2026 2:30 PM'];
    }

    /**
     * @dataProvider invalidInputs
     */
    public function testRejectsInvalidFormats(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse($input);
    }
}
