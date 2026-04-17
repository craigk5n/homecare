<?php
require_once 'includes/init.php';

$id = (int) (getIntValue('id') ?? 0);
$thumb = !empty(getGetValue('thumb'));

if ($id < 1) {
    http_response_code(400);
    exit('Missing id');
}

require_once __DIR__ . '/vendor/autoload.php';

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\AttachmentRepository;
use HomeCare\Service\AttachmentService;

$db = new DbiAdapter();
$repo = new AttachmentRepository($db);
$service = new AttachmentService($repo, __DIR__ . '/data/attachments');

$attachment = $repo->findById($id);
if ($attachment === null) {
    http_response_code(404);
    exit('Attachment not found');
}

if ($thumb) {
    $path = $service->getThumbnailPath($attachment['storage_path'], $attachment['mime_type']);
    if ($path === null) {
        http_response_code(404);
        exit('No thumbnail available');
    }
} else {
    $path = $service->getAbsolutePath($attachment['storage_path']);
}

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found on disk');
}

header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . addcslashes($attachment['filename'], '"\\') . '"');
header('Cache-Control: private, max-age=86400');
readfile($path);
exit;
