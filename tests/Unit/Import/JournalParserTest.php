<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Import;

use HomeCare\Import\JournalParser;
use HomeCare\Import\ParsedJournalEntry;
use PHPUnit\Framework\TestCase;

final class JournalParserTest extends TestCase
{
    private JournalParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new JournalParser();
    }

    /**
     * The sample from STATUS.md § HC-085 — must produce 12 entries
     * across 2 dates with 2 non_monotonic flags (Apr 14's 1:20 AM
     * after 9:30 PM and 1:35 AM after 10:00 PM).
     */
    public function testCanonicalSampleYields12EntriesAnd2NonMonotonic(): void
    {
        $text = <<<TXT
Wednesday April 15, 2026

7:45 AM After a slow start, ate everything: Ate 5 kibble, turkey, potatoes and sweet potatoes.

1:00 PM Ate 5 kibble, turkey and some potatoes.

7:45 PM Ate 5 kibble, green beans, turkey.

Tuesday April 14, 2026

7:45 AM Ate 5 kibble, turkey, softies and a few potatoes.

9:30 PM Ate 4 bowls of Cheerios

1:20 AM Ate everything: 3 kibble, turkey, potatoes and sweet potatoes.

8:20 AM Vomit (Regurgitate food and medicine)

1:15 PM Ate 5 kibble, turkey, zucchini. No potatoes.

8:00 PM Ate 5 kibble, turkey, green beans and a few potatoes

9:25 PM Vomit after cheese

10:00 PM 3 bowls of Cheerios

1:35 AM 3 kibble, ground turkey
TXT;

        $plan = $this->parser->parse($text);

        $this->assertSame([], $plan->errors, 'no file errors expected');
        $this->assertCount(12, $plan->entries);
        $this->assertSame(2, $plan->nonMonotonicCount());

        // Spot-check the two non_monotonic entries are the expected ones.
        $nonMonotonic = [];
        foreach ($plan->entries as $e) {
            if ($e->confidence === ParsedJournalEntry::CONF_NON_MONOTONIC) {
                $nonMonotonic[] = $e->noteTime;
            }
        }
        $this->assertSame(
            ['2026-04-14 01:20:00', '2026-04-14 01:35:00'],
            $nonMonotonic,
        );

        // First Wednesday entry is ok with right time/note body.
        $this->assertSame(ParsedJournalEntry::CONF_OK, $plan->entries[0]->confidence);
        $this->assertSame('2026-04-15 07:45:00', $plan->entries[0]->noteTime);
        $this->assertStringStartsWith('After a slow start', $plan->entries[0]->note);
    }

    /**
     * @return iterable<string,array{0:string}>
     */
    public static function dateHeaderVariants(): iterable
    {
        yield 'weekday with comma' => ["Wednesday, April 15, 2026\n7:45 AM ate"];
        yield 'weekday no comma'   => ["Wednesday April 15 2026\n7:45 AM ate"];
        yield 'no weekday, comma'  => ["April 15, 2026\n7:45 AM ate"];
        yield 'no weekday plain'   => ["April 15 2026\n7:45 AM ate"];
        yield 'short month comma'  => ["Apr 15, 2026\n7:45 AM ate"];
        yield 'short month plain'  => ["Apr 15 2026\n7:45 AM ate"];
        yield 'ISO date'           => ["2026-04-15\n7:45 AM ate"];
    }

    /**
     * @dataProvider dateHeaderVariants
     */
    public function testAcceptsHeaderVariants(string $text): void
    {
        $plan = $this->parser->parse($text);

        $this->assertSame([], $plan->errors);
        $this->assertCount(1, $plan->entries);
        $this->assertSame('2026-04-15 07:45:00', $plan->entries[0]->noteTime);
    }

    public function testMultiLineNoteBodyPreservesLines(): void
    {
        $text = <<<TXT
April 15, 2026

7:45 AM Ate breakfast.
Had some water too.
Then went back to sleep.
TXT;

        $plan = $this->parser->parse($text);

        $this->assertCount(1, $plan->entries);
        $this->assertSame(
            "Ate breakfast.\nHad some water too.\nThen went back to sleep.",
            $plan->entries[0]->note,
        );
    }

    public function testBlankLineFlushesEntryButKeepsDateBlock(): void
    {
        $text = <<<TXT
April 15, 2026

7:45 AM first

9:00 AM second
TXT;

        $plan = $this->parser->parse($text);

        $this->assertCount(2, $plan->entries);
        $this->assertSame('first', $plan->entries[0]->note);
        $this->assertSame('second', $plan->entries[1]->note);
        $this->assertSame('2026-04-15 09:00:00', $plan->entries[1]->noteTime);
    }

    public function testOrphanEntryProducesFileError(): void
    {
        $text = <<<TXT
7:45 AM no date header above me
TXT;

        $plan = $this->parser->parse($text);

        $this->assertSame([], $plan->entries);
        $this->assertCount(1, $plan->errors);
        $this->assertStringContainsString('entry appears before any date header', $plan->errors[0]);
    }

    public function testOrphanOnlyAffectsOrphansNotLaterEntries(): void
    {
        $text = <<<TXT
7:45 AM orphan

April 15, 2026

9:00 AM legit
TXT;

        $plan = $this->parser->parse($text);

        // One orphan error but the later valid entry still parsed.
        $this->assertCount(1, $plan->errors);
        $this->assertCount(1, $plan->entries);
        $this->assertSame('legit', $plan->entries[0]->note);
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function timeFormats(): iterable
    {
        yield '12:00 AM → midnight' => ['12:00 AM entry', '2026-04-15 00:00:00'];
        yield '12:30 PM → noon+30'  => ['12:30 PM entry', '2026-04-15 12:30:00'];
        yield '1:35 AM'             => ['1:35 AM entry',  '2026-04-15 01:35:00'];
        yield '01:35 AM zero-pad'   => ['01:35 AM entry', '2026-04-15 01:35:00'];
        yield '1:35 PM'             => ['1:35 PM entry',  '2026-04-15 13:35:00'];
        yield '11:59 PM'            => ['11:59 PM entry', '2026-04-15 23:59:00'];
        yield 'lowercase am'        => ['7:45 am entry',  '2026-04-15 07:45:00'];
    }

    /**
     * @dataProvider timeFormats
     */
    public function testTimeParsing(string $line, string $expected): void
    {
        $plan = $this->parser->parse("April 15, 2026\n\n{$line}");

        $this->assertSame([], $plan->errors);
        $this->assertCount(1, $plan->entries);
        $this->assertSame($expected, $plan->entries[0]->noteTime);
    }

    public function testContinuationLineThatLooksLikeDateDoesNotChangeDate(): void
    {
        // The regex gate requires the line to be a date-header SHAPE.
        // Bare "ate 5 kibble" is not.
        $text = <<<TXT
April 15, 2026

7:45 AM Ate 5 kibble
ate 5 kibble turkey
Still same entry.
TXT;

        $plan = $this->parser->parse($text);

        $this->assertCount(1, $plan->entries);
        $this->assertSame('2026-04-15 07:45:00', $plan->entries[0]->noteTime);
        $this->assertStringContainsString('Still same entry.', $plan->entries[0]->note);
    }

    public function testDateBlockResetsMonotonicTracker(): void
    {
        // 10 PM on Apr 15 shouldn't cause 1 AM on Apr 16 to be flagged.
        $text = <<<TXT
April 15, 2026

10:00 PM late

April 16, 2026

1:00 AM new day
TXT;

        $plan = $this->parser->parse($text);

        $this->assertCount(2, $plan->entries);
        $this->assertSame(ParsedJournalEntry::CONF_OK, $plan->entries[0]->confidence);
        $this->assertSame(ParsedJournalEntry::CONF_OK, $plan->entries[1]->confidence);
    }

    public function testStripsBomFromHeadOfInput(): void
    {
        $text = "\xEF\xBB\xBFApril 15, 2026\n\n7:45 AM ate";

        $plan = $this->parser->parse($text);

        $this->assertSame([], $plan->errors);
        $this->assertCount(1, $plan->entries);
    }

    public function testEmptyInputReturnsEmptyPlan(): void
    {
        $plan = $this->parser->parse('');

        $this->assertSame([], $plan->entries);
        $this->assertSame([], $plan->errors);
        $this->assertFalse($plan->isValid());
    }
}
