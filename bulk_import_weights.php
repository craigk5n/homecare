<?php

declare(strict_types=1);

/**
 * Bulk import patient weight readings from a CSV file.
 *
 * Two CSV layouts are supported, auto-detected from the header row:
 *
 *   Long format (single patient):
 *     date,weight,note
 *     2026-01-13,5.13,Vet visit
 *     ...
 *   Requires --patient=<id>.
 *
 *   Wide format (multiple patients, one column per name):
 *     Date,Gracie,Daisy,Fozzie
 *     6/15/2011,7.6,13.5,
 *     ...
 *   Each non-date column is matched to a patient by name (case-insensitive,
 *   exact). Columns whose names don't match a known patient are reported
 *   once and ignored. Empty cells are skipped silently.
 *
 * Common options:
 *   --file=<path>         CSV file path. Omit to read from stdin.
 *   --unit=kg|lb          Unit of the input weights (default: kg).
 *   --dry-run             Parse and validate but don't write anything.
 *   --allow-duplicates    Insert rows even if (date, weight) already exists
 *                         for that patient.
 *   --note=<text>         Optional note attached to every imported row in
 *                         wide-format mode.
 *   --help                Show usage.
 *
 * Behaviour:
 *   - Each row is inserted into hc_weight_history.
 *   - Duplicates (same patient_id + recorded_at + weight_kg) are skipped
 *     by default.
 *   - hc_patients.weight_kg / weight_as_of are synced once per affected
 *     patient at the end of the run, to the most recent history entry by
 *     recorded_at.
 *   - Dates must include a 4-digit year. Anything strtotime() can read
 *     (YYYY-MM-DD, M/D/YYYY, etc.) works; bare M/D is rejected so the
 *     current year isn't silently assumed.
 */

// init.php uses cwd-relative includes, so anchor to the script directory
// to allow invocation from anywhere.
chdir(__DIR__);
require_once 'includes/init.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\WeightRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

// hc_validate() short-circuits in CLI mode and never opens the DB,
// so do that explicitly here using the credentials do_config() loaded.
global $c, $db_host, $db_login, $db_password, $db_database;
if (empty($c)) {
    $c = @dbi_connect($db_host, $db_login, $db_password, $db_database);
    if (!$c) {
        fwrite(STDERR, "Database connection failed: " . dbi_error() . "\n");
        exit(1);
    }
}

$opts = parseArgs($argv);
if (!empty($opts['help'])) {
    echo usage();
    exit(0);
}

$dryRun = !empty($opts['dry-run']);
$allowDuplicates = !empty($opts['allow-duplicates']);
$unit = strtolower((string) ($opts['unit'] ?? 'kg'));
if ($unit !== 'kg' && $unit !== 'lb') {
    fwrite(STDERR, "Invalid --unit: must be 'kg' or 'lb'.\n");
    exit(1);
}
$convFactor = $unit === 'lb' ? 1 / 2.20462 : 1.0;
$globalNote = isset($opts['note']) && $opts['note'] !== true ? (string) $opts['note'] : null;

$file = $opts['file'] ?? null;
if ($file !== null) {
    if (!is_readable($file)) {
        fwrite(STDERR, "Cannot read file: {$file}\n");
        exit(1);
    }
    $fh = fopen($file, 'r');
} else {
    if (stream_isatty(STDIN)) {
        fwrite(STDERR, "No --file given and stdin is a TTY. Pipe a CSV in or use --file.\n");
        exit(1);
    }
    $fh = STDIN;
}

$header = fgetcsv($fh);
if ($header === false) {
    fwrite(STDERR, "Empty input.\n");
    exit(1);
}
$cols = array_map(static fn($h) => trim((string) $h), $header);
$colsLower = array_map(static fn($h) => strtolower($h), $cols);
$dateIdx = array_search('date', $colsLower, true);
if ($dateIdx === false) {
    fwrite(STDERR, "CSV header must include a 'date' column. Got: " . implode(',', $cols) . "\n");
    exit(1);
}

$db = new DbiAdapter();
$weightRepo = new WeightRepository($db);

