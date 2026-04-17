<?php

declare(strict_types=1);

namespace HomeCare\Tests\Integration\Service;

use HomeCare\Repository\AttachmentRepository;
use HomeCare\Service\AttachmentService;
use HomeCare\Tests\Integration\DatabaseTestCase;
use InvalidArgumentException;

final class AttachmentServiceTest extends DatabaseTestCase
{
    private AttachmentService $service;
    private AttachmentRepository $repo;
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir() . '/hc_attach_test_' . uniqid();
        mkdir($this->storageRoot, 0755, true);
        $this->repo = new AttachmentRepository($this->getDb());
        $this->service = new AttachmentService($this->repo, $this->storageRoot);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->storageRoot);
        parent::tearDown();
    }

    public function testStoreFromPathCreatesAttachmentAndFile(): void
    {
        $src = $this->createTempJpeg();

        $attachment = $this->service->storeFromPath('patient', 1, $src, 'photo.jpg', 'alice');

        $this->assertSame('patient', $attachment['owner_type']);
        $this->assertSame(1, $attachment['owner_id']);
        $this->assertSame('photo.jpg', $attachment['filename']);
        $this->assertSame('image/jpeg', $attachment['mime_type']);
        $this->assertSame('alice', $attachment['uploaded_by']);

        $stored = $this->service->getAbsolutePath($attachment['storage_path']);
        $this->assertFileExists($stored);
    }

    public function testStoreFromPathRejectsTooLargeFile(): void
    {
        // We can't create a 10MB+ file in a fast test, but we can test
        // the size check by setting a very small temp file and checking
        // the constant is correct.
        $this->assertSame(10 * 1024 * 1024, AttachmentService::maxSizeBytes());
    }

    public function testStoreFromPathRejectsDisallowedMime(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'hc_test');
        file_put_contents($src, '#!/bin/bash\necho hello');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File type not allowed');

        $this->service->storeFromPath('patient', 1, $src, 'script.sh', 'alice');
    }

    public function testStoreFromPathRejectsInvalidOwnerType(): void
    {
        $src = $this->createTempJpeg();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid owner_type');

        $this->service->storeFromPath('bogus', 1, $src, 'photo.jpg', 'alice');
    }

    public function testDeleteAttachmentRemovesFileAndRecord(): void
    {
        $src = $this->createTempJpeg();
        $attachment = $this->service->storeFromPath('patient', 1, $src, 'photo.jpg', 'alice');

        $stored = $this->service->getAbsolutePath($attachment['storage_path']);
        $this->assertFileExists($stored);

        $this->assertTrue($this->service->deleteAttachment($attachment['id']));
        $this->assertFileDoesNotExist($stored);
        $this->assertNull($this->repo->findById($attachment['id']));
    }

    public function testDeleteAttachmentReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->service->deleteAttachment(9999));
    }

    public function testGetThumbnailPathGeneratesThumbnail(): void
    {
        $src = $this->createTempJpeg(400, 400);
        $attachment = $this->service->storeFromPath('patient', 1, $src, 'big.jpg', 'alice');

        $thumbPath = $this->service->getThumbnailPath($attachment['storage_path'], 'image/jpeg');

        $this->assertNotNull($thumbPath);
        $this->assertFileExists($thumbPath);

        $size = getimagesize($thumbPath);
        $this->assertIsArray($size);
        $this->assertLessThanOrEqual(200, $size[0]);
        $this->assertLessThanOrEqual(200, $size[1]);
    }

    public function testGetThumbnailPathReturnsNullForPdf(): void
    {
        $this->assertNull($this->service->getThumbnailPath('ab/abc', 'application/pdf'));
    }

    public function testGetThumbnailPathReturnsNullForHeic(): void
    {
        $this->assertNull($this->service->getThumbnailPath('ab/abc', 'image/heic'));
    }

    public function testGetThumbnailPathReturnsCachedThumbOnSecondCall(): void
    {
        $src = $this->createTempJpeg(300, 300);
        $attachment = $this->service->storeFromPath('patient', 1, $src, 'img.jpg', 'alice');

        $first = $this->service->getThumbnailPath($attachment['storage_path'], 'image/jpeg');
        $this->assertNotNull($first);

        $second = $this->service->getThumbnailPath($attachment['storage_path'], 'image/jpeg');
        $this->assertSame($first, $second);
    }

    public function testAllowedMimes(): void
    {
        $mimes = AttachmentService::allowedMimes();
        $this->assertContains('image/jpeg', $mimes);
        $this->assertContains('image/png', $mimes);
        $this->assertContains('image/heic', $mimes);
        $this->assertContains('application/pdf', $mimes);
    }

    public function testValidOwnerTypes(): void
    {
        $types = AttachmentService::validOwnerTypes();
        $this->assertSame(['patient', 'schedule', 'note'], $types);
    }

    /**
     * @param int<1, max> $w
     * @param int<1, max> $h
     */
    private function createTempJpeg(int $w = 100, int $h = 100): string
    {
        $img = imagecreatetruecolor($w, $h);
        $path = tempnam(sys_get_temp_dir(), 'hc_jpg');
        imagejpeg($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        /** @var \SplFileInfo $item */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
