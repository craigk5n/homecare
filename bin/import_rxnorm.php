#!/usr/bin/env php
<?php
/**
 * Import RxNorm drug catalog from an RRF dump (HC-110).
 *
 * Reads RXNCONSO.RRF from a local directory and loads Semantic Clinical
 * Drug (SCD) and Semantic Branded Drug (SBD) entries into hc_drug_catalog.
 * Idempotent: upserts on rxnorm_id so re-running is safe.
 *
 * Usage:
 *   php bin/import_rxnorm.php /path/to/rrf/
 *   php bin/import_rxnorm.php /path/to/rrf/ --dry-run
 *
 * The RRF directory should contain at least RXNCONSO.RRF (from the
 * RxNorm Full Release at https://www.nlm.nih.gov/research/umls/rxnorm/).
 *
 * Veterinary note: RxNorm covers human drugs only. For vet-specific
 * medications, add rows manually with rxnorm_id=NULL:
 *   INSERT INTO hc_drug_catalog (name, strength, dosage_form, generic)
 *   VALUES ('Carprofen', '75mg', 'Chewable Tablet', 'Y');
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/init.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\DrugCatalogRepository;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/import_rxnorm.php /path/to/rrf/ [--dry-run]\n");
    exit(1);
}

$rffDir = rtrim($argv[1], '/');
$dryRun = in_array('--dry-run', $argv, true);

$consoPath = $rffDir . '/RXNCONSO.RRF';
if (!file_exists($consoPath)) {
    fwrite(STDERR, "Error: RXNCONSO.RRF not found in $rffDir\n");
    exit(1);
}

$db = new DbiAdapter();
$repo = new DrugCatalogRepository($db);

$targetTtys = ['SCD', 'SBD'];
$inserted = 0;
$updated = 0;
$skipped = 0;

$fh = fopen($consoPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "Error: cannot open $consoPath\n");
    exit(1);
}

echo "Reading $consoPath...\n";

while (($line = fgets($fh)) !== false) {
    $fields = explode('|', $line);

    // RXNCONSO.RRF columns: RXCUI|LAT|TS|LUI|STT|SUI|ISPREF|RXAUI|SAUI|SCUI|SDUI|SAB|TTY|CODE|STR|SRL|SUPPRESS|CVF
    if (count($fields) < 17) {
        continue;
    }

    $lat = $fields[1];  // language
    $sab = $fields[11]; // source abbreviation
    $tty = $fields[12]; // term type
    $str = $fields[14]; // string (drug name)
    $rxcui = (int) $fields[0];

    // Only English, from RXNORM source, SCD/SBD term types
    if ($lat !== 'ENG' || $sab !== 'RXNORM' || !in_array($tty, $targetTtys, true)) {
        $skipped++;
        continue;
    }

    $isGeneric = $tty === 'SCD';

    // Parse strength and dosage form from the full clinical name
    // RxNorm SCD format: "ingredient strength dosage_form"
    // e.g. "Amoxicillin 500 MG Oral Capsule"
    $parts = parseRxnormName($str);

    if ($dryRun) {
        echo sprintf("[DRY-RUN] RXCUI=%d %s (%s, %s) %s\n",
            $rxcui, $parts['name'], $parts['strength'] ?? '?', $parts['dosage_form'] ?? '?',
            $isGeneric ? 'generic' : 'branded'
        );
        $inserted++;
        continue;
    }

    $existing = $repo->findByRxnormId($rxcui);
    $repo->upsertByRxnormId([
        'rxnorm_id' => $rxcui,
        'name' => $parts['name'],
        'strength' => $parts['strength'],
        'dosage_form' => $parts['dosage_form'],
        'ingredient_names' => $parts['ingredient_names'],
        'generic' => $isGeneric,
    ]);

    if ($existing !== null) {
        $updated++;
    } else {
        $inserted++;
    }

    if (($inserted + $updated) % 1000 === 0) {
        echo sprintf("  processed %d entries...\n", $inserted + $updated);
    }
}

fclose($fh);

echo sprintf(
    "\nDone. Inserted: %d, Updated: %d, Skipped: %d%s\n",
    $inserted,
    $updated,
    $skipped,
    $dryRun ? ' (dry run — no changes made)' : ''
);

/**
 * Parse an RxNorm clinical drug name into component parts.
 *
 * @return array{name:string, strength:?string, dosage_form:?string, ingredient_names:?string}
 */
function parseRxnormName(string $str): array
{
    $str = trim($str);

    // Common dosage forms to detect at the end of the string
    $dosageForms = [
        'Oral Tablet', 'Oral Capsule', 'Oral Solution', 'Oral Suspension',
        'Injectable Solution', 'Injectable Suspension',
        'Topical Cream', 'Topical Ointment', 'Topical Gel', 'Topical Lotion',
        'Ophthalmic Solution', 'Otic Solution',
        'Nasal Spray', 'Inhalation Solution', 'Inhalation Powder',
        'Rectal Suppository', 'Vaginal Cream', 'Vaginal Tablet',
        'Transdermal Patch', 'Sublingual Tablet', 'Chewable Tablet',
        'Delayed Release Oral Capsule', 'Extended Release Oral Tablet',
        'Extended Release Oral Capsule', 'Disintegrating Oral Tablet',
        'Prefilled Syringe', 'Auto-Injector', 'Pen Injector',
        'Metered Dose Inhaler', 'Dry Powder Inhaler',
        'Topical Solution', 'Topical Spray', 'Topical Foam',
        'Oral Powder', 'Oral Granules', 'Oral Film',
        'Buccal Film', 'Buccal Tablet',
    ];

    $dosageForm = null;
    $remainder = $str;

    foreach ($dosageForms as $form) {
        if (str_ends_with($str, $form)) {
            $dosageForm = $form;
            $remainder = trim(substr($str, 0, -strlen($form)));
            break;
        }
    }

    // Try to extract strength: look for patterns like "500 MG", "10 MG/ML", "0.5 MG"
    $strength = null;
    $ingredientPart = $remainder;
    if (preg_match('/^(.+?)\s+([\d.]+\s*(?:MG|MCG|UNITS?|MEQ|MG\/ML|MCG\/ML|%|IU)(?:\s*\/\s*[\d.]+\s*(?:ML|MG|L))?)$/i', $remainder, $m)) {
        $ingredientPart = trim($m[1]);
        $strength = trim($m[2]);
    }

    return [
        'name' => $str,
        'strength' => $strength,
        'dosage_form' => $dosageForm,
        'ingredient_names' => $ingredientPart !== $str ? $ingredientPart : null,
    ];
}
