<?php
require_once 'includes/init.php';
require_role('caregiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$ownerType = getPostValue('owner_type');
$ownerId = (int) (getPostValue('owner_id') ?? 0);
$returnUrl = getPostValue('return_url') ?: 'index.php';

if (empty($ownerType) || $ownerId < 1) {
    die_miserable_death('Missing owner_type or owner_id');
}

if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] === UPLOAD_ERR_NO_FILE) {
    die_miserable_death('No file uploaded');
}

require_once __DIR__ . '/vendor/autoload.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\AttachmentRepository;
use HomeCare\Service\AttachmentService;

$db = new DbiAdapter();
$repo = new AttachmentRepository($db);
$service = new AttachmentService($repo, __DIR__ . '/data/attachments');

try {
    $attachment = $service->upload(
        $ownerType,
        $ownerId,
        $_FILES['attachment'],
        $GLOBALS['login'] ?? 'unknown',
    );

    audit_log('attachment.uploaded', 'attachment', $attachment['id'], [
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'filename' => $attachment['filename'],
        'mime_type' => $attachment['mime_type'],
        'size_bytes' => $attachment['size_bytes'],
    ]);

    do_redirect($returnUrl);
} catch (\InvalidArgumentException $e) {
    die_miserable_death(htmlspecialchars($e->getMessage()));
} catch (\RuntimeException $e) {
    die_miserable_death('Upload error: ' . htmlspecialchars($e->getMessage()));
}
