<?php

declare(strict_types=1);

namespace HomeCare\Tests\Unit\Auth;

use HomeCare\Auth\PatientAccess;
use HomeCare\Repository\PatientLinkRepositoryInterface;
use HomeCare\Repository\UserPatientRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * HC-237 rule table for {@see PatientAccess}, with in-memory fakes.
 *
 * Patients 1-3. Schedules 10 (patient 1), 20 (patient 2). Intake 100 on
 * schedule 10. Note 7 on patient 2. Attachment 50 on schedule 20.
 * Medicine 5 scheduled for patient 1 only, medicine 6 for patients 2+3,
 * medicine 8 for nobody.
 */
final class PatientAccessTest extends TestCase
{
    /** @var array<string,list<int>> */
    private array $assigned = [];

    /** @var array<string,string> */
    private array $roles = [
        'root' => 'admin',
        'carol' => 'caregiver',
        'vera' => 'viewer',
        'free' => 'caregiver',
    ];

    /** @var \ArrayObject<string,int>|null */
    private ?\ArrayObject $lookupCounter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assigned = [
            'carol' => [1],
            'vera' => [2, 3],
            'root' => [1],
        ];
    }

    public function testAdminIsUnrestrictedEvenWithRows(): void
    {
        $access = $this->access();

        $this->assertNull($access->allowedPatientIds('root'));
        $this->assertTrue($access->canAccess('root', 2));
    }

    public function testNonAdminWithoutRowsIsUnrestricted(): void
    {
        $access = $this->access();

        $this->assertNull($access->allowedPatientIds('free'));
        $this->assertTrue($access->canAccess('free', 3));
    }

    public function testNonAdminWithRowsIsLimitedToThem(): void
    {
        $access = $this->access();

        $this->assertSame([1], $access->allowedPatientIds('carol'));
        $this->assertTrue($access->canAccess('carol', 1));
        $this->assertFalse($access->canAccess('carol', 2));
        $this->assertSame([2, 3], $access->allowedPatientIds('vera'));
    }

    public function testUnknownLoginSeesNothing(): void
    {
        $access = $this->access();

        $this->assertSame([], $access->allowedPatientIds('ghost'));
        $this->assertFalse($access->canAccess('ghost', 1));
        $this->assertSame([], $access->allowedPatientIds(''));
    }

    public function testFilterPatients(): void
    {
        $rows = [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B'], ['id' => 3, 'name' => 'C']];
        $access = $this->access();

        $this->assertSame([['id' => 1, 'name' => 'A']], $access->filterPatients('carol', $rows));
        $this->assertSame($rows, $access->filterPatients('free', $rows));
    }

    public function testScopeIsCachedPerLogin(): void
    {
        $access = $this->access();
        $access->canAccess('carol', 1);
        $access->canAccess('carol', 2);
        $access->allowedPatientIds('carol');

        $this->assertNotNull($this->lookupCounter);
        $this->assertSame(1, $this->lookupCounter['n']);
    }

    public function testScheduleIntakeNoteAndAttachmentResolveToPatient(): void
    {
        $access = $this->access();

        $this->assertTrue($access->canAccessSchedule('carol', 10));
        $this->assertFalse($access->canAccessSchedule('carol', 20));
        $this->assertTrue($access->canAccessIntake('carol', 100));
        $this->assertFalse($access->canAccessNote('carol', 7));
        $this->assertTrue($access->canAccessNote('vera', 7));
        $this->assertFalse($access->canAccessAttachment('carol', 50));
        $this->assertTrue($access->canAccessAttachment('vera', 50));
        $this->assertTrue($access->canAccessAttachmentOwner('carol', 'schedule', 10));
        $this->assertFalse($access->canAccessAttachmentOwner('carol', 'patient', 2));
    }

    public function testMissingIndirectRowIsDeniedForRestrictedButAllowedForUnrestricted(): void
    {
        $access = $this->access();

        $this->assertFalse($access->canAccessSchedule('carol', 999));
        $this->assertFalse($access->canAccessAttachmentOwner('carol', 'bogus', 1));
        $this->assertTrue($access->canAccessSchedule('free', 999));
        $this->assertTrue($access->canAccessSchedule('root', 999));
    }

    public function testMedicineAccessFollowsLinkedPatients(): void
    {
        $access = $this->access();

        $this->assertTrue($access->canAccessMedicine('carol', 5));
        $this->assertFalse($access->canAccessMedicine('carol', 6));
        $this->assertTrue($access->canAccessMedicine('vera', 6));
        $this->assertTrue($access->canAccessMedicine('carol', 8), 'unlinked catalog item stays visible');
        $this->assertTrue($access->canAccessMedicine('free', 6));
    }

    public function testFilterMedicines(): void
    {
        $rows = [['medicine_id' => 5], ['medicine_id' => 6], ['medicine_id' => 8]];
        $access = $this->access();

        $this->assertSame([['medicine_id' => 5], ['medicine_id' => 8]], $access->filterMedicines('carol', $rows));
        $this->assertSame([['medicine_id' => 6], ['medicine_id' => 8]], $access->filterMedicines('vera', $rows));
        $this->assertSame($rows, $access->filterMedicines('root', $rows));
    }

    private function access(): PatientAccess
    {
        $counter = new \ArrayObject(['n' => 0]);
        $this->lookupCounter = $counter;
        $assignments = new class ($this->assigned, $counter) implements UserPatientRepositoryInterface {
            /**
             * @param array<string,list<int>>  $assigned
             * @param \ArrayObject<string,int> $counter
             */
            public function __construct(private array $assigned, private readonly \ArrayObject $counter) {}

            public function getPatientIds(string $login): array
            {
                $this->counter['n'] = $this->counter['n'] + 1;

                return $this->assigned[$login] ?? [];
            }

            public function replacePatientIds(string $login, array $patientIds): void
            {
                $this->assigned[$login] = $patientIds;
            }
        };

        $links = new class implements PatientLinkRepositoryInterface {
            public function patientIdForSchedule(int $scheduleId): ?int
            {
                return [10 => 1, 20 => 2][$scheduleId] ?? null;
            }

            public function patientIdForIntake(int $intakeId): ?int
            {
                return $intakeId === 100 ? 1 : null;
            }

            public function patientIdForNote(int $noteId): ?int
            {
                return $noteId === 7 ? 2 : null;
            }

            public function patientIdForAttachment(int $attachmentId): ?int
            {
                return $attachmentId === 50 ? 2 : null;
            }

            public function patientIdForOwner(string $ownerType, int $ownerId): ?int
            {
                return match ($ownerType) {
                    'patient' => $ownerId,
                    'schedule' => $this->patientIdForSchedule($ownerId),
                    'note' => $this->patientIdForNote($ownerId),
                    default => null,
                };
            }

            public function patientIdsForMedicine(int $medicineId): array
            {
                return $this->patientIdsByMedicine()[$medicineId] ?? [];
            }

            public function patientIdsByMedicine(): array
            {
                return [5 => [1], 6 => [2, 3]];
            }
        };

        $roles = $this->roles;

        return new PatientAccess($assignments, $links, static fn(string $login): ?string => $roles[$login] ?? null);
    }
}
