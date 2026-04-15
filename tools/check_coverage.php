<?php
/**
 * Simple coverage checker for CI / local gate.
 *
 * Expects coverage-clover.xml from phpunit --coverage-clover.
 * Reports src/ line coverage; fails <80%.
 */
$cloverPath = 'coverage-clover.xml';
if (!file_exists($cloverPath)) {
  fwrite(STDERR, "coverage-clover.xml not found\n");
  exit(2);
}

$xml = simplexml_load_file($cloverPath);
if (!$xml) {
  fwrite(STDERR, "Failed to parse $cloverPath\n");
  exit(2);
}

$allLines = 0;
$allCovered = 0;
$srcLines = 0;
$srcCovered = 0;

foreach ($xml->project->file as $file) {
  $filename = (string)$file['name'];
  $metrics = $file->metrics['@attributes'];
  $lines = (int)$metrics['statements'];
  $covered = (int)$metrics['coveredstatements'];
  $allLines += $lines;
  $allCovered += $covered;
  if (str_starts_with($filename, 'src/')) {
    $srcLines += $lines;
    $srcCovered += $covered;
  }
}

$allPct = $allLines ? round(100 * $allCovered / $allLines, 1) : 0;
$srcPct = $srcLines ? round(100 * $srcCovered / $srcLines, 1) : 0;

echo "All coverage: {$allPct}%\n";
echo "src/ coverage: {$srcPct}% (lines: {$srcLines}, covered: {$srcCovered})\n";

if ($srcPct < 80.0) {
  fwrite(STDERR, "src/ coverage below 80%: {$srcPct}%\n");
  exit(1);
}

echo "Coverage OK\n";
