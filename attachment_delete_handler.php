<?php
require_once 'includes/init.php';
require_role('caregiver');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$attachmentId = (int) (getPostValue('attachment_id') ?? 0);
$returnUrl = getPostValue('return_url') ?: 'index.php';

if ($attachmentId < 1) {
    die_miserable_death('Missing attachment_id');
}

require_once __DIR__ . '/vendor/autoload.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\AttachmentRepository;
use HomeCare\Service\AttachmentService;

$db = new DbiAdapter();
$repo = new AttachmentRepository($db);
$service = new AttachmentService($repo, __DIR__ . '/data/attachments');

$attachment = $repo->findById($attachmentId);
if ($attachment === null) {
    die_miserable_death('Attachment not found');
}

$isOwner = ($attachment['uploaded_by'] === ($GLOBALS['login'] ?? ''));
$isAdmin = !empty($GLOBALS['is_admin']) && $GLOBALS['is_admin'] === 'Y';

if (!$isOwner && !$isAdmin) {
    die_miserable_death('Only the uploader or an admin can delete this attachment');
}

$service->deleteAttachment($attachmentId);

audit_log('attachment.deleted', 'attachment', $attachmentId, [
    'owner_type' => $attachment['owner_type'],
    'owner_id' => $attachment['owner_id'],
    'filename' => $attachment['filename'],
]);

do_redirect($returnUrl);
