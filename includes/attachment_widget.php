<?php
/**
 * Reusable attachment widget: upload form + file list (HC-130).
 *
 * Include this from any page that needs attachments. Set the variables
 * before including:
 *   $att_owner_type  — 'patient', 'schedule', or 'note'
 *   $att_owner_id    — the FK value
 *   $att_return_url  — where to redirect after upload/delete
 *
 * Requires: vendor/autoload.php already loaded, DB connection open.
 */

if (!defined('_ISVALID')) {
    die('No direct access');
}

if (empty($att_owner_type) || empty($att_owner_id) || empty($att_return_url)) {
    return;
}

use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\AttachmentRepository;

$attDb = new DbiAdapter();
$attRepo = new AttachmentRepository($attDb);
$attachments = $attRepo->findByOwner($att_owner_type, (int) $att_owner_id);

$canUpload = function_exists('require_role') && in_array(
    $GLOBALS['login_role'] ?? 'viewer',
    ['caregiver', 'admin'],
    true
);
// Fallback: check $GLOBALS['login'] exists (any logged-in user can upload)
if (!$canUpload && !empty($GLOBALS['login'])) {
    $canUpload = true;
}

$currentUser = $GLOBALS['login'] ?? '';
$isAdmin = !empty($GLOBALS['is_admin']) && $GLOBALS['is_admin'] === 'Y';
?>

<div class="card mb-3" id="attachments-section">
<div class="card-header d-flex justify-content-between align-items-center">
    <strong>Attachments</strong>
    <span class="badge badge-secondary"><?= count($attachments) ?></span>
</div>
<div class="card-body p-2">

<?php if ($attachments !== []): ?>
<div class="list-group list-group-flush mb-2">
<?php foreach ($attachments as $att): ?>
    <div class="list-group-item d-flex justify-content-between align-items-center px-2 py-1">
        <div class="d-flex align-items-center">
            <?php if (str_starts_with($att['mime_type'], 'image/')): ?>
                <a href="attachment.php?id=<?= $att['id'] ?>" target="_blank">
                    <img src="attachment.php?id=<?= $att['id'] ?>&thumb=1"
                         alt="<?= htmlspecialchars($att['filename']) ?>"
                         style="max-width:48px;max-height:48px;object-fit:cover"
                         class="mr-2 rounded">
                </a>
            <?php else: ?>
                <span class="mr-2" style="font-size:1.5rem">📄</span>
            <?php endif; ?>
            <div>
                <a href="attachment.php?id=<?= $att['id'] ?>" target="_blank"
                   class="text-truncate d-block" style="max-width:200px">
                    <?= htmlspecialchars($att['filename']) ?>
                </a>
                <small class="text-muted">
                    <?= htmlspecialchars(number_format($att['size_bytes'] / 1024, 0)) ?> KB
                    &middot; <?= htmlspecialchars($att['uploaded_by']) ?>
                    &middot; <?= htmlspecialchars(date('M j, Y', strtotime($att['uploaded_at']))) ?>
                </small>
            </div>
        </div>
        <?php if ($att['uploaded_by'] === $currentUser || $isAdmin): ?>
        <form method="POST" action="attachment_delete_handler.php" class="d-inline"
              onsubmit="return confirm('Delete this attachment?');">
            <?php print_form_key(); ?>
            <input type="hidden" name="attachment_id" value="<?= $att['id'] ?>">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($att_return_url) ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">&times;</button>
        </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($canUpload): ?>
<form method="POST" action="attachment_upload_handler.php" enctype="multipart/form-data"
      id="attachment-upload-form">
    <?php print_form_key(); ?>
    <input type="hidden" name="owner_type" value="<?= htmlspecialchars($att_owner_type) ?>">
    <input type="hidden" name="owner_id" value="<?= (int) $att_owner_id ?>">
    <input type="hidden" name="return_url" value="<?= htmlspecialchars($att_return_url) ?>">
    <div class="custom-file mb-2">
        <input type="file" class="custom-file-input" id="attachment-file" name="attachment"
               accept="image/jpeg,image/png,image/heic,application/pdf">
        <label class="custom-file-label" for="attachment-file">Choose file or drop here</label>
    </div>
    <small class="form-text text-muted mb-2 d-block">JPEG, PNG, HEIC, or PDF — max 10 MB</small>
    <button type="submit" class="btn btn-sm btn-outline-primary" id="upload-btn" disabled>Upload</button>
</form>
<script>
(function() {
    var fileInput = document.getElementById('attachment-file');
    var uploadBtn = document.getElementById('upload-btn');
    var label = fileInput ? fileInput.nextElementSibling : null;
    if (fileInput && uploadBtn) {
        fileInput.addEventListener('change', function() {
            if (fileInput.files.length > 0) {
                if (label) label.textContent = fileInput.files[0].name;
                uploadBtn.disabled = false;
            } else {
                if (label) label.textContent = 'Choose file or drop here';
                uploadBtn.disabled = true;
            }
        });
    }

    // Drag-and-drop onto the card
    var card = document.getElementById('attachments-section');
    if (card && fileInput) {
        card.addEventListener('dragover', function(e) {
            e.preventDefault();
            card.classList.add('border-primary');
        });
        card.addEventListener('dragleave', function() {
            card.classList.remove('border-primary');
        });
        card.addEventListener('drop', function(e) {
            e.preventDefault();
            card.classList.remove('border-primary');
            if (e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                fileInput.dispatchEvent(new Event('change'));
            }
        });
    }
})();
</script>
<?php endif; ?>

</div>
</div>
