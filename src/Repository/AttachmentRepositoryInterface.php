<?php

declare(strict_types=1);

namespace HomeCare\Repository;

/**
 * Read/write contract for hc_attachments (HC-130).
 *
 * @phpstan-import-type Attachment from AttachmentRepository
 * @phpstan-import-type AttachmentInput from AttachmentRepository
 */
interface AttachmentRepositoryInterface
{
    /**
     * @return Attachment|null
     */
    public function findById(int $id): ?array;

    /**
     * @return list<Attachment>
     */
    public function findByOwner(string $ownerType, int $ownerId): array;

    /**
     * @param AttachmentInput $data
     */
    public function create(array $data): int;

    public function delete(int $id): bool;
}
