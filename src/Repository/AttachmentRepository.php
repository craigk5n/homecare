<?php

declare(strict_types=1);

namespace HomeCare\Repository;

use HomeCare\Database\DatabaseInterface;

/**
 * Access to hc_attachments — file upload metadata (HC-130).
 *
 * @phpstan-type Attachment array{
 *     id:int,
 *     owner_type:string,
 *     owner_id:int,
 *     filename:string,
 *     mime_type:string,
 *     size_bytes:int,
 *     sha256:string,
 *     storage_path:string,
 *     uploaded_by:string,
 *     uploaded_at:string
 * }
 * @phpstan-type AttachmentInput array{
 *     owner_type:string,
 *     owner_id:int,
 *     filename:string,
 *     mime_type:string,
 *     size_bytes:int,
 *     sha256:string,
 *     storage_path:string,
 *     uploaded_by:string
 * }
 */
final class AttachmentRepository implements AttachmentRepositoryInterface
{
    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @return Attachment|null
     */
    public function findById(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT id, owner_type, owner_id, filename, mime_type, size_bytes,
                    sha256, storage_path, uploaded_by, uploaded_at
             FROM hc_attachments WHERE id = ?',
            [$id],
        );

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * @return list<Attachment>
     */
    public function findByOwner(string $ownerType, int $ownerId): array
    {
        $rows = $this->db->query(
            'SELECT id, owner_type, owner_id, filename, mime_type, size_bytes,
                    sha256, storage_path, uploaded_by, uploaded_at
             FROM hc_attachments
             WHERE owner_type = ? AND owner_id = ?
             ORDER BY uploaded_at DESC',
            [$ownerType, $ownerId],
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * @param AttachmentInput $data
     */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO hc_attachments
                (owner_type, owner_id, filename, mime_type, size_bytes, sha256, storage_path, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['owner_type'],
                $data['owner_id'],
                $data['filename'],
                $data['mime_type'],
                $data['size_bytes'],
                $data['sha256'],
                $data['storage_path'],
                $data['uploaded_by'],
            ],
        );

        return $this->db->lastInsertId();
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM hc_attachments WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return Attachment
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'owner_type' => (string) $row['owner_type'],
            'owner_id' => (int) $row['owner_id'],
            'filename' => (string) $row['filename'],
            'mime_type' => (string) $row['mime_type'],
            'size_bytes' => (int) $row['size_bytes'],
            'sha256' => (string) $row['sha256'],
            'storage_path' => (string) $row['storage_path'],
            'uploaded_by' => (string) $row['uploaded_by'],
            'uploaded_at' => (string) $row['uploaded_at'],
        ];
    }
}
