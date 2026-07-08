<?php
/**
 * CLI intake-history exporter (cron entry point).
 *
 * Renders a patient's recorded doses over a date range to Markdown (the
 * default), plain text, CSV, or FHIR — reusing the exact same query and
 * exporters as the web endpoints (export_intake_*.php), so a cron dump
 * and a browser download can never drift.
 *
 * Because this runs locally under the same shell/user that owns the
 * cron table, there is no HTTP surface and no token to leak — the OS
 * account IS the authorization boundary. (For a remote puller, use the
 * bearer-authenticated web endpoints instead.)
 *
 * Usage:
 *   php export_intake.php --patient=1                       # last 12 months, Markdown, to stdout
 *   php export_intake.php --patient=1 --months=12 --format=markdown
 *   php export_intake.php --patient=1 --start=2025-07-08 --end=2026-07-08
 *   php export_intake.php --patient=1 --out-dir=/var/backups/homecare
 *   php export_intake.php --all --out-dir=/var/backups/homecare --format=markdown
 *
 * Options:
 *   --patient=N     Patient id to export. Required unless --all.
 *   --all           Export every active patient (implies --out-dir).
 *   --months=N      Rolling window: N months back from today. Default 12.
 *   --start=DATE    Explicit window start (YYYY-MM-DD). Overrides --months.
 *   --end=DATE      Explicit window end   (YYYY-MM-DD). Default today.
 *   --format=FMT    markdown (default) | text | csv | fhir
 *   --out=PATH      Write to this file. Default: stdout (single patient only).
 *   --out-dir=DIR   Write to DIR/intake-<patient>-<end>.<ext>. Auto filename.
 *   --help          Show this help.
 *
 * Exit codes: 0 success, 1 usage error, 2 runtime error.
 *
 * Example crontab (03:15 daily, rolling year, all patients):
 *   15 3 * * * php /var/www/html/homecare/export_intake.php --all \
 *     --out-dir=/var/backups/homecare >> /var/log/homecare-export.log 2>&1
 */

declare(strict_types=1);

require_once 'includes/init.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Export\CsvIntakeExporter;
use HomeCare\Export\FhirIntakeExporter;
use HomeCare\Export\IntakeExportQuery;
use HomeCare\Export\MarkdownIntakeExporter;
use HomeCare\Export\TextIntakeExporter;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    die("This script is CLI-only.\n");
}

// hc_validate() short-circuits for CLI without opening the DB (it is a
// public/exempt entrypoint), so establish the mysqli connection here the
// same way api/v1/_bootstrap.php does. dbi4php stashes the handle in
// $GLOBALS['c'], which DbiAdapter and the dbi_* helpers both read.
if (empty($GLOBALS['c'])) {
    global $db_host, $db_login, $db_password, $db_database;
    $GLOBALS['c'] = @dbi_connect($db_host, $db_login, $db_password, $db_database);
    if (!$GLOBALS['c']) {
        fwrite(STDERR, 'Database connection failed: ' . dbi_error() . "\n");
        exit(2);
    }
}

// Give the audit trail a recognisable actor. There is no logged-in user
// on a cron run, so audit_log() would otherwise record a null actor.
$GLOBALS['login'] = 'cli:export_intake';

/**
 * Parse `--key=value` / `--flag` style args into a map. Bare flags map
 * to true. Unknown keys are kept so we can reject them explicitly.
 *
 * @param list<string> $argv
 * @return array<string,string|bool>
 */
function hc_parse_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            fwrite(STDERR, "Unexpected argument: {$arg}\n");
            exit(1);
        }
        $body = substr($arg, 2);
        $eq = strpos($body, '=');
        if ($eq === false) {
            $out[$body] = true;
        } else {
            $out[substr($body, 0, $eq)] = substr($body, $eq + 1);
        }
    }
    return $out;
}

/** Validate a YYYY-MM-DD date string; exit(1) on malformed input. */
function hc_require_date(string $value, string $label): string
{
    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = \DateTimeImmutable::getLastErrors();
    if ($d === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        fwrite(STDERR, "Invalid {$label} (expected YYYY-MM-DD): {$value}\n");
        exit(1);
    }
    return $d->format('Y-m-d');
}

$opts = hc_parse_args($argv);

if (isset($opts['help'])) {
    // Echo the file's own docblock header as help text.
    $src = (string) file_get_contents(__FILE__);
    if (preg_match('#/\*\*(.*?)\*/#s', $src, $m) === 1) {
        $help = preg_replace('/^\s*\*[ ]?/m', '', trim($m[1]));
        fwrite(STDOUT, $help . "\n");
    }
    exit(0);
}

// --- Format selection -------------------------------------------------
$format = is_string($opts['format'] ?? null) ? strtolower((string) $opts['format']) : 'markdown';
$extByFormat = ['markdown' => 'md', 'text' => 'txt', 'csv' => 'csv', 'fhir' => 'json'];
if (!isset($extByFormat[$format])) {
    fwrite(STDERR, "Unknown --format '{$format}'. Use: markdown | text | csv | fhir\n");
    exit(1);
}
$ext = $extByFormat[$format];

