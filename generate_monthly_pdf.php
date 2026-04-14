<?php
/**
 * Cron-friendly generator for intake PDFs.
 *
 * Named "monthly" because the original use case was "once a month, archive
 * last month's activity", but the flags support arbitrary ranges — point
 * it at a yearly window on Jan 1 if you want an annual archive alongside
 * the monthlies.
 *
 * Typical cron entries:
 *   # 00:05 on the 1st of each month — archive the month that just ended
 *   5 0 1 * * cd /var/www/html/homecare && php generate_monthly_pdf.php
 *
 *   # 00:05 on Jan 1 — archive the full prior year
 *   5 0 1 1 * cd /var/www/html/homecare && php generate_monthly_pdf.php \
 *             --start=$(date -d "last year 01 01" +%Y%m%d) \
 *             --end=$(date -d "last year 12 31" +%Y%m%d)
 *
 * Date inputs accept either YYYY-MM-DD or YYYYMMDD so they survive shell
 * quoting and `date +%Y%m%d` without extra hyphens.
 *
 * Resolution order for dates:
 *   1. --start / --end (explicit window; either YYYY-MM-DD or YYYYMMDD)
 *   2. --month=YYYY-MM / YYYYMM (first-to-last-day of that month)
 *   3. last completed calendar month (default)
 *
 * Resolution order for destination:
 *   1. --output-dir flag
 *   2. $PDF_ARCHIVE_DIR env var
 *   3. hc_config.pdf_archive_dir row
 *
 * Files are written atomically (temp + rename) so a reader listing the
 * archive dir never sees a half-written PDF even if cron collides with a
 * caregiver pulling the archive.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Export\IntakeExportQuery;
use HomeCare\Export\PdfIntakeExporter;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI-only.\n";
    exit(1);
}

$opts = parse_args($argv);

if ($opts['help']) {
    echo usage();
    exit(0);
}

// ── Resolve the date window ──────────────────────────────────────────

[$periodStart, $periodEnd, $periodLabel, $filenameSegment] = resolve_window($opts);
if ($periodStart === null) {
    fwrite(STDERR, "Could not derive a date window from the given options.\n");
    fwrite(STDERR, usage());
    exit(2);
}

// ── Resolve destination ──────────────────────────────────────────────

$outputDir = (string) (
    $opts['output_dir']
    ?? (getenv('PDF_ARCHIVE_DIR') ?: '')
    ?: read_config_value('pdf_archive_dir')
);

if ($outputDir === '' && !$opts['dry_run']) {
    fwrite(
        STDERR,
        "No archive destination configured.\n"
        . "Set hc_config.pdf_archive_dir, \$PDF_ARCHIVE_DIR env, or pass --output-dir=PATH.\n"
    );
    exit(3);
}

if ($outputDir !== '' && !$opts['dry_run']) {
    $outputDir = rtrim($outputDir, '/');
    if (!is_dir($outputDir) && !mkdir($outputDir, 0750, true) && !is_dir($outputDir)) {
        fwrite(STDERR, "Cannot create archive dir: {$outputDir}\n");
        exit(4);
    }
    if (!is_writable($outputDir)) {
        fwrite(STDERR, "Archive dir is not writable: {$outputDir}\n");
        exit(4);
    }
}

// ── Render per patient ───────────────────────────────────────────────

$patients = filter_patients(getPatients(), $opts['patient_id']);
if ($patients === []) {
    fwrite(STDERR, "No matching active patients.\n");
    exit(5);
}

$db = new DbiAdapter();
$query = new IntakeExportQuery($db);
$exporter = new PdfIntakeExporter();

$generatedAt = date('Y-m-d H:i');
$writtenCount = 0;
$failureCount = 0;

foreach ($patients as $patient) {
    $patientId = (int) $patient['id'];
    $patientName = (string) $patient['name'];
    $rows = $query->fetch($patientId, $periodStart, $periodEnd);

    $filename = sprintf(
        'intake-%s-%s.pdf',
        slugify($patientName),
        $filenameSegment
    );
    $destination = $outputDir === '' ? '(stdout)' : $outputDir . '/' . $filename;

    if ($opts['dry_run']) {
        echo sprintf(
            "[dry-run] would write %s (%d intakes, %s..%s)\n",
            $destination,
            count($rows),
            $periodStart,
            $periodEnd
        );
        continue;
    }

    try {
        $pdf = $exporter->toPdf($rows, [
            'patient_name' => $patientName,
            'period_label' => $periodLabel,
            'generated_at' => $generatedAt,
        ]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "PDF render failed for patient {$patientId} ({$patientName}): " . $e->getMessage() . "\n");
        $failureCount++;
        continue;
    }

    if (!atomic_write($destination, $pdf)) {
        fwrite(STDERR, "Write failed: {$destination}\n");
        $failureCount++;
        continue;
    }
    $writtenCount++;

    if ($opts['verbose']) {
        echo sprintf(
            "wrote %s (%d intakes, %d bytes)\n",
            $destination,
            count($rows),
            strlen($pdf)
        );
    }

    // Audit log so admins can prove an archive was emitted on a given day.
    $GLOBALS['login'] = $GLOBALS['login'] ?? 'cron';
    audit_log('export.intake_pdf_cron', 'patient', $patientId, [
        'start_date' => $periodStart,
        'end_date' => $periodEnd,
        'row_count' => count($rows),
        'destination' => $destination,
        'bytes' => strlen($pdf),
    ]);
}

if ($opts['verbose'] || $failureCount > 0) {
    echo sprintf("Done. %d written, %d failed.\n", $writtenCount, $failureCount);
}

exit($failureCount > 0 ? 6 : 0);

// ── helpers ──────────────────────────────────────────────────────────

/**
 * @param list<string> $argv
 * @return array{
 *     help:bool, dry_run:bool, verbose:bool,
 *     patient_id:?int, month:?string, start:?string, end:?string,
 *     output_dir:?string
 * }
 */
