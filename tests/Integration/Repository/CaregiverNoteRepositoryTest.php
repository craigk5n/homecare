<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\CaregiverNoteRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class CaregiverNoteRepositoryTest extends DatabaseTestCase
{
    private CaregiverNoteRepository $repo;

    private int $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Daisy']);
        $this->patientId = $db->lastInsertId();
        $this->repo = new CaregiverNoteRepository($db);
    }

    public function testCreateReturnsIdAndPersistsRow(): void
    {
        $id = $this->repo->create(
            $this->patientId,
            'Ate well this morning',
            '2026-04-15 07:45:00',
        );

        $this->assertGreaterThan(0, $id);

        $note = $this->repo->getById($id);
        $this->assertNotNull($note);
        $this->assertSame($id, $note['id']);
        $this->assertSame($this->patientId, $note['patient_id']);
        $this->assertSame('Ate well this morning', $note['note']);
        $this->assertSame('2026-04-15 07:45:00', $note['note_time']);
    }

    public function testGetByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->getById(999));
    }

    public function testUpdateChangesNoteAndTime(): void
    {
        $id = $this->repo->create($this->patientId, 'original', '2026-04-15 07:45:00');

        $this->assertTrue(
            $this->repo->update($id, 'corrected', '2026-04-15 08:00:00'),
        );

        $note = $this->repo->getById($id);
        $this->assertNotNull($note);
        $this->assertSame('corrected', $note['note']);
        $this->assertSame('2026-04-15 08:00:00', $note['note_time']);
    }

    public function testDeleteRemovesRow(): void
    {
        $id = $this->repo->create($this->patientId, 'transient', '2026-04-15 07:45:00');

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->getById($id));
    }

    public function testGetForPatientReturnsNewestFirst(): void
    {
        $oldId = $this->repo->create($this->patientId, 'older', '2026-04-14 07:45:00');
        $newId = $this->repo->create($this->patientId, 'newer', '2026-04-15 07:45:00');
        $midId = $this->repo->create($this->patientId, 'middle', '2026-04-14 19:00:00');

        $notes = $this->repo->getForPatient($this->patientId);

        $ids = array_map(static fn(array $n): int => $n['id'], $notes);
        $this->assertSame([$newId, $midId, $oldId], $ids);
    }

    public function testGetForPatientScopesToPatient(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Fozzie']);
        $otherId = $db->lastInsertId();

        $mine = $this->repo->create($this->patientId, 'mine', '2026-04-15 07:45:00');
        $this->repo->create($otherId, 'theirs', '2026-04-15 07:45:00');

        $notes = $this->repo->getForPatient($this->patientId);

        $this->assertCount(1, $notes);
        $this->assertSame($mine, $notes[0]['id']);
    }

    public function testGetForPatientHonoursLimitAndOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repo->create(
                $this->patientId,
                'note ' . $i,
                sprintf('2026-04-%02d 07:45:00', 10 + $i),
            );
        }

        $page1 = $this->repo->getForPatient($this->patientId, limit: 2, offset: 0);
        $page2 = $this->repo->getForPatient($this->patientId, limit: 2, offset: 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        // Newest first → page 1 starts at Apr 14, page 2 at Apr 12.
        $this->assertSame('note 4', $page1[0]['note']);
        $this->assertSame('note 2', $page2[0]['note']);
    }

    public function testSearchFiltersByDateRange(): void
    {
        $this->repo->create($this->patientId, 'early', '2026-04-10 07:45:00');
        $this->repo->create($this->patientId, 'middle', '2026-04-15 07:45:00');
        $this->repo->create($this->patientId, 'late', '2026-04-20 07:45:00');

        $matches = $this->repo->search(
            $this->patientId,
            startDate: '2026-04-13 00:00:00',
            endDate: '2026-04-17 23:59:59',
        );

        $this->assertCount(1, $matches);
        $this->assertSame('middle', $matches[0]['note']);
    }

    public function testSearchFiltersByQueryString(): void
    {
        $this->repo->create($this->patientId, 'Ate 5 kibble, turkey', '2026-04-15 07:45:00');
        $this->repo->create($this->patientId, 'Vomit after cheese', '2026-04-15 09:00:00');
        $this->repo->create($this->patientId, 'Ate 5 kibble, potatoes', '2026-04-15 13:00:00');

        $matches = $this->repo->search($this->patientId, query: 'kibble');

        $this->assertCount(2, $matches);

        $vomit = $this->repo->search($this->patientId, query: 'Vomit');
        $this->assertCount(1, $vomit);
    }

    public function testSearchEscapesLikeWildcards(): void
    {
        $this->repo->create($this->patientId, 'weight up 3% since last visit', '2026-04-15 07:45:00');
        $this->repo->create($this->patientId, 'totally unrelated note', '2026-04-15 09:00:00');

        // The "%" must match literally, not act as a wildcard.
        $matches = $this->repo->search($this->patientId, query: '3%');

        $this->assertCount(1, $matches);
        $this->assertStringContainsString('3%', $matches[0]['note']);
    }

    public function testSearchScopesToPatient(): void
    {
        $db = $this->getDb();
        $db->execute('INSERT INTO hc_patients (name) VALUES (?)', ['Fozzie']);
        $otherId = $db->lastInsertId();

        $this->repo->create($this->patientId, 'mine', '2026-04-15 07:45:00');
        $this->repo->create($otherId, 'theirs', '2026-04-15 07:45:00');

        $matches = $this->repo->search($this->patientId);

        $this->assertCount(1, $matches);
        $this->assertSame('mine', $matches[0]['note']);
    }

    public function testSearchRespectsLimitAndOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repo->create(
                $this->patientId,
                'note ' . $i,
                sprintf('2026-04-%02d 07:45:00', 10 + $i),
            );
        }

        $page1 = $this->repo->search($this->patientId, limit: 2, offset: 0);
        $page2 = $this->repo->search($this->patientId, limit: 2, offset: 2);

        $this->assertSame('note 4', $page1[0]['note']);
        $this->assertSame('note 2', $page2[0]['note']);
    }

    public function testCountSearchMatchesFilters(): void
    {
        $this->repo->create($this->patientId, 'early', '2026-04-10 07:45:00');
        $this->repo->create($this->patientId, 'middle', '2026-04-15 07:45:00');
        $this->repo->create($this->patientId, 'late', '2026-04-20 07:45:00');

        $this->assertSame(3, $this->repo->countSearch($this->patientId));
        $this->assertSame(
            1,
            $this->repo->countSearch(
                $this->patientId,
                startDate: '2026-04-13 00:00:00',
                endDate: '2026-04-17 23:59:59',
            ),
        );
        $this->assertSame(
            1,
            $this->repo->countSearch($this->patientId, query: 'earl'),
        );
        $this->assertSame(
            0,
            $this->repo->countSearch($this->patientId, query: 'nomatch'),
        );
    }
}