// --- Date window ------------------------------------------------------
$endDate = isset($opts['end']) && is_string($opts['end'])
    ? hc_require_date($opts['end'], '--end')
    : date('Y-m-d');

if (isset($opts['start']) && is_string($opts['start'])) {
    $startDate = hc_require_date($opts['start'], '--start');
} else {
    $months = 12;
    if (isset($opts['months'])) {
        if (!is_string($opts['months']) || !ctype_digit($opts['months']) || (int) $opts['months'] < 1) {
            fwrite(STDERR, "Invalid --months (expected a positive integer): " . var_export($opts['months'], true) . "\n");
            exit(1);
        }
        $months = (int) $opts['months'];
    }
    $startDate = date('Y-m-d', (int) strtotime("{$endDate} -{$months} months"));
}

if ($startDate > $endDate) {
    fwrite(STDERR, "Window start ({$startDate}) is after end ({$endDate}).\n");
    exit(1);
}

// --- Output target ----------------------------------------------------
$outDir = is_string($opts['out-dir'] ?? null) ? rtrim((string) $opts['out-dir'], '/') : null;
$outFile = is_string($opts['out'] ?? null) ? (string) $opts['out'] : null;
$all = isset($opts['all']);

if ($outDir !== null && !is_dir($outDir)) {
    fwrite(STDERR, "--out-dir does not exist or is not a directory: {$outDir}\n");
    exit(1);
}
if ($all && $outDir === null) {
    fwrite(STDERR, "--all requires --out-dir (one file per patient cannot go to stdout).\n");
    exit(1);
}
if ($all && $outFile !== null) {
    fwrite(STDERR, "--all is incompatible with --out (use --out-dir).\n");
    exit(1);
}

// --- Resolve patient list --------------------------------------------
/** @var list<array<string,mixed>> $targets */
$targets = [];
if ($all) {
    foreach (getPatients(false) as $p) {
        $targets[] = $p;
    }
    if ($targets === []) {
        fwrite(STDERR, "No active patients to export.\n");
        exit(2);
    }
} else {
    $patientId = is_string($opts['patient'] ?? null) && ctype_digit((string) $opts['patient'])
        ? (int) $opts['patient']
        : 0;
    if ($patientId <= 0) {
        fwrite(STDERR, "Missing or invalid --patient (or use --all). Try --help.\n");
        exit(1);
    }
    $patient = getPatient($patientId);
    if (!$patient) {
        fwrite(STDERR, "Patient not found: {$patientId}\n");
        exit(2);
    }
    $targets[] = $patient;
}

// --- Render + write ---------------------------------------------------
$query = new IntakeExportQuery(new DbiAdapter());
$generatedAt = date('Y-m-d H:i');
$periodLabel = $startDate === $endDate ? $startDate : $startDate . ' – ' . $endDate;

/**
 * Render one patient's rows into the chosen format.
 *
 * @param list<array<string,mixed>> $rows
 */
function hc_render(string $format, array $rows, string $patientName, string $periodLabel, string $generatedAt): string
{
    $meta = [
        'patient_name' => $patientName,
        'period_label' => $periodLabel,
        'generated_at' => $generatedAt,
    ];
    return match ($format) {
        'markdown' => (new MarkdownIntakeExporter())->toMarkdown($rows, $meta),
        'text' => (new TextIntakeExporter())->toText($rows, $meta),
        'csv' => (new CsvIntakeExporter())->toCsv($rows),
        'fhir' => (new FhirIntakeExporter())->toJson($rows),
        default => '',
    };
}

$exit = 0;
foreach ($targets as $patient) {
    $pid = (int) $patient['id'];
    $pname = (string) $patient['name'];
    $rows = $query->fetch($pid, $startDate, $endDate);
    $content = hc_render($format, $rows, $pname, $periodLabel, $generatedAt);

    // Resolve destination path for this patient.
    if ($outDir !== null) {
        $slug = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($pname)) ?: 'patient';
        $dest = sprintf('%s/intake-%s-%s.%s', $outDir, $slug, $endDate, $ext);
    } elseif ($outFile !== null) {
        $dest = $outFile;
    } else {
        $dest = null; // stdout
    }

    if ($dest === null) {
        fwrite(STDOUT, $content);
    } else {
        if (file_put_contents($dest, $content, LOCK_EX) === false) {
            fwrite(STDERR, "Failed to write {$dest}\n");
            $exit = 2;
            continue;
        }
        fwrite(STDERR, sprintf("Wrote %s (%d rows, %s)\n", $dest, count($rows), $periodLabel));
    }

    audit_log('export.intake_cli', 'patient', $pid, [
        'format' => $format,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'row_count' => count($rows),
        'destination' => $dest ?? 'stdout',
        'via' => 'cli',
    ]);
}

exit($exit);
