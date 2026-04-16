<?php

declare(strict_types=1);

namespace HomeCare\Import;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Repository\CaregiverNoteRepository;
use RuntimeException;

/**
 * Annotate + commit a {@see JournalImportPlan} against a single patient.
 *
 * De-dup key: `(patient_id, note_time, SHA256(note))`. The importer
 * loads all existing notes for the patient within the plan's time
 * range, hashes each, and marks incoming entries whose hash already
 * exists as `isDuplicate`. `commit()` skips duplicates silently and
 * inserts everything else inside a single transaction.
 */
final class JournalImporter
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly CaregiverNoteRepository $notes,
    ) {
    }

    /**
     * Return a new plan with each entry's `isDuplicate` flag set according
     * to existing rows in `hc_caregiver_notes` for the given patient.
     *
     * In-file dupes (two identical entries in the same paste) also get
     * flagged so re-pasting after a partial failure is safe.
     */
    public function annotateDuplicates(JournalImportPlan $plan, int $patientId): JournalImportPlan
    {
        if ($plan->entries === []) {
            return $plan;
        }

        $times = array_map(
            static fn (ParsedJournalEntry $e): string => $e->noteTime,
            $plan->entries
        );
        $min = min($times);
        $max = max($times);

        $seen = []; // hash key → true
        foreach ($this->notes->getNotesInTimeRange($patientId, $min, $max) as $row) {
            $time = $row['note_time'];
            if ($time === null) {
                continue;
            }
            $seen[self::dedupKey($time, $row['note'])] = true;
        }

        $annotated = [];
        foreach ($plan->entries as $entry) {
            $key = self::dedupKey($entry->noteTime, $entry->note);
            if (isset($seen[$key])) {
                $annotated[] = $entry->asDuplicate();
            } else {
                $annotated[] = $entry;
                // Flag in-file dupes on the second occurrence.
                $seen[$key] = true;
            }
        }

        return $plan->withEntries($annotated);
    }

    /**
     * Insert every non-duplicate entry inside a transaction.
     *
     * @return array{inserted:int,skipped:int}
     */
    public function commit(JournalImportPlan $plan, int $patientId): array
    {
        if (!$plan->isValid()) {
            throw new RuntimeException(
                'Cannot commit an invalid journal plan; fix file errors first.'
            );
        }

        /** @var array{inserted:int,skipped:int} $result */
        $result = $this->db->transactional(function () use ($plan, $patientId): array {
            $inserted = 0;
            $skipped = 0;
            foreach ($plan->entries as $entry) {
                if ($entry->isDuplicate) {
                    $skipped++;
                    continue;
                }
                $this->notes->create($patientId, $entry->note, $entry->noteTime);
                $inserted++;
            }

            return ['inserted' => $inserted, 'skipped' => $skipped];
        });

        return $result;
    }

    private static function dedupKey(string $noteTime, string $note): string
    {
        return $noteTime . "\0" . hash('sha256', $note);
    }
}