function parse_args(array $argv): array
{
    $opts = [
        'help' => false,
        'dry_run' => false,
        'verbose' => false,
        'patient_id' => null,
        'month' => null,
        'start' => null,
        'end' => null,
        'output_dir' => null,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
        } elseif ($arg === '--dry-run') {
            $opts['dry_run'] = true;
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $opts['verbose'] = true;
        } elseif (str_starts_with($arg, '--patient-id=')) {
            $opts['patient_id'] = (int) substr($arg, 13);
        } elseif (str_starts_with($arg, '--month=')) {
            $opts['month'] = substr($arg, 8);
        } elseif (str_starts_with($arg, '--start=')) {
            $opts['start'] = substr($arg, 8);
        } elseif (str_starts_with($arg, '--end=')) {
            $opts['end'] = substr($arg, 6);
        } elseif (str_starts_with($arg, '--output-dir=')) {
            $opts['output_dir'] = substr($arg, 13);
        } else {
            fwrite(STDERR, "Unknown argument: {$arg}\n");
            fwrite(STDERR, usage());
            exit(2);
        }
    }

    return $opts;
}

function usage(): string
{
    return <<<TXT
Usage: php generate_monthly_pdf.php [options]

  --patient-id=N     Only generate for one patient
  --start=DATE       Start of window. YYYY-MM-DD or YYYYMMDD.
  --end=DATE         End of window.   YYYY-MM-DD or YYYYMMDD.
  --month=YYYY-MM    Shortcut for --start=YYYY-MM-01 --end=<last day>.
                     YYYYMM (no hyphen) also accepted.
  --output-dir=PATH  Archive destination (overrides config)
  --dry-run          Don't write, just print plan
  --verbose, -v      One line per PDF written
  --help, -h         This help

If no date flags are given, the default window is the most recently
completed calendar month.

Destination resolution order:
  1. --output-dir flag
  2. \$PDF_ARCHIVE_DIR environment variable
  3. hc_config.pdf_archive_dir row

TXT;
}

/**
 * Resolve the reporting window from the parsed options.
 *
 * @param array{start:?string,end:?string,month:?string} $opts
 * @return array{0:?string,1:?string,2:string,3:string} [start, end, displayLabel, filenameSegment]
 */
