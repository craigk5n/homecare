#!/usr/bin/env php
<?php
/**
 * Import drug interaction pairs into hc_drug_interactions (HC-112).
 *
 * Reads a CSV file of ingredient-level interaction pairs and upserts
 * them into the database. The CSV must have columns:
 *   ingredient_a, ingredient_b, severity, description
 *
 * Sources (licence-compatible):
 *   - RxNav Interaction API (https://rxnav.nlm.nih.gov/InteractionAPIs.html)
 *     Free, public-domain. Query programmatically then export to CSV.
 *   - DrugBank Open Data (https://go.drugbank.com/releases/latest#open-data)
 *     CC BY-NC 4.0. Suitable for non-commercial/educational use.
 *   - Custom curated list maintained by the care team.
 *
 * Usage:
 *   php bin/import_interactions.php /path/to/interactions.csv
 *   php bin/import_interactions.php /path/to/interactions.csv --dry-run
 *
 * CSV format (header row required):
 *   ingredient_a,ingredient_b,severity,description
 *   amoxicillin,warfarin,moderate,"May increase anticoagulant effect"
 *   aspirin,ibuprofen,major,"Increased risk of GI bleeding"
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/init.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\InteractionRepository;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/import_interactions.php /path/to/interactions.csv [--dry-run]\n");
    exit(1);
}

$csvPath = $argv[1];
$dryRun = in_array('--dry-run', $argv, true);

if (!file_exists($csvPath)) {
    fwrite(STDERR, "Error: file not found: $csvPath\n");
    exit(1);
}

$db = new DbiAdapter();
$repo = new InteractionRepository($db);

$fh = fopen($csvPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "Error: cannot open $csvPath\n");
    exit(1);
}

$header = fgetcsv($fh);
if ($header === false) {
    fwrite(STDERR, "Error: empty CSV file\n");
    exit(1);
}

$header = array_map('strtolower', array_map('trim', $header));
$colA = array_search('ingredient_a', $header, true);
$colB = array_search('ingredient_b', $header, true);
$colSev = array_search('severity', $header, true);
$colDesc = array_search('description', $header, true);

if ($colA === false || $colB === false || $colSev === false) {
    fwrite(STDERR, "Error: CSV must have columns: ingredient_a, ingredient_b, severity\n");
    exit(1);
}

$validSeverities = ['minor', 'moderate', 'major'];
$inserted = 0;
$skipped = 0;
$lineNum = 1;

echo "Reading $csvPath...\n";

while (($row = fgetcsv($fh)) !== false) {
    $lineNum++;

    if (count($row) < 3) {
        $skipped++;
        continue;
    }

    $a = strtolower(trim((string) ($row[$colA] ?? '')));
    $b = strtolower(trim((string) ($row[$colB] ?? '')));
    $sev = strtolower(trim((string) ($row[$colSev] ?? '')));
    $desc = $colDesc !== false ? trim((string) ($row[$colDesc] ?? '')) : null;

    if ($a === '' || $b === '' || $a === $b) {
        echo "  Line $lineNum: skipped (empty or self-interaction)\n";
        $skipped++;
        continue;
    }

    if (!in_array($sev, $validSeverities, true)) {
        echo "  Line $lineNum: skipped (invalid severity '$sev')\n";
        $skipped++;
        continue;
    }

    if ($desc === '') {
        $desc = null;
    }

    if ($dryRun) {
        echo "[DRY-RUN] $a + $b => $sev\n";
    } else {
        $repo->upsert($a, $b, $sev, $desc);
    }
    $inserted++;
}

fclose($fh);

echo sprintf(
    "\nDone. Loaded: %d, Skipped: %d%s\n",
    $inserted,
    $skipped,
    $dryRun ? ' (dry run — no changes made)' : ''
);
