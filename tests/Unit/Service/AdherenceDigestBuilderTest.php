<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Service;

use HomeCare\Service\AdherenceDigestBuilder;
use HomeCare\Service\AdherenceDigestPatient;
use HomeCare\Service\AdherenceDigestRow;
use PHPUnit\Framework\TestCase;

final class AdherenceDigestBuilderTest extends TestCase
{
    private AdherenceDigestBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new AdherenceDigestBuilder();
    }

    public function testHeaderIncludesRunDate(): void
    {
        $body = $this->builder->build('2026-04-13', []);

        $this->assertStringStartsWith(
            'Weekly adherence digest — 2026-04-13',
            $body
        );
    }

    public function testEmptyPatientListShowsPlaceholder(): void
    {
        $body = $this->builder->build('2026-04-13', []);

        $this->assertStringContainsString('No patients are active yet.', $body);
    }

    public function testPatientWithNoRowsShowsNoIntakesMessage(): void
    {
        $body = $this->builder->build('2026-04-13', [
            new AdherenceDigestPatient('Fozzie'),
        ]);

        $this->assertStringContainsString('Fozzie', $body);
        $this->assertStringContainsString('No intakes this week.', $body);
        // No "Medication" table header when the patient has no rows.
        $fozzieBlock = strstr($body, 'Fozzie') ?: '';
        $this->assertStringNotContainsString('Medication', $fozzieBlock);
    }

    public function testCheckmarkAtExactNinetyThreshold(): void
    {
        $body = $this->builder->build('2026-04-13', [
            new AdherenceDigestPatient('Daisy', [
                new AdherenceDigestRow('Tobra', 90.0, 92.5),
            ]),
        ]);

        $this->assertStringContainsString('✓ 90%', $body);
        $this->assertStringContainsString('✓ 92.5%', $body);
    }

    public function testWarningAtExactSeventyAndJustBelowNinety(): void
    {
        $body = $this->builder->build('2026-04-13', [
            new AdherenceDigestPatient('Daisy', [
                new AdherenceDigestRow('Tobra', 70.0, 89.9),
            ]),
        ]);

        $this->assertStringContainsString('⚠ 70%', $body);
        $this->assertStringContainsString('⚠ 89.9%', $body);
    }

    public function testCrossBelowSeventy(): void
    {
        $body = $this->builder->build('2026-04-13', [
            new AdherenceDigestPatient('Daisy', [
                new AdherenceDigestRow('Tobra', 69.9, 50.0),
            ]),
        ]);

        $this->assertStringContainsString('✗ 69.9%', $body);
        $this->assertStringContainsString('✗ 50%', $body);
    }

    public function testSectionOrderingFollowsInputOrder(): void
    {
        $body = $this->builder->build('2026-04-13', [
            new AdherenceDigestPatient('Zelda', [
                new AdherenceDigestRow('Vitamin A', 95.0, 92.0),
            ]),
            new AdherenceDigestPatient('Apollo', [
                new AdherenceDigestRow('Vitamin B', 88.0, 90.0),
            ]),
        ]);

        $posZelda = strpos($body, 'Zelda');
        $posApollo = strpos($body, 'Apollo');
        $this->assertNotFalse($posZelda);
        $this->assertNotFalse($posApollo);
        $this->assertLessThan($posApollo, $posZelda,
            'Zelda appears first in input, should appear first in body');
    }

    public function testRowOrderingFollowsInputOrder(): void
    {
        $body = $this->builder->build('2026-04-13', [
            new AdherenceDigestPatient('Daisy', [
                new AdherenceDigestRow('Zoloft', 95.0, 95.0),
                new AdherenceDigestRow('Aspirin', 80.0, 80.0),
            ]),
        ]);

        $posZoloft = strpos($body, 'Zoloft');
        $posAspirin = strpos($body, 'Aspirin');
        $this->assertNotFalse($posZoloft);
        $this->assertNotFalse($posAspirin);
        $this->assertLessThan($posAspirin, $posZoloft);
    }

    public function testBoundaryExactlyAt70IsWarnNotCross(): void
    {
        // Defensive: threshold uses `>=` so exact 70 must render ⚠.
        $body = $this->builder->build('2026-04-13', [
            new AdherenceDigestPatient('Daisy', [
                new AdherenceDigestRow('Tobra', 70.0, 69.9),
            ]),
        ]);

        $this->assertStringContainsString('⚠ 70%', $body);
        $this->assertStringContainsString('✗ 69.9%', $body);
    }

    public function testTableHeaderIsPresentWhenRowsExist(): void
    {
        $body = $this->builder->build('2026-04-13', [
            new AdherenceDigestPatient('Daisy', [
                new AdherenceDigestRow('Tobra', 95.0, 95.0),
            ]),
        ]);

        $this->assertStringContainsString('Medication', $body);
        $this->assertStringContainsString('7-day', $body);
        $this->assertStringContainsString('30-day', $body);
    }
}
