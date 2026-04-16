<?php

declare(strict_types=1);

namespace HomeCare\Import;

/**
 * Result of parsing+validating a caregiver-note import file.
 *
 * Holds row-level results plus file-level errors (e.g. missing header,
 * unknown delimiter). Invalid when any row has a validation error OR
 * the file itself failed to parse.
 */
final readonly class ImportPlan
{
    /**
     * @param list<ParsedRow> $rows
     * @param list<string>    $fileErrors  file-level errors (blocks commit)
     */
    public function __construct(
        public array $rows,
        public array $fileErrors = [],
    ) {
    }

    public function isValid(): bool
    {
        if ($this->fileErrors !== []) {
            return false;
        }
        foreach ($this->rows as $row) {
            if (!$row->isValid()) {
                return false;
            }
        }

        return $this->rows !== [];
    }

    public function totalRows(): int
    {
        return count($this->rows);
    }

    /**
     * @return list<ParsedRow>
     */
    public function invalidRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (ParsedRow $r): bool => !$r->isValid(),
        ));
    }
}
