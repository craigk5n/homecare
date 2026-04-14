<?php
/**
 * HC-030: Per-user settings page. Currently houses API-key management.
 *
 * Future HC-xx tickets will add password change, notification preferences,
 * etc. under this same page.
 *
 * Any authenticated user can manage their own account here; admin-only
 * system settings (ntfy config, etc.) belong on a separate admin page
 * when those land.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_role('caregiver');

use HomeCare\Auth\ApiKeyAuth;
use HomeCare\Auth\Authorization;
use HomeCare\Config\NtfyConfig;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\UserRepository;

$db = new DbiAdapter();
$users = new UserRepository($db);
$ntfyConfig = new NtfyConfig($db);
/** @var string $login */
$login = $GLOBALS['login'];
$currentRole = getCurrentUserRole();
$isAdmin = (new Authorization($currentRole))->canAdmin();

$freshKey = null;
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = getPostValue('action');
    if ($action === 'generate') {
        $freshKey = ApiKeyAuth::generateRawKey();
        $users->updateApiKeyHash($login, ApiKeyAuth::hashKey($freshKey));
        audit_log('apikey.generated', 'user');
        $flash = ['type' => 'success', 'text' => 'New API key generated. Copy it now -- it will not be shown again.'];
    } elseif ($action === 'revoke') {
        $users->updateApiKeyHash($login, null);
        audit_log('apikey.revoked', 'user');
        $flash = ['type' => 'warning', 'text' => 'API key revoked. Any clients using it will start returning 401 immediately.'];
    } elseif ($action === 'save_ntfy' && $isAdmin) {
        $ntfyConfig->setUrl(trim((string) getPostValue('ntfy_url')));
        $ntfyConfig->setTopic(trim((string) getPostValue('ntfy_topic')));
        $ntfyConfig->setEnabled(getPostValue('ntfy_enabled') === 'Y');
        audit_log('ntfy.config_updated', 'config', null, $ntfyConfig->getAll());
        $flash = ['type' => 'success', 'text' => 'Notification settings saved.'];
    }
}

$user = $users->findByLogin($login);
$hasKey = $user !== null && $user['api_key_hash'] !== null && $user['api_key_hash'] !== '';

print_header();
?>
<h2>Settings</h2>

<?php if ($flash !== null): ?>
  <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['text']) ?></div>
<?php endif; ?>

<h4 class="mt-4">API Key</h4>
<p class="text-muted">
  Bearer tokens for programmatic API access. Generate a key, copy it
  once, and send it in the <code>Authorization: Bearer &lt;key&gt;</code>
  header on API requests. The key is stored hashed -- we cannot recover
  it; if you lose it, generate a new one.
</p>

<?php if ($freshKey !== null): ?>
  <div class="card border-warning mb-3">
    <div class="card-header bg-warning text-dark"><strong>Your new API key</strong></div>
    <div class="card-body">
      <p class="mb-2">Copy this now. It is the only time you will ever see it.</p>
      <pre class="bg-light p-2 border mb-0" style="word-break: break-all; white-space: pre-wrap;"><?= htmlspecialchars($freshKey) ?></pre>
    </div>
  </div>
<?php endif; ?>

<div class="mb-3">
  <strong>Status:</strong>
  <?php if ($hasKey): ?>
    <span class="badge badge-success">Active</span>
    (hashed; plain value not stored)
  <?php else: ?>
    <span class="badge badge-secondary">None</span>
  <?php endif; ?>
</div>

<form method="post" class="d-inline-block mr-2">
  <?php print_form_key(); ?>
  <input type="hidden" name="action" value="generate">
  <button type="submit" class="btn btn-primary"
          onclick="return confirm('<?= $hasKey ? 'Replacing the current key will invalidate any clients using it. Continue?' : 'Generate a new API key?' ?>');">
    <?= $hasKey ? 'Regenerate API Key' : 'Generate API Key' ?>
  </button>
</form>

<?php if ($hasKey): ?>
  <form method="post" class="d-inline-block">
    <?php print_form_key(); ?>
    <input type="hidden" name="action" value="revoke">
    <button type="submit" class="btn btn-outline-danger"
            onclick="return confirm('Revoke the current API key? Clients will start returning 401 immediately.');">
      Revoke
    </button>
  </form>
<?php endif; ?>

<?php if ($isAdmin):
    $nt = $ntfyConfig->getAll();
?>
  <hr class="my-4">
  <h4 id="notifications">Notifications (ntfy) <small class="text-muted">— admin only</small></h4>
  <p class="text-muted">
    Push notifications for medication reminders and low-supply alerts.
    When disabled, <code>send_reminders.php</code> runs normally but
    short-circuits before pushing to ntfy. Leave the topic blank to
    effectively disable even when enabled.
  </p>
  <form method="post" class="form">
    <?php print_form_key(); ?>
    <input type="hidden" name="action" value="save_ntfy">
    <div class="form-group mb-3" style="max-width: 520px;">
      <label for="ntfy_url" class="form-label">Server URL</label>
      <input type="url" class="form-control" id="ntfy_url" name="ntfy_url"
             value="<?= htmlspecialchars($nt['url']) ?>"
             placeholder="https://ntfy.sh/">
    </div>
    <div class="form-group mb-3" style="max-width: 520px;">
      <label for="ntfy_topic" class="form-label">Topic / channel</label>
      <input type="text" class="form-control" id="ntfy_topic" name="ntfy_topic"
             value="<?= htmlspecialchars($nt['topic']) ?>"
             placeholder="e.g. homecare-craig">
    </div>
    <div class="form-check mb-3">
      <input type="checkbox" class="form-check-input" id="ntfy_enabled"
             name="ntfy_enabled" value="Y" <?= $nt['enabled'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="ntfy_enabled">
        Enable push notifications
      </label>
    </div>
    <button type="submit" class="btn btn-primary">Save notification settings</button>
  </form>
<?php endif; ?>

<?php echo print_trailer(); ?>