$weightIdx = array_search('weight', $colsLower, true);
$wide = ($weightIdx === false);

if ($wide) {
    // Wide format: each non-date column is a patient name. Resolve to
    // patient IDs, ignoring columns that don't match a known patient.
    $patients = getPatients(); // active + inactive
    $byName = [];
    foreach ($patients as $p) {
        $byName[strtolower($p['name'])] = (int) $p['id'];
    }

    $colToPatient = []; // colIdx => patient_id
    $unknownCols = [];
    foreach ($cols as $idx => $name) {
        if ($idx === $dateIdx || $name === '') {
            continue;
        }
        $key = strtolower($name);
        if (isset($byName[$key])) {
            $colToPatient[$idx] = $byName[$key];
        } else {
            $unknownCols[] = $name;
        }
    }

    if (empty($colToPatient)) {
        fwrite(STDERR, "No CSV columns matched any patient name. Header: " . implode(',', $cols) . "\n");
        exit(1);
    }
    echo "Wide-format import. Mapped columns:\n";
    foreach ($colToPatient as $idx => $pid) {
        echo "  '" . $cols[$idx] . "' -> patient #{$pid}\n";
    }
    if (!empty($unknownCols)) {
        echo "Ignoring unknown columns: " . implode(', ', $unknownCols) . "\n";
    }
} else {
    // Long format: requires --patient.
    if (empty($opts['patient'])) {
        fwrite(STDERR, "Long-format CSV (date,weight,note) requires --patient=<id>.\n");
        exit(1);
    }
    $patientId = (int) $opts['patient'];
    $patient = getPatient($patientId);
    if (empty($patient)) {
        fwrite(STDERR, "No patient found with id={$patientId}.\n");
        exit(1);
    }
    echo "Long-format import for patient #{$patientId} ({$patient['name']}), input unit={$unit}\n";
    $noteIdx = array_search('note', $colsLower, true);
}

$lineNo = 1;
$inserted = 0;
$skippedDup = 0;
$skippedBad = 0;
$touchedPatients = []; // patient_id => true

while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;
    // Skip wholly blank rows.
    $allEmpty = true;
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            $allEmpty = false;
            break;
        }
    }
    if ($allEmpty) {
        continue;
    }

    $rawDate = trim((string) ($row[$dateIdx] ?? ''));
    if ($rawDate === '') {
        fwrite(STDERR, "  line {$lineNo}: skipped (missing date)\n");
        $skippedBad++;
        continue;
    }
    $recordedAt = parseDate($rawDate);
    if ($recordedAt === null) {
        fwrite(STDERR, "  line {$lineNo}: skipped (unparseable or year-less date '{$rawDate}')\n");
        $skippedBad++;
        continue;
    }

    if ($wide) {
        foreach ($colToPatient as $idx => $pid) {
            $rawWeight = trim((string) ($row[$idx] ?? ''));
            if ($rawWeight === '') {
                continue; // empty cell — patient not weighed that day
            }
            $result = importOne(
                $weightRepo,
                $pid,
                $rawWeight,
                $recordedAt,
                $globalNote,
                $convFactor,
                $allowDuplicates,
                $dryRun,
                $lineNo,
                $cols[$idx],
            );
            if ($result === 'inserted') {
                $inserted++;
                $touchedPatients[$pid] = true;
            } elseif ($result === 'duplicate') {
                $skippedDup++;
            } else {
                $skippedBad++;
            }
        }
    } else {
        $rawWeight = trim((string) ($row[$weightIdx] ?? ''));
        $note = $noteIdx !== false ? trim((string) ($row[$noteIdx] ?? '')) : '';
        if ($note === '') {
            $note = $globalNote;
        }
        if ($rawWeight === '') {
            fwrite(STDERR, "  line {$lineNo}: skipped (missing weight)\n");
            $skippedBad++;
            continue;
        }
        $result = importOne(
            $weightRepo,
            $patientId,
            $rawWeight,
            $recordedAt,
            $note,
            $convFactor,
            $allowDuplicates,
            $dryRun,
            $lineNo,
            null,
        );
        if ($result === 'inserted') {
            $inserted++;
            $touchedPatients[$patientId] = true;
        } elseif ($result === 'duplicate') {
            $skippedDup++;
        } else {
            $skippedBad++;
        }
    }
}

