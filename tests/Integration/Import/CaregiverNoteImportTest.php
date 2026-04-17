<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Import;

use HomeCare\Import\CaregiverNoteImporter;
use HomeCare\Repository\CaregiverNoteRepository;
use HomeCare\Repository\PatientRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;
use RuntimeException;

final class CaregiverNoteImportTest extends DatabaseTestCase
{
    private CaregiverNoteImporter $importer;

    private CaregiverNoteRepository $notes;

    private int $daisyId;

    private int $fozzieId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();

        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $this->daisyId = $db->lastInsertId();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Fozzie']);
        $this->fozzieId = $db->lastInsertId();

        $this->notes = new CaregiverNoteRepository($db);
        $this->importer = new CaregiverNoteImporter(
            $db,
            $this->notes,
            new PatientRepository($db),
        );
    }

    public function testGoodCsvRoundTrips(): void
    {
        $csv = <<<CSV
note_time,note,patient_name
2026-04-15 07:45,Ate breakfast,Daisy
2026-04-15T13:00,Ate lunch,Daisy
4/15/2026 7:00 PM,Ate dinner,Daisy
CSV;

        $plan = $this->importer->parse($csv);

        $this->assertTrue($plan->isValid(), 'plan should be valid; errors: '
            . (string) json_encode($plan->invalidRows(), JSON_PRETTY_PRINT));
        $this->assertSame(3, $plan->totalRows());

        $inserted = $this->importer->commit($plan);

        $this->assertSame(3, $inserted);
        $rows = $this->notes->getForPatient($this->daisyId);
        $this->assertCount(3, $rows);
    }

    public function testMissingRequiredColumnBlocksEntireFile(): void
    {
        $csv = "note_time,patient_name\n2026-04-15,Daisy";

        $plan = $this->importer->parse($csv);

        $this->assertFalse($plan->isValid());
        $this->assertContains(
            "missing required column 'note'",
            $plan->fileErrors,
        );
    }

    public function testMissingPatientColumnErrorsWhenNoDefault(): void
    {
        $csv = "note_time,note\n2026-04-15,ate";

        $plan = $this->importer->parse($csv);

        $this->assertFalse($plan->isValid());
        $this->assertSame(
            ["missing patient column: need one of 'patient_id' or 'patient_name'"],
            $plan->fileErrors,
        );
    }

    public function testDefaultPatientIdFillsMissingColumn(): void
    {
        $csv = "note_time,note\n2026-04-15 07:45,ate breakfast";

        $plan = $this->importer->parse($csv, defaultPatientId: $this->daisyId);

        $this->assertTrue($plan->isValid());
        $this->importer->commit($plan);

        $rows = $this->notes->getForPatient($this->daisyId);
        $this->assertCount(1, $rows);
    }

    public function testPatientNameCaseInsensitive(): void
    {
        $csv = "note_time,note,patient_name\n2026-04-15,x,daisy\n2026-04-15,y,FOZZIE";

        $plan = $this->importer->parse($csv);

        $this->assertTrue($plan->isValid(), (string) json_encode($plan->invalidRows()));

        $this->importer->commit($plan);
        $this->assertCount(1, $this->notes->getForPatient($this->daisyId));
        $this->assertCount(1, $this->notes->getForPatient($this->fozzieId));
    }

    public function testUnknownPatientNameProducesRowError(): void
    {
        $csv = "note_time,note,patient_name\n2026-04-15,x,Fozzie the Cat";

        $plan = $this->importer->parse($csv);

        $this->assertFalse($plan->isValid());
        $invalid = $plan->invalidRows();
        $this->assertCount(1, $invalid);
        $this->assertSame(2, $invalid[0]->lineNumber);
        $this->assertContains("no patient matched 'Fozzie the Cat'", $invalid[0]->errors);
    }

    public function testAmbiguousDateProducesRowError(): void
    {
        $csv = "note_time,note,patient_name\nyesterday afternoon,x,Daisy";

        $plan = $this->importer->parse($csv);

        $this->assertFalse($plan->isValid());
        $this->assertCount(1, $plan->invalidRows());
        $this->assertStringContainsString(
            'unrecognised note_time',
            $plan->invalidRows()[0]->errors[0],
        );
    }

    public function testTabSeparatedAutoDetected(): void
    {
        $csv = "note_time\tnote\tpatient_name\n2026-04-15\tx\tDaisy";

        $plan = $this->importer->parse($csv);

        $this->assertTrue($plan->isValid());
        $this->importer->commit($plan);
        $this->assertCount(1, $this->notes->getForPatient($this->daisyId));
    }

    public function testSemicolonSeparatedAutoDetected(): void
    {
        $csv = "note_time;note;patient_name\n2026-04-15;x;Daisy";

        $plan = $this->importer->parse($csv);

        $this->assertTrue($plan->isValid());
    }

    public function testQuotedCommaInsideNote(): void
    {
        $csv = "note_time,note,patient_name\n2026-04-15,\"ate turkey, potatoes\",Daisy";

        $plan = $this->importer->parse($csv);

        $this->assertTrue($plan->isValid());
        $this->importer->commit($plan);
        $rows = $this->notes->getForPatient($this->daisyId);
        $this->assertSame('ate turkey, potatoes', $rows[0]['note']);
    }

    public function testCommitThrowsWhenPlanInvalid(): void
    {
        $csv = "note_time,note,patient_name\nnonsense,x,Daisy";

        $plan = $this->importer->parse($csv);
        $this->assertFalse($plan->isValid());

        $this->expectException(RuntimeException::class);
        $this->importer->commit($plan);
    }

    public function testRollbackOnMidFileCommitFailure(): void
    {
        // Pre-populate one note so we can confirm state survives rollback.
        $this->notes->create($this->daisyId, 'pre-existing', '2026-04-01 00:00:00');
        $this->assertCount(1, $this->notes->getForPatient($this->daisyId));

        // Plan two valid rows. Between validation and commit we sabotage
        // the target table so the 2nd INSERT fails — proves rollback works.
        $csv = "note_time,note,patient_name\n"
             . "2026-04-10,first,Daisy\n"
             . '2026-04-11,second,Daisy';
        $plan = $this->importer->parse($csv);
        $this->assertTrue($plan->isValid());

        // Drop the `note` column so subsequent INSERTs blow up. This is
        // a deliberate, low-effort way to trigger a commit-time error
        // that forces rollback. Done via raw PDO since DatabaseInterface
        // doesn't expose DDL.
        $this->getSqliteDb()->pdo()->exec('ALTER TABLE hc_caregiver_notes RENAME COLUMN note TO notebody');

        try {
            $this->importer->commit($plan);
            $this->fail('commit should have thrown');
        } catch (\Throwable) {
            // Expected. Reset schema so the assertion below can read.
            $this->getSqliteDb()->pdo()->exec(
                'ALTER TABLE hc_caregiver_notes RENAME COLUMN notebody TO note',
            );
        }

        // The pre-existing note survives; neither of the two planned
        // rows landed (transaction rolled back).
        $rows = $this->notes->getForPatient($this->daisyId);
        $this->assertCount(1, $rows);
        $this->assertSame('pre-existing', $rows[0]['note']);
    }
}
