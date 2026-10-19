<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\PatientLinkRepository;
use HomeCare\Tests\Factory\IntakeFactory;
use HomeCare\Tests\Factory\MedicineFactory;
use HomeCare\Tests\Factory\PatientFactory;
use HomeCare\Tests\Factory\ScheduleFactory;
use HomeCare\Tests\Integration\DatabaseTestCase;

/**
 * HC-237: indirect id (schedule / intake / note / attachment / medicine)
 * to patient lookups.
 */
final class PatientLinkRepositoryTest extends DatabaseTestCase
{
    private PatientLinkRepository $links;

    private int $daisy;

    private int $fozzie;

    private int $sildenafil;

    private int $furosemide;

    private int $unlinked;

    private int $daisySchedule;

    private int $fozzieSchedule;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $this->links = new PatientLinkRepository($db);

        $this->daisy = (new PatientFactory($db))->create(['name' => 'Daisy'])['id'];
        $this->fozzie = (new PatientFactory($db))->create(['name' => 'Fozzie'])['id'];
        $this->sildenafil = (new MedicineFactory($db))->create(['name' => 'Sildenafil'])['id'];
        $this->furosemide = (new MedicineFactory($db))->create(['name' => 'Furosemide'])['id'];
        $this->unlinked = (new MedicineFactory($db))->create(['name' => 'Unused'])['id'];

        $schedules = new ScheduleFactory($db);
        $this->daisySchedule = $schedules->create(['patient_id' => $this->daisy, 'medicine_id' => $this->sildenafil])['id'];
        $this->fozzieSchedule = $schedules->create(['patient_id' => $this->fozzie, 'medicine_id' => $this->sildenafil])['id'];
        $schedules->create(['patient_id' => $this->fozzie, 'medicine_id' => $this->furosemide]);
    }

    public function testScheduleResolvesToPatient(): void
    {
        $this->assertSame($this->daisy, $this->links->patientIdForSchedule($this->daisySchedule));
        $this->assertSame($this->fozzie, $this->links->patientIdForSchedule($this->fozzieSchedule));
        $this->assertNull($this->links->patientIdForSchedule(9999));
    }

    public function testIntakeResolvesThroughSchedule(): void
    {
        $intake = (new IntakeFactory($this->getDb()))->create(['schedule_id' => $this->fozzieSchedule]);

        $this->assertSame($this->fozzie, $this->links->patientIdForIntake($intake['id']));
        $this->assertNull($this->links->patientIdForIntake(9999));
    }

    public function testNoteResolvesToPatient(): void
    {
        $this->getDb()->execute(
            'INSERT INTO hc_caregiver_notes (patient_id, note) VALUES (?, ?)',
            [$this->daisy, 'Ate well'],
        );
        $noteId = $this->getDb()->lastInsertId();

        $this->assertSame($this->daisy, $this->links->patientIdForNote($noteId));
        $this->assertNull($this->links->patientIdForNote(9999));
    }

    public function testAttachmentResolvesForEachOwnerType(): void
    {
        $this->getDb()->execute(
            'INSERT INTO hc_caregiver_notes (patient_id, note) VALUES (?, ?)',
            [$this->fozzie, 'Vet visit'],
        );
        $noteId = $this->getDb()->lastInsertId();

        $onPatient = $this->attachment('patient', $this->daisy);
        $onSchedule = $this->attachment('schedule', $this->fozzieSchedule);
        $onNote = $this->attachment('note', $noteId);
        $bogus = $this->attachment('intake', 1);

        $this->assertSame($this->daisy, $this->links->patientIdForAttachment($onPatient));
        $this->assertSame($this->fozzie, $this->links->patientIdForAttachment($onSchedule));
        $this->assertSame($this->fozzie, $this->links->patientIdForAttachment($onNote));
        $this->assertNull($this->links->patientIdForAttachment($bogus), 'unknown owner type');
        $this->assertNull($this->links->patientIdForAttachment(9999));
    }

    public function testOwnerLookupForMissingPatient(): void
    {
        $this->assertNull($this->links->patientIdForOwner('patient', 9999));
        $this->assertSame($this->daisy, $this->links->patientIdForOwner('patient', $this->daisy));
    }

    public function testMedicineResolvesToEveryLinkedPatient(): void
    {
        $this->assertSame([$this->daisy, $this->fozzie], $this->links->patientIdsForMedicine($this->sildenafil));
        $this->assertSame([$this->fozzie], $this->links->patientIdsForMedicine($this->furosemide));
        $this->assertSame([], $this->links->patientIdsForMedicine($this->unlinked));
    }

    public function testPatientIdsByMedicineMap(): void
    {
        $map = $this->links->patientIdsByMedicine();

        $this->assertSame([$this->daisy, $this->fozzie], $map[$this->sildenafil]);
        $this->assertSame([$this->fozzie], $map[$this->furosemide]);
        $this->assertArrayNotHasKey($this->unlinked, $map);
    }

    private function attachment(string $ownerType, int $ownerId): int
    {
        $this->getDb()->execute(
            'INSERT INTO hc_attachments (owner_type, owner_id, filename, mime_type, size_bytes, sha256, storage_path, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$ownerType, $ownerId, 'a.pdf', 'application/pdf', 1, str_repeat('0', 64), 'x/a.pdf', 'carol'],
        );

        return $this->getDb()->lastInsertId();
    }
}
