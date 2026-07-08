<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Export;

use HomeCare\Export\MarkdownIntakeExporter;
use PHPUnit\Framework\TestCase;

final class MarkdownIntakeExporterTest extends TestCase
{
    private MarkdownIntakeExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new MarkdownIntakeExporter();
    }

    public function testEmptyRowSetProducesEmptyStateCopy(): void
    {
        $md = $this->exporter->toMarkdown([], [
            'patient_name' => 'Daisy',
            'period_label' => 'February 2026',
        ]);

        $this->assertStringContainsString('# Intake History — Daisy', $md);
        $this->assertStringContainsString('**Period:** February 2026', $md);
        $this->assertStringContainsString('_No intakes recorded', $md);
    }

    public function testSingleIntakeRendersTableRow(): void
    {
        $md = $this->exporter->toMarkdown([self::row(
            medicine_name: 'Thyroxine',
            medicine_dosage: '0.2mg',
            unit_per_dose: 1.0,
            taken_time: '2026-02-14 08:00:00',
            note: 'took with food',
        )]);

        $this->assertStringContainsString('## Saturday, February 14, 2026', $md);
        $this->assertStringContainsString('| Time | Medication | Dose | Notes |', $md);
        $this->assertStringContainsString('| 08:00 | Thyroxine (0.2mg) | ×1 | took with food |', $md);
        $this->assertStringContainsString('**Total:** 1 intake across 1 day.', $md);
    }

    public function testPipeInNoteIsEscapedSoTableLayoutSurvives(): void
    {
        $md = $this->exporter->toMarkdown([self::row(note: 'a | b')]);
        // The literal pipe must be escaped, not left to split the cell.
        $this->assertStringContainsString('a \\| b', $md);
    }

    public function testMultilineNoteCollapsedToOneRow(): void
    {
        $md = $this->exporter->toMarkdown([self::row(note: "line one\nline two")]);
        $this->assertStringContainsString('line one line two', $md);
        $this->assertStringNotContainsString("line one\nline two", $md);
    }

    public function testDayGroupingProducesOneTablePerDay(): void
    {
        $md = $this->exporter->toMarkdown([
            self::row(taken_time: '2026-02-14 08:00:00'),
            self::row(taken_time: '2026-02-15 08:00:00'),
        ]);

        $this->assertSame(2, substr_count($md, '## '), 'one heading per calendar day');
        $this->assertSame(2, substr_count($md, '| Time | Medication | Dose | Notes |'));
        $this->assertStringContainsString('**Total:** 2 intakes across 2 days.', $md);
    }

    /**
     * @return array{
     *     intake_id:int,schedule_id:int,patient_id:int,patient_name:string,
     *     medicine_id:int,medicine_name:string,medicine_dosage:string,
     *     frequency:string,unit_per_dose:float,taken_time:string,note:?string
     * }
     */
    private static function row(
        int $intake_id = 1,
        int $schedule_id = 1,
        int $patient_id = 1,
        string $patient_name = 'Daisy',
        int $medicine_id = 1,
        string $medicine_name = 'Thyroxine',
        string $medicine_dosage = '0.2mg',
        string $frequency = '12h',
        float $unit_per_dose = 1.0,
        string $taken_time = '2026-02-14 08:00:00',
        ?string $note = null,
    ): array {
        return [
            'intake_id' => $intake_id,
            'schedule_id' => $schedule_id,
            'patient_id' => $patient_id,
            'patient_name' => $patient_name,
            'medicine_id' => $medicine_id,
            'medicine_name' => $medicine_name,
            'medicine_dosage' => $medicine_dosage,
            'frequency' => $frequency,
            'unit_per_dose' => $unit_per_dose,
            'taken_time' => $taken_time,
            'note' => $note,
        ];
    }
}
