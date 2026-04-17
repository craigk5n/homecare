<?php

declare(strict_types=1);

namespace HomeCare\Import;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Strict parser for the free-text `note_time` column in caregiver-note imports.
 *
 * Accepts three shape families, in priority order:
 *
 *   1. ISO 8601               — 2026-04-01T14:30[:00], 2026-04-01 14:30[:00]
 *   2. US calendar            — 4/1/2026 2:30 PM, 04/01/2026 14:30
 *   3. Date-only (any shape)  — 2026-04-01 or 4/1/2026 → treated as midnight
 *
 * Everything else is rejected with {@see InvalidArgumentException} so
 * ambiguous strings never silently land at a wrong timestamp. The
 * importer catches the exception and converts it to a row-level error.
 *
 * Output is always a 19-character `Y-m-d H:i:s` string in the server's
 * local timezone -- the same shape every other `note_time` column in
 * the DB uses.
 */
final class NoteTimeParser
{
    /**
     * Formats tried in order. Each pair is (format-string, requires-time).
     * `createFromFormat` is strict about day-in-month, so "2026-04-31" is
     * rejected before we get to the fallback.
     *
     * @var list<array{0:string,1:bool}>
     */
    private const FORMATS = [
        // ISO 8601 with time
        ['Y-m-d\TH:i:s', true],
        ['Y-m-d\TH:i',   true],
        ['Y-m-d H:i:s',  true],
        ['Y-m-d H:i',    true],
        // US with time, 12-hour
        ['n/j/Y g:i A',  true],
        ['n/j/Y g:i a',  true],
        ['m/d/Y g:i A',  true],
        ['m/d/Y g:i a',  true],
        // US with time, 24-hour
        ['n/j/Y H:i',    true],
        ['m/d/Y H:i',    true],
        ['n/j/Y H:i:s',  true],
        ['m/d/Y H:i:s',  true],
        // Date-only (midnight). The leading `!` resets every unset field
        // to zero, so these don't inherit the current wall-clock time.
        ['!Y-m-d',       false],
        ['!n/j/Y',       false],
        ['!m/d/Y',       false],
    ];

    public function parse(string $input): string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            throw new InvalidArgumentException('note_time is empty');
        }

        foreach (self::FORMATS as [$format, $_requiresTime]) {
            $dt = DateTimeImmutable::createFromFormat($format, $trimmed);
            if ($dt === false) {
                continue;
            }
            // createFromFormat tolerates trailing garbage; reject it so
            // "2026-04-01 banana" doesn't pass as the first 10 chars.
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            return $dt->format('Y-m-d H:i:s');
        }

        throw new InvalidArgumentException(
            "unrecognised note_time '{$trimmed}'"
            . ' (expected ISO 8601, US date/time, or YYYY-MM-DD)',
        );
    }
}
