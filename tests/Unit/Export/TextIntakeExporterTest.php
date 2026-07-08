<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Export;

use HomeCare\Export\TextIntakeExporter;
use PHPUnit\Framework\TestCase;

final class TextIntakeExporterTest extends TestCase
{
    private TextIntakeExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new TextIntakeExporter();
    }

    public function testEmptyRowSetProducesEmptyStateCopy(): void
    {
        $text = $this->exporter->toText([], [
            'patient_name' => 'Daisy',
            'period_label' => 'February 2026',
        ]);

        $this->assertStringContainsString('Intake History — Daisy', $text);
        $this->assertStringContainsString('Period: February 2026', $text);
        $this->assertStringContainsString('No intakes recorded', $text);
    }

    public function testSingleIntakeRendersTimeMedDoseAndNote(): void
    {
        $text = $this->exporter->toText([self::row(
            medicine_name: 'Thyroxine',
            medicine_dosage: '0.2mg',
            unit_per_dose: 1.0,
            taken_time: '2026-02-14 08:00:00',
            note: 'took with food',
        )]);

        $this->assertStringContainsString('Saturday, February 14, 2026', $text);
        $this->assertStringContainsString('08:00', $text);
        $this->assertStringContainsString('Thyroxine (0.2mg) ×1', $text);
        $this->assertStringContainsString('— took with food', $text);
        $this->assertStringContainsString('Total: 1 intake across 1 day.', $text);
    }

    public function testHalfDoseTrimsTrailingZeros(): void
    {
        $text = $this->exporter->toText([self::row(unit_per_dose: 0.5)]);
        $this->assertStringContainsString('×0.5', $text);
    }

    public function testRowsGroupedByDayAndTotalsCount(): void
    {
        $text = $this->exporter->toText([
            self::row(taken_time: '2026-02-14 08:00:00'),
            self::row(taken_time: '2026-02-14 20:00:00'),
            self::row(taken_time: '2026-02-15 08:00:00'),
        ]);

        $this->assertStringContainsString('February 14, 2026', $text);
        $this->assertStringContainsString('February 15, 2026', $text);
        $this->assertStringContainsString('Total: 3 intakes across 2 days.', $text);
    }

    public function testMultilineNoteCollapsedToOneLine(): void
    {
        $text = $this->exporter->toText([self::row(note: "line one\nline two")]);
        $this->assertStringContainsString('— line one line two', $text);
        // The note itself must not introduce a raw newline mid-entry.
        $this->assertStringNotContainsString("line one\nline two", $text);
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
