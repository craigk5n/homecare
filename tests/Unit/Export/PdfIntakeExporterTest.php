<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Export;

use HomeCare\Export\PdfIntakeExporter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PdfIntakeExporter. We don't re-parse the PDF stream
 * to assert every visual pixel — that would make the tests brittle
 * against Dompdf version bumps. Instead we assert:
 *
 *   - the bytes are a valid PDF (signature + non-empty)
 *   - the _HTML_ layer (public renderHtml) contains the expected
 *     patient name, dates, medication names, and notes, properly
 *     HTML-escaped
 *
 * The HTML-layer assertions catch the great majority of data-shape
 * regressions without coupling us to Dompdf's output.
 */
final class PdfIntakeExporterTest extends TestCase
{
    private PdfIntakeExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new PdfIntakeExporter();
    }

    public function testEmptyRowSetStillProducesValidPdfWithEmptyStateCopy(): void
    {
        $pdf = $this->exporter->toPdf([], [
            'patient_name' => 'Daisy',
            'period_label' => 'February 2026',
            'generated_at' => '2026-04-14 10:00',
        ]);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(500, strlen($pdf));

        $html = $this->exporter->renderHtml([], [
            'patient_name' => 'Daisy',
            'period_label' => 'February 2026',
        ]);
        $this->assertStringContainsString('Daisy', $html);
        $this->assertStringContainsString('February 2026', $html);
        $this->assertStringContainsString('No intakes recorded', $html);
    }

    public function testSingleIntakeRendersPatientMedAndDate(): void
    {
        $rows = [self::row([
            'patient_name' => 'Daisy',
            'medicine_name' => 'Thyroxine',
            'medicine_dosage' => '0.2mg',
            'taken_time' => '2026-02-14 08:00:00',
            'note' => 'took with food',
        ])];

        $html = $this->exporter->renderHtml($rows);
        $this->assertStringContainsString('Daisy', $html);
        $this->assertStringContainsString('Thyroxine', $html);
        $this->assertStringContainsString('0.2mg', $html);
        $this->assertStringContainsString('08:00', $html);
        $this->assertStringContainsString('took with food', $html);
        // Long-form day heading ("Saturday, February 14, 2026")
        $this->assertStringContainsString('February 14, 2026', $html);
    }

    public function testNotesAreHtmlEscapedToPreventInjection(): void
    {
        $rows = [self::row([
            'note' => 'took <script>alert(1)</script> with food',
        ])];
        $html = $this->exporter->renderHtml($rows);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRowsAreGroupedByCalendarDay(): void
    {
        $rows = [
            self::row(['taken_time' => '2026-02-14 08:00:00', 'medicine_name' => 'Thyroxine']),
            self::row(['taken_time' => '2026-02-14 20:00:00', 'medicine_name' => 'Thyroxine']),
            self::row(['taken_time' => '2026-02-15 08:00:00', 'medicine_name' => 'Vetmedin']),
        ];
        $html = $this->exporter->renderHtml($rows);

        // Two day headings, one per calendar day.
        $this->assertSame(
            2,
            substr_count($html, '<h2>'),
            'one h2 heading per calendar day'
        );
        $this->assertStringContainsString('February 14, 2026', $html);
        $this->assertStringContainsString('February 15, 2026', $html);
    }

    public function testSummaryCountsReflectRows(): void
    {
        $rows = [
            self::row(['taken_time' => '2026-02-14 08:00:00']),
            self::row(['taken_time' => '2026-02-14 20:00:00']),
            self::row(['taken_time' => '2026-02-15 08:00:00']),
        ];
        $html = $this->exporter->renderHtml($rows);

        $this->assertStringContainsString('3 intakes', $html);
        $this->assertStringContainsString('2 days with activity', $html);
    }

    public function testLogoIsEmbeddedAsDataUri(): void
    {
        $html = $this->exporter->renderHtml([], ['patient_name' => 'Daisy']);
        // Either the PWA icon or the 1x1 fallback — both are data: URIs,
        // which is what matters for offline / LAN-isolated rendering.
        $this->assertMatchesRegularExpression('/<img src="data:image\/png;base64,/', $html);
    }

    public function testPdfIsReproducibleEnoughToRender(): void
    {
        // A small integration-style check that toPdf() doesn't just
        // succeed on empty input — give it real data and verify PDF
        // text extraction contains the data. Skipped if pdftotext is
        // missing from the CI image.
        if (!self::commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext not available on this host');
        }

        $pdf = $this->exporter->toPdf(
            [self::row([
                'patient_name' => 'Daisy',
                'medicine_name' => 'Thyroxine',
                'taken_time' => '2026-02-14 08:00:00',
            ])],
            ['patient_name' => 'Daisy', 'period_label' => 'February 2026'],
        );

        $tmp = tempnam(sys_get_temp_dir(), 'hc_pdf_');
        file_put_contents($tmp, $pdf);
        try {
            $text = shell_exec('pdftotext ' . escapeshellarg($tmp) . ' - 2>/dev/null');
            $this->assertIsString($text);
            $this->assertStringContainsString('Daisy', $text);
            $this->assertStringContainsString('Thyroxine', $text);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array{
     *     intake_id:int,schedule_id:int,patient_id:int,patient_name:string,
     *     medicine_id:int,medicine_name:string,medicine_dosage:string,
     *     frequency:string,unit_per_dose:float,taken_time:string,note:?string
     * }
     */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'intake_id' => 1,
            'schedule_id' => 1,
            'patient_id' => 1,
            'patient_name' => 'Daisy',
            'medicine_id' => 1,
            'medicine_name' => 'Thyroxine',
            'medicine_dosage' => '0.2mg',
            'frequency' => '12h',
            'unit_per_dose' => 1.0,
            'taken_time' => '2026-02-14 08:00:00',
            'note' => null,
        ], $overrides);
    }

    private static function commandExists(string $name): bool
    {
        $out = shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');

        return is_string($out) && trim($out) !== '';
    }
}
