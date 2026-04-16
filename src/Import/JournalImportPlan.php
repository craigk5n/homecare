<?php

declare(strict_types=1);

namespace HomeCare\Import;

/**
 * Output of {@see JournalParser::parse()}.
 *
 * `entries` are parsed journal rows in source order (not grouped by day --
 * the preview does the grouping). `errors` are file-level problems that
 * prevent commit, most commonly orphan entries (time line before any
 * date header).
 */
final readonly class JournalImportPlan
{
    /**
     * @param list<ParsedJournalEntry> $entries
     * @param list<string>             $errors   file-level errors (blocks commit)
     */
    public function __construct(
        public array $entries,
        public array $errors = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [] && $this->entries !== [];
    }

    public function nonMonotonicCount(): int
    {
        $n = 0;
        foreach ($this->entries as $e) {
            if ($e->confidence === ParsedJournalEntry::CONF_NON_MONOTONIC) {
                $n++;
            }
        }

        return $n;
    }

    public function duplicateCount(): int
    {
        $n = 0;
        foreach ($this->entries as $e) {
            if ($e->isDuplicate) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Replace the entry list (used by the importer to annotate duplicates
     * without mutating this plan in place).
     *
     * @param list<ParsedJournalEntry> $entries
     */
    public function withEntries(array $entries): self
    {
        return new self($entries, $this->errors);
    }
}
