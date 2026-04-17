<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Repository;

use HomeCare\Repository\AttachmentRepository;
use HomeCare\Tests\Integration\DatabaseTestCase;

final class AttachmentRepositoryTest extends DatabaseTestCase
{
    private AttachmentRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new AttachmentRepository($this->getDb());
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repo->create([
            'owner_type' => 'patient',
            'owner_id' => 1,
            'filename' => 'prescription.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 12345,
            'sha256' => str_repeat('a', 64),
            'storage_path' => 'aa/' . str_repeat('a', 64),
            'uploaded_by' => 'alice',
        ]);

        $this->assertGreaterThan(0, $id);

        $row = $this->repo->findById($id);
        $this->assertNotNull($row);
        $this->assertSame('patient', $row['owner_type']);
        $this->assertSame(1, $row['owner_id']);
        $this->assertSame('prescription.jpg', $row['filename']);
        $this->assertSame('image/jpeg', $row['mime_type']);
        $this->assertSame(12345, $row['size_bytes']);
        $this->assertSame('alice', $row['uploaded_by']);
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->findById(9999));
    }

    public function testFindByOwner(): void
    {
        $this->repo->create($this->makeInput('patient', 1, 'a.jpg'));
        $this->repo->create($this->makeInput('patient', 1, 'b.pdf'));
        $this->repo->create($this->makeInput('patient', 2, 'c.jpg'));
        $this->repo->create($this->makeInput('schedule', 1, 'd.jpg'));

        $results = $this->repo->findByOwner('patient', 1);

        $this->assertCount(2, $results);
        $names = array_map(fn(array $r) => $r['filename'], $results);
        $this->assertContains('a.jpg', $names);
        $this->assertContains('b.pdf', $names);
    }

    public function testFindByOwnerReturnsEmptyForNoMatch(): void
    {
        $this->assertSame([], $this->repo->findByOwner('patient', 999));
    }

    public function testDelete(): void
    {
        $id = $this->repo->create($this->makeInput('patient', 1, 'to-delete.jpg'));
        $this->assertNotNull($this->repo->findById($id));

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->findById($id));
    }

    /**
     * @return array{owner_type:string, owner_id:int, filename:string, mime_type:string, size_bytes:int, sha256:string, storage_path:string, uploaded_by:string}
     */
    private function makeInput(string $ownerType, int $ownerId, string $filename): array
    {
        $sha = hash('sha256', $filename . random_int(0, 999999));

        return [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'filename' => $filename,
            'mime_type' => str_ends_with($filename, '.pdf') ? 'application/pdf' : 'image/jpeg',
            'size_bytes' => 1024,
            'sha256' => $sha,
            'storage_path' => substr($sha, 0, 2) . '/' . $sha,
            'uploaded_by' => 'testuser',
        ];
    }
}
