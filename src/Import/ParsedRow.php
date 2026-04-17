<?php

declare(strict_types=1);

namespace HomeCare\Import;

/**
 * One row of a caregiver-note import after parsing and validation.
 *
 * A row with a non-empty `errors` array is invalid and MUST NOT be
 * inserted; the importer checks the whole plan before committing
 * anything so a single bad row blocks the entire file.
 */
final readonly class ParsedRow
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public int $lineNumber,
        public ?int $patientId,
        public ?string $noteTime,
        public ?string $note,
        public array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function withError(string $error): self
    {
        return new self(
            $this->lineNumber,
            $this->patientId,
            $this->noteTime,
            $this->note,
            [...$this->errors, $error],
        );
    }
}