function resolve_window(array $opts): array
{
    // Explicit start/end window takes precedence.
    if ($opts['start'] !== null || $opts['end'] !== null) {
        $start = $opts['start'] !== null ? normalize_date($opts['start']) : null;
        $end = $opts['end'] !== null ? normalize_date($opts['end']) : null;
        if ($start === null || $end === null) {
            return [null, null, '', ''];
        }
        if ($start > $end) {
            return [null, null, '', ''];
        }
        $label = format_range_label($start, $end);
        $segment = str_replace('-', '', $start) . '-' . str_replace('-', '', $end);

        return [$start, $end, $label, $segment];
    }

    // --month= shortcut.
    if ($opts['month'] !== null) {
        $month = normalize_month($opts['month']);
        if ($month === null) {
            return [null, null, '', ''];
        }
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        if ($end === false) {
            return [null, null, '', ''];
        }

        return [$start, $end, date('F Y', strtotime($start)), $month];
    }

    // Default: last completed calendar month.
    $start = date('Y-m-01', strtotime('first day of last month'));
    $end = date('Y-m-t', strtotime($start));
    $month = substr($start, 0, 7);

    return [$start, $end, date('F Y', strtotime($start)), $month];
}

/**
 * Accept either YYYY-MM-DD or YYYYMMDD and return a canonical YYYY-MM-DD,
 * or null if the input doesn't match either form (or fails date validation).
 */
function normalize_date(string $s): ?string
{
    $s = trim($s);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        [$_, $y, $mo, $d] = $m;
    } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        [$_, $y, $mo, $d] = $m;
    } else {
        return null;
    }
    if (!checkdate((int) $mo, (int) $d, (int) $y)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', (int) $y, (int) $mo, (int) $d);
}

/**
 * Accept YYYY-MM or YYYYMM and return YYYY-MM.
 */
function normalize_month(string $s): ?string
{
    $s = trim($s);
    if (preg_match('/^(\d{4})-(\d{2})$/', $s, $m)) {
        [$_, $y, $mo] = $m;
    } elseif (preg_match('/^(\d{4})(\d{2})$/', $s, $m)) {
        [$_, $y, $mo] = $m;
    } else {
        return null;
    }
    if ((int) $mo < 1 || (int) $mo > 12) {
        return null;
    }

    return sprintf('%04d-%02d', (int) $y, (int) $mo);
}

/**
 * A human-friendly label for a [start, end] range:
 *  - same day       → "April 14, 2026"
 *  - full month     → "February 2026"
 *  - full year      → "2026"
 *  - otherwise      → "Feb 1, 2026 – Feb 28, 2026"
 */
function format_range_label(string $start, string $end): string
{
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($startTs === false || $endTs === false) {
        return $start . ' – ' . $end;
    }
    if ($start === $end) {
        return date('F j, Y', $startTs);
    }
    $yearStart = date('Y-01-01', $startTs);
    $yearEnd = date('Y-12-31', $startTs);
    if ($start === $yearStart && $end === $yearEnd) {
        return date('Y', $startTs);
    }
    $monthStart = date('Y-m-01', $startTs);
    $monthEnd = date('Y-m-t', $startTs);
    if ($start === $monthStart && $end === $monthEnd) {
        return date('F Y', $startTs);
    }

    return date('M j, Y', $startTs) . ' – ' . date('M j, Y', $endTs);
}

/**
 * @param list<array{id:int|string,name:string}> $patients
 * @return list<array{id:int|string,name:string}>
 */
function filter_patients(array $patients, ?int $patientId): array
{
    if ($patientId !== null) {
        return array_values(array_filter(
            $patients,
            static fn (array $p): bool => (int) $p['id'] === $patientId
        ));
    }

    return array_values($patients);
}

function slugify(string $s): string
{
    $slug = strtolower($s);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug === '' ? 'patient' : $slug;
}

/**
 * Atomic write: temp file in same dir, chmod, rename. Rename is atomic
 * within a single filesystem on POSIX so readers never see a partial PDF.
 */
function atomic_write(string $destination, string $bytes): bool
{
    $dir = dirname($destination);
    $tmp = tempnam($dir, '.hc_pdf_');
    if ($tmp === false) {
        return false;
    }
    if (file_put_contents($tmp, $bytes) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $destination)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function read_config_value(string $key): string
{
    $rows = dbi_get_cached_rows(
        'SELECT value FROM hc_config WHERE setting = ?',
        [$key]
    );
    if ($rows === []) {
        return '';
    }

    return (string) $rows[0][0];
}
