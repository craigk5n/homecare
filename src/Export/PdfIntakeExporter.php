<?php

declare(strict_types=1);

namespace HomeCare\Export;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Render intake rows as a print-ready PDF via Dompdf.
 *
 * Layout decisions:
 *  - Letter paper, 0.5" margins. Letter is the most common US home-printer
 *    size; A4 users can still fit the content (8.3" vs 8.5") with a bit of
 *    extra white space on the right.
 *  - Logo in the top-left so the document self-identifies without relying
 *    on the filename, and so a caregiver handing a printout to a vet sees
 *    "HomeCare" branding at first glance.
 *  - Intakes are grouped by calendar day, matching how the on-screen
 *    report organises them. A 24-hour log on one line is nearly useless
 *    when you're scanning for "what did she miss yesterday?".
 *  - Notes render inline under the med name (muted italic) rather than
 *    behind a tooltip icon, because PDFs are inherently read-only and a
 *    tooltip would be invisible anyway.
 *
 * The rendered HTML is intentionally self-contained — no external CSS,
 * no remote fonts — so Dompdf's LAN-isolated rendering is reproducible
 * and doesn't fail at cron time if the box loses outbound network.
 *
 * @phpstan-import-type IntakeExportRow from IntakeExportQuery
 */
final class PdfIntakeExporter
{
    /** Absolute path to the project root (for resolving image paths). */
    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? dirname(__DIR__, 2);
    }

    /**
     * Render the given rows as a PDF.
     *
     * @param list<IntakeExportRow> $rows
     * @param array{
     *     patient_name?:string,
     *     period_label?:string,
     *     generated_at?:string
     * } $meta Optional display metadata. Missing keys are filled from the
     *         first row / current time.
     */
    public function toPdf(array $rows, array $meta = []): string
    {
        $html = $this->renderHtml($rows, $meta);

        $options = new Options();
        // Local image + font loading only. Explicit default, but being
        // explicit matters because Dompdf's Options object changes
        // defaults between major versions.
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setChroot([$this->basePath]);
        // DejaVu ships with Dompdf and covers Latin-1 + most diacritics;
        // caregivers occasionally enter notes with accented characters
        // (medicine brand names, Spanish-language notes, etc).
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('Letter', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // Dompdf's CSS counter(pages) is unreliable across 3.x versions —
        // the CSS runs before the final paginated count is known and
        // frequently reports "0". Stamp the page-number line via the
        // canvas after rendering, which is the documented reliable path.
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            $canvas->get_width() - 80,
            $canvas->get_height() - 22,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            null,
            7,
            [0.44, 0.50, 0.59] // #718096 to match the CSS footer
        );

        return (string) $dompdf->output();
    }

    /**
     * Public purely so the integration test can eyeball the HTML layer
     * without running Dompdf. Not part of the stable API.
     *
     * @param list<IntakeExportRow> $rows
     * @param array<string,string>  $meta
     */
    public function renderHtml(array $rows, array $meta = []): string
    {
        $patientName = $meta['patient_name']
            ?? ($rows[0]['patient_name'] ?? 'Unknown patient');
        $periodLabel = $meta['period_label']
            ?? $this->periodLabelFromRows($rows);
        $generatedAt = $meta['generated_at']
            ?? date('Y-m-d H:i');

        // Group intakes by calendar date (YYYY-MM-DD).
        $byDate = [];
        foreach ($rows as $row) {
            $date = substr($row['taken_time'], 0, 10);
            $byDate[$date] ??= [];
            $byDate[$date][] = $row;
        }
        ksort($byDate);

        $logoSrc = $this->logoAsDataUri();
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $totalIntakes = count($rows);
        $totalDays = count($byDate);

        $body = '';
        if ($totalIntakes === 0) {
            $body .= '<p class="empty">No intakes recorded for this period.</p>';
        } else {
            foreach ($byDate as $date => $dayRows) {
                $body .= '<div class="day-block">';
                $body .= '<h2>' . $esc(self::formatDateHeading($date)) . '</h2>';
                $body .= '<table class="day-table">';
                $body .= '<thead><tr>'
                    . '<th class="col-time">Time</th>'
                    . '<th class="col-med">Medication</th>'
                    . '<th class="col-dose">Dose</th>'
                    . '<th class="col-freq">Frequency</th>'
                    . '</tr></thead>';
                $body .= '<tbody>';
                foreach ($dayRows as $r) {
                    $time = strlen((string) $r['taken_time']) >= 16
                        ? substr((string) $r['taken_time'], 11, 5)
                        : '';
                    $body .= '<tr>';
                    $body .= '<td class="col-time">' . $esc($time) . '</td>';
                    $body .= '<td class="col-med">'
                        . '<div class="med-name">' . $esc((string) $r['medicine_name']) . '</div>';
                    $note = (string) ($r['note'] ?? '');
                    if ($note !== '') {
                        $body .= '<div class="note">' . $esc($note) . '</div>';
                    }
                    $body .= '</td>';
                    $body .= '<td class="col-dose">'
                        . $esc(self::formatDose(
                            (float) $r['unit_per_dose'],
                            (string) $r['medicine_dosage']
                        ))
                        . '</td>';
                    $body .= '<td class="col-freq">' . $esc((string) $r['frequency']) . '</td>';
                    $body .= '</tr>';
                }
                $body .= '</tbody></table>';
                $body .= '</div>';
            }
        }

        $footerLeft = 'HomeCare intake report · generated ' . $esc($generatedAt);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Intake report — {$esc($patientName)} — {$esc($periodLabel)}</title>
<style>
  @page {
    /* Tight-but-safe home-printer margins. Top/left/right give LaserJets
       enough bleed; bottom leaves room for the fixed footer + page num. */
    margin: 0.4in 0.45in 0.6in 0.45in;
  }
  body {
    font-family: "DejaVu Sans", sans-serif;
    font-size: 9pt;
    line-height: 1.25;
    color: #1a202c;
    margin: 0;
  }
  header.report-header {
    display: block;
    border-bottom: 1.5pt solid #0d6efd;
    padding-bottom: 5pt;
    margin-bottom: 8pt;
  }
  header.report-header table {
    width: 100%;
    border-collapse: collapse;
  }
  header.report-header td {
    vertical-align: middle;
    padding: 0;
  }
  header.report-header .logo-cell {
    width: 42pt;
  }
  header.report-header .logo-cell img {
    width: 36pt;
    height: 36pt;
  }
  header.report-header .title-cell h1 {
    font-size: 14pt;
    margin: 0;
    color: #0d6efd;
    font-weight: bold;
    line-height: 1.1;
  }
  header.report-header .title-cell .subtitle {
    font-size: 9.5pt;
    margin-top: 1pt;
    color: #4a5568;
  }
  header.report-header .summary-cell {
    text-align: right;
    font-size: 8pt;
    color: #4a5568;
    white-space: nowrap;
    line-height: 1.2;
  }

  /* .day-block NO LONGER uses page-break-inside: avoid. Dompdf was
     pushing entire day blocks to a fresh page whenever it thought the
     block wouldn't fit, which left the first page almost empty when
     there were many intakes per day. Rows are individually atomic
     instead (via tr page-break-inside) so intakes never get cut in
     half, but a long day can wrap naturally across pages. */
  .day-block {
    margin-bottom: 8pt;
  }
  .day-block h2 {
    font-size: 9.5pt;
    font-weight: bold;
    color: #2c5282;
    margin: 0 0 2pt 0;
    padding: 2pt 5pt;
    background: #ebf4ff;
    border-left: 2.5pt solid #0d6efd;
    /* Keep the day heading glued to the first row that follows. */
    page-break-after: avoid;
  }
  table.day-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9pt;
    /* Fixed layout forces the browser/Dompdf to honor col widths
       exactly instead of auto-sizing to the longest content, which
       was giving the medication name 380pt and squeezing the dose
       column — where the sig phrase actually lives — into 80pt. */
    table-layout: fixed;
  }
  table.day-table th {
    text-align: left;
    font-weight: bold;
    color: #2d3748;
    border-bottom: 0.75pt solid #cbd5e0;
    padding: 2pt 4pt;
    font-size: 8.5pt;
  }
  table.day-table td {
    vertical-align: top;
    padding: 2pt 4pt;
    border-bottom: 0.5pt solid #edf2f7;
  }
  /* Never split a single intake across pages — every other row is
     cheap to repeat on a new page header if needed. */
  table.day-table tr {
    page-break-inside: avoid;
  }
  table.day-table tr:last-child td {
    border-bottom: 0;
  }
  /* Column widths as percentages summing to exactly 100%. Declaring
     widths in pt that didn't sum to the table width made Dompdf
     distribute the leftover into the first column, which is why the
     Time column used to eat 25% of the page even though it only
     needed to show "HH:MM". Percentages + table-layout: fixed is the
     deterministic path.
       Time:  7%  (~38pt, comfortable for "HH:MM" + padding)
       Med:   38% (~210pt, fits most product names on ≤ 2 lines)
       Dose:  47% (~260pt, fits most sig phrases on ≤ 2 lines)
       Freq:  8%  (~44pt, "12h"/"1d" plus padding) */
  .col-time { width: 7%;  white-space: nowrap; font-variant-numeric: tabular-nums; }
  .col-med  { width: 38%; }
  .col-dose { width: 47%; color: #4a5568; font-size: 8.5pt; }
  .col-freq { width: 8%;  color: #4a5568; white-space: nowrap; font-size: 8.5pt; }
  .med-name { font-weight: bold; }
  .note {
    font-style: italic;
    color: #4a5568;
    font-size: 8pt;
    margin-top: 0;
    line-height: 1.2;
  }
  .empty {
    font-style: italic;
    color: #718096;
    text-align: center;
    padding: 30pt 0;
  }
  footer.report-footer {
    position: fixed;
    left: 0.5in;
    right: 0.5in;
    bottom: 0.2in;
    font-size: 7pt;
    color: #718096;
    border-top: 0.5pt solid #e2e8f0;
    padding-top: 3pt;
  }
  footer.report-footer table {
    width: 100%;
    border-collapse: collapse;
  }
  footer.report-footer .right {
    text-align: right;
  }
</style>
</head>
<body>

<header class="report-header">
  <table>
    <tr>
      <td class="logo-cell"><img src="{$logoSrc}" alt=""></td>
      <td class="title-cell">
        <h1>Intake Report</h1>
        <div class="subtitle">{$esc($patientName)} · {$esc($periodLabel)}</div>
      </td>
      <td class="summary-cell">
        {$totalIntakes} intake{$this->plural($totalIntakes)}<br>
        {$totalDays} day{$this->plural($totalDays)} with activity
      </td>
    </tr>
  </table>
</header>

{$body}

<footer class="report-footer">
  {$footerLeft}
  <!-- Page number is stamped via Dompdf's canvas::page_text() after
       render, so it's aligned on the right of this footer at runtime. -->
</footer>

</body>
</html>
HTML;
    }

    /**
     * Embed the PWA icon directly in the HTML as a data URI so Dompdf
     * doesn't need filesystem access at render time (and so the HTML is
     * self-contained enough to log / inspect / debug in isolation).
     * Falls back to a tiny neutral placeholder if the icon is missing.
     */
    private function logoAsDataUri(): string
    {
        $candidates = [
            $this->basePath . '/pub/icons/icon-192.png',
            $this->basePath . '/pub/icons/icon-512.png',
        ];
        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                $bytes = file_get_contents($path);
                if ($bytes !== false) {
                    return 'data:image/png;base64,' . base64_encode($bytes);
                }
            }
        }

        // 1×1 transparent pixel. Prevents Dompdf from warning about a
        // missing <img src> when the branding assets aren't deployed.
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';
    }

    /**
     * @param list<IntakeExportRow> $rows
     */
    private function periodLabelFromRows(array $rows): string
    {
        if ($rows === []) {
            return '—';
        }
        $first = substr((string) $rows[0]['taken_time'], 0, 10);
        $last = substr((string) end($rows)['taken_time'], 0, 10);

        return $first === $last ? $first : ($first . ' – ' . $last);
    }

    private static function formatDateHeading(string $ymd): string
    {
        $ts = strtotime($ymd);
        if ($ts === false) {
            return $ymd;
        }

        // "Sunday, February 1, 2026"
        return date('l, F j, Y', $ts);
    }

    private static function formatDose(float $unitPerDose, string $medicineDosage): string
    {
        $unit = rtrim(rtrim(sprintf('%.2f', $unitPerDose), '0'), '.');
        if ($unit === '') {
            $unit = '0';
        }
        if ($medicineDosage === '') {
            return $unit;
        }

        return $unit . ' × ' . $medicineDosage;
    }

    private function plural(int $n): string
    {
        return $n === 1 ? '' : 's';
    }
}
