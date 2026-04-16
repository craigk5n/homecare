<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Import;

use HomeCare\Import\JournalImporter;
use HomeCare\Import\JournalParser;
use HomeCare\Repository\CaregiverNoteRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;
use RuntimeException;

final class JournalImportTest extends DatabaseTestCase
{
    private JournalParser $parser;

    private JournalImporter $importer;

    private CaregiverNoteRepository $notes;

    private int $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $this->patientId = $db->lastInsertId();

        $this->notes = new CaregiverNoteRepository($db);
        $this->parser = new JournalParser();
        $this->importer = new JournalImporter($db, $this->notes);
    }

    public function testCanonicalSampleRoundTripsTo12Rows(): void
    {
        $text = self::sampleJournal();

        $plan = $this->parser->parse($text);
        $plan = $this->importer->annotateDuplicates($plan, $this->patientId);

        $this->assertTrue($plan->isValid());
        $result = $this->importer->commit($plan, $this->patientId);

        $this->assertSame(12, $result['inserted']);
        $this->assertSame(0, $result['skipped']);

        $rows = $this->notes->getForPatient($this->patientId, limit: 100);
        $this->assertCount(12, $rows);

        // Spot-check: the earliest row in source is Apr 15 7:45 AM.
        $times = array_column($rows, 'note_time');
        $this->assertContains('2026-04-15 07:45:00', $times);
        $this->assertContains('2026-04-14 01:35:00', $times);
    }

    public function testRePastingTheSameJournalSkipsEverything(): void
    {
        $text = self::sampleJournal();

        // First commit lands all 12.
        $first = $this->parser->parse($text);
        $first = $this->importer->annotateDuplicates($first, $this->patientId);
        $this->importer->commit($first, $this->patientId);

        // Second commit finds every entry already present.
        $second = $this->parser->parse($text);
        $second = $this->importer->annotateDuplicates($second, $this->patientId);

        $this->assertSame(12, $second->duplicateCount());
        $result = $this->importer->commit($second, $this->patientId);

        $this->assertSame(0, $result['inserted']);
        $this->assertSame(12, $result['skipped']);

        // Only the original 12 rows exist.
        $this->assertCount(12, $this->notes->getForPatient($this->patientId, limit: 100));
    }

    public function testPartialDuplicatesInsertOnlyNewEntries(): void
    {
        // Seed one note that matches an entry in the paste.
        $this->notes->create(
            $this->patientId,
            'Ate 5 kibble, turkey and some potatoes.',
            '2026-04-15 13:00:00'
        );

        $plan = $this->parser->parse(self::sampleJournal());
        $plan = $this->importer->annotateDuplicates($plan, $this->patientId);

        $this->assertSame(1, $plan->duplicateCount());
        $result = $this->importer->commit($plan, $this->patientId);

        $this->assertSame(11, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(12, $this->notes->getForPatient($this->patientId, limit: 100));
    }

    public function testInFileDuplicatesCollapseToOne(): void
    {
        $text = <<<TXT
April 15, 2026

7:45 AM same body
7:45 AM same body
TXT;

        $plan = $this->parser->parse($text);
        $plan = $this->importer->annotateDuplicates($plan, $this->patientId);

        // Both entries parse; the second one is flagged as in-file dup.
        $this->assertCount(2, $plan->entries);
        $this->assertSame(1, $plan->duplicateCount());

        $result = $this->importer->commit($plan, $this->patientId);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
    }

    public function testCommitThrowsOnInvalidPlan(): void
    {
        $plan = $this->parser->parse("7:45 AM orphan before any header");
        $this->assertFalse($plan->isValid());

        $this->expectException(RuntimeException::class);
        $this->importer->commit($plan, $this->patientId);
    }

    public function testRollbackOnMidCommitFailure(): void
    {
        $this->notes->create($this->patientId, 'pre-existing', '2026-04-01 00:00:00');
        $this->assertCount(1, $this->notes->getForPatient($this->patientId));

        $plan = $this->parser->parse(self::sampleJournal());
        $plan = $this->importer->annotateDuplicates($plan, $this->patientId);
        $this->assertTrue($plan->isValid());

        // Sabotage the table mid-commit so inserts blow up.
        $this->getSqliteDb()->pdo()->exec(
            'ALTER TABLE hc_caregiver_notes RENAME COLUMN note TO notebody'
        );

        try {
            $this->importer->commit($plan, $this->patientId);
            $this->fail('commit should have thrown');
        } catch (\Throwable) {
            $this->getSqliteDb()->pdo()->exec(
                'ALTER TABLE hc_caregiver_notes RENAME COLUMN notebody TO note'
            );
        }

        // Survivor: only the pre-existing row. No partial inserts.
        $rows = $this->notes->getForPatient($this->patientId, limit: 100);
        $this->assertCount(1, $rows);
        $this->assertSame('pre-existing', $rows[0]['note']);
    }

    private static function sampleJournal(): string
    {
        return <<<TXT
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
    }
}
