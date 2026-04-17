<?php

declare(strict_types=1);

namespace HomeCare\Service;

use HomeCare\Repository\AttachmentRepositoryInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Upload, validate, store, thumbnail, and serve file attachments (HC-130).
 *
 * @phpstan-import-type Attachment from \HomeCare\Repository\AttachmentRepository
 */
final class AttachmentService
{
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/heic',
        'application/pdf',
    ];

    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    private const THUMB_MAX_DIM = 200;

    private const VALID_OWNER_TYPES = ['patient', 'schedule', 'note'];

    public function __construct(
        private readonly AttachmentRepositoryInterface $repo,
        private readonly string $storageRoot,
    ) {}

    /**
     * @param array{name:string, tmp_name:string, size:int, error:int} $uploadedFile $_FILES entry
     *
     * @return Attachment
     */
    public function upload(
        string $ownerType,
        int $ownerId,
        array $uploadedFile,
        string $uploadedBy,
    ): array {
        $this->validateOwnerType($ownerType);

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload failed with error code ' . $uploadedFile['error']);
        }

        if ($uploadedFile['size'] > self::MAX_SIZE_BYTES) {
            throw new InvalidArgumentException('File exceeds 10 MB limit');
        }

        $mime = $this->sniffMime($uploadedFile['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException(
                'File type not allowed: ' . $mime . '. Accepted: JPEG, PNG, HEIC, PDF.',
            );
        }

        $sha256 = hash_file('sha256', $uploadedFile['tmp_name']);
        if ($sha256 === false) {
            throw new RuntimeException('Failed to hash uploaded file');
        }

        $relPath = substr($sha256, 0, 2) . '/' . $sha256;
        $absPath = $this->storageRoot . '/' . $relPath;
        $dir = dirname($absPath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Failed to create storage directory');
        }

        if (!move_uploaded_file($uploadedFile['tmp_name'], $absPath)) {
            throw new RuntimeException('Failed to move uploaded file to storage');
        }

        $id = $this->repo->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'filename' => basename($uploadedFile['name']),
            'mime_type' => $mime,
            'size_bytes' => $uploadedFile['size'],
            'sha256' => $sha256,
            'storage_path' => $relPath,
            'uploaded_by' => $uploadedBy,
        ]);

        $attachment = $this->repo->findById($id);
        if ($attachment === null) {
            throw new RuntimeException('Attachment record not found after insert');
        }

        return $attachment;
    }

    /**
     * Store a file from a raw path (for testing without $_FILES).
     *
     * @return Attachment
     */
    public function storeFromPath(
        string $ownerType,
        int $ownerId,
        string $sourcePath,
        string $originalName,
        string $uploadedBy,
    ): array {
        $this->validateOwnerType($ownerType);

        $size = filesize($sourcePath);
        if ($size === false || $size > self::MAX_SIZE_BYTES) {
            throw new InvalidArgumentException('File exceeds 10 MB limit');
        }

        $mime = $this->sniffMime($sourcePath);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('File type not allowed: ' . $mime);
        }

        $sha256 = hash_file('sha256', $sourcePath);
        if ($sha256 === false) {
            throw new RuntimeException('Failed to hash file');
        }

        $relPath = substr($sha256, 0, 2) . '/' . $sha256;
        $absPath = $this->storageRoot . '/' . $relPath;
        $dir = dirname($absPath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Failed to create storage directory');
        }

        if (!copy($sourcePath, $absPath)) {
            throw new RuntimeException('Failed to copy file to storage');
        }

        $id = $this->repo->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'filename' => basename($originalName),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'storage_path' => $relPath,
            'uploaded_by' => $uploadedBy,
        ]);

        $attachment = $this->repo->findById($id);
        if ($attachment === null) {
            throw new RuntimeException('Attachment record not found after insert');
        }

        return $attachment;
    }

    public function getAbsolutePath(string $storagePath): string
    {
        return $this->storageRoot . '/' . $storagePath;
    }

    /**
     * Get the path to a thumbnail, generating it lazily if needed.
     * Returns null for non-image types.
     */
    public function getThumbnailPath(string $storagePath, string $mimeType): ?string
    {
        if (!str_starts_with($mimeType, 'image/') || $mimeType === 'image/heic') {
            return null;
        }

        $thumbRelPath = 'thumbs/' . $storagePath;
        $thumbAbsPath = $this->storageRoot . '/' . $thumbRelPath;

        if (file_exists($thumbAbsPath)) {
            return $thumbAbsPath;
        }

        $srcAbsPath = $this->storageRoot . '/' . $storagePath;
        if (!file_exists($srcAbsPath)) {
            return null;
        }

        $thumbDir = dirname($thumbAbsPath);
        if (!is_dir($thumbDir) && !mkdir($thumbDir, 0755, true)) {
            return null;
        }

        return $this->generateThumbnail($srcAbsPath, $thumbAbsPath, $mimeType);
    }

    /**
     * Delete an attachment's files and DB record.
     */
    public function deleteAttachment(int $id): bool
    {
        $attachment = $this->repo->findById($id);
        if ($attachment === null) {
            return false;
        }

        $absPath = $this->storageRoot . '/' . $attachment['storage_path'];
        if (file_exists($absPath)) {
            unlink($absPath);
        }

        $thumbPath = $this->storageRoot . '/thumbs/' . $attachment['storage_path'];
        if (file_exists($thumbPath)) {
            unlink($thumbPath);
        }

        return $this->repo->delete($id);
    }

    /**
     * @return list<string>
     */
    public static function allowedMimes(): array
    {
        return self::ALLOWED_MIMES;
    }

    public static function maxSizeBytes(): int
    {
        return self::MAX_SIZE_BYTES;
    }

    /**
     * @return list<string>
     */
    public static function validOwnerTypes(): array
    {
        return self::VALID_OWNER_TYPES;
    }

    private function sniffMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    private function validateOwnerType(string $ownerType): void
    {
        if (!in_array($ownerType, self::VALID_OWNER_TYPES, true)) {
            throw new InvalidArgumentException(
                "Invalid owner_type '$ownerType'. Must be one of: " . implode(', ', self::VALID_OWNER_TYPES),
            );
        }
    }

    private function generateThumbnail(string $srcPath, string $destPath, string $mimeType): ?string
    {
        $src = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($srcPath),
            'image/png' => @imagecreatefrompng($srcPath),
            default => false,
        };

        if ($src === false) {
            return null;
        }

        $origW = imagesx($src);
        $origH = imagesy($src);

        if ($origW <= self::THUMB_MAX_DIM && $origH <= self::THUMB_MAX_DIM) {
            imagedestroy($src);
            copy($srcPath, $destPath);

            return $destPath;
        }

        $ratio = min(self::THUMB_MAX_DIM / $origW, self::THUMB_MAX_DIM / $origH);
        $newW = max(1, (int) round($origW * $ratio));
        $newH = max(1, (int) round($origH * $ratio));

        $thumb = imagecreatetruecolor($newW, $newH);
        if ($thumb === false) {
            imagedestroy($src);

            return null;
        }

        if ($mimeType === 'image/png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        $ok = match ($mimeType) {
            'image/jpeg' => imagejpeg($thumb, $destPath, 85),
            'image/png' => imagepng($thumb, $destPath),
            default => false,
        };

        imagedestroy($src);
        imagedestroy($thumb);

        return $ok ? $destPath : null;
    }
}
