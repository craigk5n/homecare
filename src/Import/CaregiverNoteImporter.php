<?php

declare(strict_types=1);

namespace HomeCare\Import;

use HomeCare\Database\DatabaseInterface;
use HomeCare\Repository\CaregiverNoteRepository;
use HomeCare\Repository\PatientRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parse + validate + commit a caregiver-note import file.
 *
 * Two-phase: `parse()` builds an {@see ImportPlan} (pure, no writes),
 * `commit()` inserts every validated row inside a transaction so the
 * file either lands completely or not at all.
 *
 * Supported file shapes:
 *   - CSV, TSV, or semicolon-separated — delimiter auto-detected from
 *     the header line (whichever of `,`, `\t`, or `;` appears most).
 *   - Header row is REQUIRED. Recognised columns: `note_time`, `note`,
 *     plus one of (`patient_id` | `patient_name`) unless a default
 *     patient id is passed to `parse()`.
 *
 * Patient matching: `patient_id` must be a live patient row. Otherwise
 * `patient_name` is compared case-insensitively against
 * `hc_patients.name`; ties (two patients with the same name) produce
 * a row error so the caregiver picks explicitly.
 */
final class CaregiverNoteImporter
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly CaregiverNoteRepository $notes,
        private readonly PatientRepository $patients,
        private readonly NoteTimeParser $timeParser = new NoteTimeParser(),
    ) {
    }

    /**
     * Parse and validate file contents. Does not touch the database
     * apart from read-only patient lookups.
     */
    public function parse(string $content, ?int $defaultPatientId = null): ImportPlan
    {
        $content = self::stripBom($content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $lines = explode("\n", $content);
        // Drop trailing blank line from final newline.
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return new ImportPlan([], ['file is empty']);
        }

        $header = array_shift($lines);
        $delimiter = self::detectDelimiter((string) $header);
        $headerFields = self::splitRow((string) $header, $delimiter);
        $headerMap = self::normaliseHeader($headerFields);

        $fileErrors = self::validateHeader($headerMap, $defaultPatientId !== null);
        if ($fileErrors !== []) {
            return new ImportPlan([], $fileErrors);
        }

        // Cache patients once per import so a 5000-line file doesn't
        // issue 5000 identical lookups.
        /** @var array<int,array{id:int,name:string}> $patientsById */
        $patientsById = [];
        /** @var array<string,list<array{id:int,name:string}>> $patientsByLowerName */
        $patientsByLowerName = [];
        foreach ($this->patients->getAll(includeDisabled: true) as $p) {
            $entry = ['id' => $p['id'], 'name' => $p['name']];
            $patientsById[$entry['id']] = $entry;
            $lower = strtolower($entry['name']);
            $patientsByLowerName[$lower] ??= [];
            $patientsByLowerName[$lower][] = $entry;
        }

        $rows = [];
        $lineNumber = 1; // header was line 1
        foreach ($lines as $line) {
            $lineNumber++;
            if (trim($line) === '') {
                continue;
            }

            $fields = self::splitRow($line, $delimiter);
            $rows[] = $this->parseRow(
                $lineNumber,
                $fields,
                $headerMap,
                $defaultPatientId,
                $patientsById,
                $patientsByLowerName,
            );
        }

        return new ImportPlan($rows);
    }

    /**
     * Insert every row of a validated plan inside a transaction.
     *
     * Returns the number of rows inserted. Throws when the plan is
     * not `isValid()` -- callers must check first and show errors.
     *
     * @throws RuntimeException when the plan has errors or the DB fails.
     */
    public function commit(ImportPlan $plan): int
    {
        if (!$plan->isValid()) {
            throw new RuntimeException(
                'Cannot commit an invalid import plan; fix row errors first.'
            );
        }

        /** @var int $count */
        $count = $this->db->transactional(function () use ($plan): int {
            $n = 0;
            foreach ($plan->rows as $row) {
                // Guaranteed non-null by isValid(), but PHPStan needs the assertion.
                if ($row->patientId === null || $row->noteTime === null || $row->note === null) {
                    throw new RuntimeException(
                        "Row {$row->lineNumber}: unexpectedly missing required fields at commit time."
                    );
                }
                $this->notes->create($row->patientId, $row->note, $row->noteTime);
                $n++;
            }

            return $n;
        });

        return $count;
    }

    /**
     * @param list<string>                                              $fields
     * @param array<string,int>                                          $headerMap
     * @param array<int,array{id:int,name:string}>                       $patientsById
     * @param array<string,list<array{id:int,name:string}>>              $patientsByLowerName
     */
    private function parseRow(
        int $lineNumber,
        array $fields,
        array $headerMap,
        ?int $defaultPatientId,
        array $patientsById,
        array $patientsByLowerName,
    ): ParsedRow {
        $errors = [];
        $get = static function (string $col) use ($headerMap, $fields): ?string {
            if (!array_key_exists($col, $headerMap)) {
                return null;
            }
            $idx = $headerMap[$col];

            return isset($fields[$idx]) ? trim($fields[$idx]) : null;
        };

        // note_time ---------------------------------------------------
        $noteTimeRaw = $get('note_time') ?? '';
        $noteTime = null;
        if ($noteTimeRaw === '') {
            $errors[] = "missing note_time";
        } else {
            try {
                $noteTime = $this->timeParser->parse($noteTimeRaw);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        // note --------------------------------------------------------
        $note = $get('note') ?? '';
        if ($note === '') {
            $errors[] = "missing note";
        }

        // patient -----------------------------------------------------
        $patientId = null;
        $patientIdRaw = $get('patient_id');
        $patientNameRaw = $get('patient_name');

        if ($patientIdRaw !== null && $patientIdRaw !== '') {
            if (!ctype_digit($patientIdRaw)) {
                $errors[] = "patient_id '{$patientIdRaw}' is not an integer";
            } else {
                $candidate = (int) $patientIdRaw;
                if (!isset($patientsById[$candidate])) {
                    $errors[] = "no patient with id {$candidate}";
                } else {
                    $patientId = $candidate;
                }
            }
        } elseif ($patientNameRaw !== null && $patientNameRaw !== '') {
            $lower = strtolower($patientNameRaw);
            $matches = $patientsByLowerName[$lower] ?? [];
            if ($matches === []) {
                $errors[] = "no patient matched '{$patientNameRaw}'";
            } elseif (count($matches) > 1) {
                $errors[] = "multiple patients matched '{$patientNameRaw}'; use patient_id";
            } else {
                $patientId = $matches[0]['id'];
            }
        } elseif ($defaultPatientId !== null) {
            if (!isset($patientsById[$defaultPatientId])) {
                $errors[] = "default patient id {$defaultPatientId} not found";
            } else {
                $patientId = $defaultPatientId;
            }
        } else {
            $errors[] = "missing patient_id or patient_name";
        }

        return new ParsedRow(
            lineNumber: $lineNumber,
            patientId:  $patientId,
            noteTime:   $noteTime,
            note:       $note === '' ? null : $note,
            errors:     $errors,
        );
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string,int> column-name → field-index
     */
    private static function normaliseHeader(array $fields): array
    {
        $map = [];
        foreach ($fields as $idx => $name) {
            $normalised = strtolower(trim($name));
            if ($normalised !== '') {
                $map[$normalised] = $idx;
            }
        }

        return $map;
    }

    /**
     * @param array<string,int> $headerMap
     *
     * @return list<string>
     */
    private static function validateHeader(array $headerMap, bool $hasDefaultPatient): array
    {
        $errors = [];
        if (!array_key_exists('note_time', $headerMap)) {
            $errors[] = "missing required column 'note_time'";
        }
        if (!array_key_exists('note', $headerMap)) {
            $errors[] = "missing required column 'note'";
        }
        if (!$hasDefaultPatient
            && !array_key_exists('patient_id', $headerMap)
            && !array_key_exists('patient_name', $headerMap)
        ) {
            $errors[] = "missing patient column: need one of 'patient_id' or 'patient_name'";
        }

        return $errors;
    }

    /**
     * Pick the delimiter whose count dominates the header line. Ties
     * break on comma → tab → semicolon, which matches caregivers'
     * most-likely export tools (Google Sheets, Apple Numbers, Excel).
     */
    private static function detectDelimiter(string $headerLine): string
    {
        $counts = [
            ','  => substr_count($headerLine, ','),
            "\t" => substr_count($headerLine, "\t"),
            ';'  => substr_count($headerLine, ';'),
        ];
        arsort($counts, SORT_NUMERIC);
        /** @var string $top */
        $top = (string) array_key_first($counts);

        return $counts[$top] === 0 ? ',' : $top;
    }

    /**
     * RFC-4180-ish line split that honours double-quoted fields so a
     * comma inside a note body doesn't accidentally start a new cell.
     *
     * @return list<string>
     */
    private static function splitRow(string $line, string $delimiter): array
    {
        // str_getcsv does the heavy lifting; escape char set to "\\" which
        // PHP treats as "no escape" by default but keeps PHP 8.4 happy.
        $fields = str_getcsv($line, $delimiter, '"', '\\');

        return array_map(
            static fn ($v): string => $v === null ? '' : (string) $v,
            $fields
        );
    }

    private static function stripBom(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            return substr($s, 3);
        }

        return $s;
    }
}
