<?php

declare(strict_types=1);

namespace HomeCare\Import;

/**
 * One entry pulled out of a plain-text journal paste.
 *
 * Confidence tells the preview how to render the row:
 *   - {@see self::CONF_OK}            — entry's time is monotonic against
 *                                       its predecessor within the same
 *                                       date block.
 *   - {@see self::CONF_NON_MONOTONIC} — wall-clock time goes backward from
 *                                       the previous entry in the same
 *                                       date block. Not an error — overnight
 *                                       events get logged this way — but the
 *                                       operator confirms in the preview.
 *
 * `isDuplicate` is annotated AFTER parsing by {@see JournalImporter}; the
 * parser itself has no patient context and cannot know.
 */
final readonly class ParsedJournalEntry
{
    public const CONF_OK = 'ok';
    public const CONF_NON_MONOTONIC = 'non_monotonic';

    public function __construct(
        public int $lineNumber,
        public string $noteTime,   // Y-m-d H:i:s
        public string $note,
        public string $confidence = self::CONF_OK,
        public bool $isDuplicate = false,
    ) {
    }

    public function asDuplicate(): self
    {
        return new self(
            $this->lineNumber,
            $this->noteTime,
            $this->note,
            $this->confidence,
            true,
        );
    }
}
