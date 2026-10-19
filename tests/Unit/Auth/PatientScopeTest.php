<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\PatientScope;
use PHPUnit\Framework\TestCase;

final class PatientScopeTest extends TestCase
{
    public function testUnrestrictedAllowsEveryPatient(): void
    {
        $scope = PatientScope::unrestricted();

        $this->assertTrue($scope->isUnrestricted());
        $this->assertNull($scope->patientIds());
        $this->assertTrue($scope->allows(1));
        $this->assertTrue($scope->allows(999));
    }

    public function testOnlyAllowsListedPatients(): void
    {
        $scope = PatientScope::only([3, 1]);

        $this->assertFalse($scope->isUnrestricted());
        $this->assertSame([1, 3], $scope->patientIds());
        $this->assertTrue($scope->allows(1));
        $this->assertTrue($scope->allows(3));
        $this->assertFalse($scope->allows(2));
    }

    public function testOnlyDeduplicatesAndDropsNonPositiveIds(): void
    {
        $scope = PatientScope::only([2, 2, 0, -1, 5]);

        $this->assertSame([2, 5], $scope->patientIds());
    }

    public function testEmptyListAllowsNothing(): void
    {
        $scope = PatientScope::only([]);

        $this->assertFalse($scope->isUnrestricted());
        $this->assertSame([], $scope->patientIds());
        $this->assertFalse($scope->allows(1));
    }

    public function testFilterKeepsOnlyAllowedRowsAndReindexes(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Daisy'],
            ['id' => '2', 'name' => 'Fozzie'],
            ['id' => 3, 'name' => 'Kermit'],
        ];

        $filtered = PatientScope::only([2, 3])->filter($rows);

        $this->assertSame([
            ['id' => '2', 'name' => 'Fozzie'],
            ['id' => 3, 'name' => 'Kermit'],
        ], $filtered);
    }

    public function testFilterHonoursCustomKey(): void
    {
        $rows = [
            ['schedule_id' => 10, 'patient_id' => 1],
            ['schedule_id' => 11, 'patient_id' => 2],
        ];

        $filtered = PatientScope::only([1])->filter($rows, 'patient_id');

        $this->assertSame([['schedule_id' => 10, 'patient_id' => 1]], $filtered);
    }

    public function testFilterDropsRowsWithoutTheKeyWhenRestricted(): void
    {
        $filtered = PatientScope::only([1])->filter([['name' => 'orphan']]);

        $this->assertSame([], $filtered);
    }

    public function testAllowsSharedResource(): void
    {
        $scope = PatientScope::only([2]);

        $this->assertTrue($scope->allowsShared([1, 2]), 'linked to an allowed patient');
        $this->assertFalse($scope->allowsShared([1, 3]), 'only other patients');
        $this->assertTrue($scope->allowsShared([]), 'not linked to anyone');
        $this->assertTrue(PatientScope::unrestricted()->allowsShared([1]));
    }

    public function testUnrestrictedFilterReturnsRowsUnchanged(): void
    {
        $rows = [['id' => 1], ['id' => 2]];

        $this->assertSame($rows, PatientScope::unrestricted()->filter($rows));
    }
}