fclose($fh);

if (!$dryRun) {
    foreach (array_keys($touchedPatients) as $pid) {
        $weightRepo->syncCurrentWeight($pid);
    }
    if (!empty($touchedPatients)) {
        echo "Synced current weight for " . count($touchedPatients) . " patient(s).\n";
    }
}

echo "\nDone. inserted={$inserted} duplicates_skipped={$skippedDup} bad_rows={$skippedBad}"
    . ($dryRun ? " (dry-run; no rows written)" : "") . "\n";
exit(0);

/**
 * Insert a single row, returning 'inserted' | 'duplicate' | 'bad'.
 */
function importOne(
    WeightRepository $repo,
    int $patientId,
    string $rawWeight,
    string $recordedAt,
    ?string $note,
    float $convFactor,
    bool $allowDuplicates,
    bool $dryRun,
    int $lineNo,
    ?string $colLabel,
): string {
    $tag = $colLabel !== null ? "[{$colLabel}] " : '';
    if (!is_numeric($rawWeight)) {
        fwrite(STDERR, "  line {$lineNo}: {$tag}skipped (non-numeric weight '{$rawWeight}')\n");
        return 'bad';
    }
    $weightKg = round((float) $rawWeight * $convFactor, 2);
    if ($weightKg <= 0) {
        fwrite(STDERR, "  line {$lineNo}: {$tag}skipped (non-positive weight)\n");
        return 'bad';
    }
    if (!$allowDuplicates) {
        $existing = dbi_get_cached_rows(
            'SELECT id FROM hc_weight_history
              WHERE patient_id = ? AND recorded_at = ? AND weight_kg = ?',
            [$patientId, $recordedAt, $weightKg],
        );
        if (!empty($existing)) {
            echo "  line {$lineNo}: {$tag}duplicate skipped ({$recordedAt}, {$weightKg}kg)\n";
            return 'duplicate';
        }
    }
    if ($dryRun) {
        echo "  line {$lineNo}: {$tag}would insert patient #{$patientId} {$recordedAt} {$weightKg}kg"
            . ($note !== null && $note !== '' ? " note='{$note}'" : '') . "\n";
    } else {
        $repo->insert($patientId, $weightKg, $recordedAt, $note !== '' ? $note : null);
        echo "  line {$lineNo}: {$tag}inserted patient #{$patientId} {$recordedAt} {$weightKg}kg\n";
    }
    return 'inserted';
}

/**
 * Parse a user-supplied date. Returns null if the string lacks a 4-digit
 * year (so '4/16' isn't silently treated as the current year) or fails
 * strtotime().
 */
function parseDate(string $raw): ?string
{
    if (!preg_match('/\b\d{4}\b/', $raw)) {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/**
 * Parse `--key=value` and `--flag` style arguments.
 *
 * @param list<string> $argv
 * @return array<string, string|bool>
 */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
        } else {
            $out[$arg] = true;
        }
    }
    return $out;
}

function usage(): string
{
    return <<<TXT
Usage: php bulk_import_weights.php [options]

Long format (single patient):
  Header: date,weight,note
  Requires --patient=<id>.

Wide format (multiple patients):
  Header: Date,<PatientName1>,<PatientName2>,...
  Patient names are matched case-insensitively against hc_patients.name.
  Unknown columns are reported and ignored. Empty cells are skipped.

Options:
  --patient=<id>        Patient ID (required for long format).
  --file=<path>         CSV file path. Omit to read from stdin.
  --unit=kg|lb          Unit of the input weights (default: kg).
  --note=<text>         Note attached to every imported row.
  --dry-run             Parse and validate but don't write anything.
  --allow-duplicates    Insert rows even if (date, weight) already exists.
  --help                Show this message.

Examples:
  php bulk_import_weights.php --patient=1 --file=daisy.csv --unit=lb --dry-run
  php bulk_import_weights.php --file=DogWeights.csv --unit=lb --note='Vet records'

TXT;
}
