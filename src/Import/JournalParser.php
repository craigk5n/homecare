<?php

declare(strict_types=1);

namespace HomeCare\Import;

use DateTimeImmutable;

/**
 * Parse a caregiver's free-form journal paste into structured entries.
 *
 * The input shape (see STATUS.md §HC-085) is:
 *
 *   Wednesday April 15, 2026        ← date header
 *
 *   7:45 AM Ate breakfast.          ← entry (time + body)
 *   Added detail on the next line.  ← continuation of the same entry
 *
 *   1:00 PM Next event.
 *
 *   Tuesday April 14, 2026
 *   ...
 *
 * Rules:
 *   - A date header resets the "current date" cursor and the
 *     monotonic tracker. Header formats recognised: weekday +
 *     "F j[,] Y", bare "F j[,] Y", "M j[,] Y", and ISO "Y-m-d".
 *   - A time line starts a new entry; its body is the trailing
 *     text after the time.
 *   - Every non-blank, non-header, non-time line is appended to
 *     the body of the entry currently being built.
 *   - Blank lines flush the current entry without ending the
 *     date block.
 *   - A time line before any date header is an **orphan** and
 *     surfaces as a file-level error -- the entire import is
 *     rejected until the caregiver fixes the file.
 *   - An entry whose wall-clock time goes backward from its
 *     immediate predecessor in the same date block is flagged
 *     `non_monotonic`. Overnight events (a 1:35 AM entry logged
 *     under yesterday's date) fit this shape and are legitimate,
 *     so we do NOT reject them -- only flag them for operator
 *     review in the preview.
 */
final class JournalParser
{
    /** Weekday → allow either leading weekday or bare header. */
    private const WEEKDAY_RE = '/^(?:Sun|Mon|Tue|Wed|Thu|Fri|Sat)[a-z]*,?\s+/i';

    /** Regex gate for date-header candidates (permissive; parser confirms). */
    private const DATE_HEADER_RE = '/^[A-Za-z]+,?\s+(?:[A-Za-z]+\s+)?\d{1,2},?\s*\d{4}$/';

    private const ISO_DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';

    /** Time line shape: "7:45 AM Body", "12:00 am body", ... */
    private const TIME_LINE_RE = '/^(\d{1,2}):(\d{2})\s*(AM|PM|am|pm)\s+(.+)$/';

    public function parse(string $text): JournalImportPlan
    {
        $text = self::stripBom($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);

        /** @var list<ParsedJournalEntry> $entries */
        $entries = [];
        /** @var list<string> $errors */
        $errors = [];

        /** @var ?string $currentDate Y-m-d cursor */
        $currentDate = null;
        /** @var ?string $lastTimeInBlock H:i:s of previous entry in same date block */
        $lastTimeInBlock = null;
        /** @var ?array{line:int,date:string,time:string,body:list<string>} $pending */
        $pending = null;

        $flush = function () use (&$pending, &$entries, &$lastTimeInBlock): void {
            if ($pending === null) {
                return;
            }
            $time = $pending['time'];
            $conf = ParsedJournalEntry::CONF_OK;
            if ($lastTimeInBlock !== null && $time < $lastTimeInBlock) {
                $conf = ParsedJournalEntry::CONF_NON_MONOTONIC;
            }
            $body = trim(implode("\n", $pending['body']));
            $entries[] = new ParsedJournalEntry(
                lineNumber: $pending['line'],
                noteTime: $pending['date'] . ' ' . $time,
                note: $body,
                confidence: $conf,
            );
            $lastTimeInBlock = $time;
            $pending = null;
        };

        foreach ($lines as $idx => $rawLine) {
            $lineNumber = $idx + 1;
            $line = trim($rawLine);

            if ($line === '') {
                $flush();
                continue;
            }

            $maybeDate = self::tryParseDateHeader($line);
            if ($maybeDate !== null) {
                $flush();
                $currentDate = $maybeDate;
                $lastTimeInBlock = null;
                continue;
            }

            if (preg_match(self::TIME_LINE_RE, $line, $m) === 1) {
                $flush();

                $hour = (int) $m[1];
                $minute = (int) $m[2];
                $ampm = $m[3];
                $body = $m[4];
                $time = self::normaliseTime($hour, $minute, $ampm);

                if ($time === null) {
                    $errors[] = "Line {$lineNumber}: invalid time '{$m[1]}:{$m[2]} {$ampm}'";
                    continue;
                }

                if ($currentDate === null) {
                    $errors[] = "Line {$lineNumber}: entry appears before any date header: "
                              . self::snippet($line);
                    continue;
                }

                $pending = [
                    'line' => $lineNumber,
                    'date' => $currentDate,
                    'time' => $time,
                    'body' => [$body],
                ];
                continue;
            }

            // Continuation line: append to body of the entry in progress.
            if ($pending !== null) {
                $pending['body'][] = $line;
            }
            // Otherwise: stray text outside any entry — silently ignored.
            // This lets caregivers add section notes / stray labels without
            // every one becoming an error.
        }

        $flush();

        return new JournalImportPlan($entries, $errors);
    }

    /**
     * Return Y-m-d if $line is a recognised date header, else null.
     *
     * The regex gate keeps noise like "Ate 5 kibble turkey" from even
     * reaching `createFromFormat` -- the latter is permissive enough
     * that we'd occasionally eat a legitimate continuation line.
     */
    private static function tryParseDateHeader(string $line): ?string
    {
        $line = trim($line);

        // ISO "2026-04-15"
        if (preg_match(self::ISO_DATE_RE, $line) === 1) {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $line);
            if ($dt !== false && self::isClean()) {
                return $dt->format('Y-m-d');
            }
        }

        if (preg_match(self::DATE_HEADER_RE, $line) !== 1) {
            return null;
        }

        // Strip commas, optional leading weekday, collapse whitespace.
        $cleaned = str_replace(',', '', $line);
        $cleaned = (string) preg_replace(self::WEEKDAY_RE, '', $cleaned);
        $cleaned = (string) preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);

        foreach (['!F j Y', '!M j Y'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $cleaned);
            if ($dt === false) {
                continue;
            }
            if (!self::isClean()) {
                continue;
            }

            return $dt->format('Y-m-d');
        }

        return null;
    }

    private static function normaliseTime(int $hour, int $minute, string $ampm): ?string
    {
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            return null;
        }

        $ampmUpper = strtoupper($ampm);
        $h = $hour;
        if ($ampmUpper === 'AM' && $h === 12) {
            $h = 0;             // 12:00 AM → midnight
        } elseif ($ampmUpper === 'PM' && $h < 12) {
            $h += 12;
        }
        // 12 PM stays 12 (noon); 1-11 AM stay as-is.

        return sprintf('%02d:%02d:00', $h, $minute);
    }

    /**
     * True when the most recent DateTimeImmutable::createFromFormat call
     * had neither warnings nor errors, so trailing garbage is rejected.
     */
    private static function isClean(): bool
    {
        $errs = DateTimeImmutable::getLastErrors();

        return $errs === false || ($errs['warning_count'] === 0 && $errs['error_count'] === 0);
    }

    private static function stripBom(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            return substr($s, 3);
        }

        return $s;
    }

    private static function snippet(string $line): string
    {
        return strlen($line) <= 60 ? "'{$line}'" : "'" . substr($line, 0, 60) . "…'";
    }
}
