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

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use HomeCare\Auth\ApiKeyAuth;
use HomeCare\Auth\Authorization;
use HomeCare\Auth\PasswordHasher;
use HomeCare\Auth\PasswordPolicy;
use HomeCare\Auth\TotpService;
use HomeCare\Config\EmailConfig;
use HomeCare\Config\NtfyConfig;
use HomeCare\Config\WebhookConfig;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\UserRepository;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Minimum seconds between two email-test attempts per admin. Prevents
 * a mistyped SMTP host from piling up 30-second timeouts (and burning
 * audit rows) while the operator iterates on a DSN.
 */
const EMAIL_TEST_COOLDOWN_SECONDS = 60;

$db = new DbiAdapter();
$users = new UserRepository($db);
$ntfyConfig = new NtfyConfig($db);
$emailConfig = new EmailConfig($db);
$webhookConfig = new WebhookConfig($db);
$totpService = new TotpService();
$passwordHasher = new PasswordHasher();
$passwordPolicy = new PasswordPolicy($db);
/** @var string $login */
$login = $GLOBALS['login'];
$currentRole = getCurrentUserRole();
$isAdmin = (new Authorization($currentRole))->canAdmin();

$freshKey = null;
/** @var list<string>|null $freshRecoveryCodes */
$freshRecoveryCodes = null;
$enrollSecret = null;
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
    } elseif ($action === 'begin_totp') {
        $enrollSecret = $totpService->generateSecret();
        $_SESSION['totp_enroll_secret'] = $enrollSecret;
    } elseif ($action === 'cancel_totp') {
        unset($_SESSION['totp_enroll_secret']);
        $flash = ['type' => 'info', 'text' => '2FA enrollment cancelled.'];
    } elseif ($action === 'confirm_totp') {
        $pending = is_string($_SESSION['totp_enroll_secret'] ?? null)
            ? (string) $_SESSION['totp_enroll_secret'] : null;
        $code = (string) getPostValue('totp_code');
        if ($pending === null) {
            $flash = ['type' => 'danger', 'text' => 'Enrollment expired. Please start again.'];
        } elseif (!$totpService->verifyCode($pending, $code)) {
            $flash = ['type' => 'danger', 'text' => 'Code did not verify. Try again — the code rolls every 30 seconds.'];
            $enrollSecret = $pending;
        } else {
            $freshRecoveryCodes = $totpService->generateRecoveryCodes();
            $hashes = array_values(array_map(
                static fn (string $c): string => TotpService::hashRecoveryCode($c),
                $freshRecoveryCodes
            ));
            $users->setTotpSecret($login, $pending);
            $users->enableTotp($login, $hashes);
            unset($_SESSION['totp_enroll_secret']);
            audit_log('totp.enabled', 'user');
            $flash = ['type' => 'success', 'text' => '2FA enabled. Copy your recovery codes now — they are shown only once.'];
        }
    } elseif ($action === 'disable_totp') {
        $currentUser = $users->findByLogin($login);
        $code = (string) getPostValue('totp_code');
        $secret = $currentUser['totp_secret'] ?? null;
        $enabledFlag = $currentUser['totp_enabled'] ?? 'N';
        if ($enabledFlag !== 'Y' || $secret === null) {
            $flash = ['type' => 'info', 'text' => '2FA is already disabled.'];
        } elseif (!$totpService->verifyCode($secret, $code)) {
            $flash = ['type' => 'danger', 'text' => 'Code did not verify. 2FA is still enabled.'];
        } else {
            $users->disableTotp($login);
            audit_log('totp.disabled', 'user');
            $flash = ['type' => 'warning', 'text' => '2FA disabled for your account.'];
        }
    } elseif ($action === 'change_password') {
        $currentUser = $users->findByLogin($login);
        $currentPw = (string) getPostValue('current_password');
        $newPw = (string) getPostValue('new_password');
        $confirmPw = (string) getPostValue('confirm_password');

        if ($currentUser === null
            || !$passwordHasher->verify($currentPw, $currentUser['passwd'])
        ) {
            $flash = ['type' => 'danger', 'text' => 'Current password did not verify.'];
        } elseif ($newPw !== $confirmPw) {
            $flash = ['type' => 'danger', 'text' => 'New password and confirmation do not match.'];
        } else {
            $violations = $passwordPolicy->validate($newPw, [
                'login' => $currentUser['login'],
                // Future: email, firstname, lastname when we read them.
            ]);
            if ($violations !== []) {
                $flash = [
                    'type' => 'danger',
                    'text' => 'Password does not meet policy: ' . implode(' ', $violations),
                ];
            } else {
                $users->updatePasswordHash($login, $passwordHasher->hash($newPw));
                // Invalidate any remember-me cookies so a leaked cookie
                // can't outlive the password change.
                $users->updateRememberToken($login, null, null);
                audit_log('password.changed', 'user');
                $flash = [
                    'type' => 'success',
                    'text' => 'Password updated. Existing "remember me" sessions have been signed out.',
                ];
            }
        }
} elseif ($action === 'save_ntfy' && $isAdmin) {
    $ntfyConfig->setUrl(trim((string) getPostValue('ntfy_url')));
    $ntfyConfig->setTopic(trim((string) getPostValue('ntfy_topic')));
    $ntfyConfig->setEnabled(getPostValue('ntfy_enabled') === 'Y');
    audit_log('ntfy.config_updated', 'config', null, $ntfyConfig->getAll());
    $flash = ['type' => 'success', 'text' => 'Notification settings saved.'];
} elseif ($action === 'save_webhook' && $isAdmin) {
    $webhookConfig->setUrl(trim((string) getPostValue('webhook_url')));
    $webhookConfig->setEnabled(getPostValue('webhook_enabled') === 'Y');
    $timeoutRaw = (string) getPostValue('webhook_timeout_seconds');
    if ($timeoutRaw !== '' && ctype_digit($timeoutRaw)) {
        $webhookConfig->setTimeoutSeconds((int) $timeoutRaw);
    }
    audit_log('webhook.config_updated', 'config', null, $webhookConfig->getAll());
    $flash = ['type' => 'success', 'text' => 'Webhook settings saved.'];
} elseif ($action === 'save_email' && $isAdmin) {
    $emailConfig->setDsn(trim((string) getPostValue('smtp_dsn')));
    $emailConfig->setFromAddress(trim((string) getPostValue('smtp_from_address')));
    $emailConfig->setFromName(trim((string) getPostValue('smtp_from_name')));
    $emailConfig->setEnabled(getPostValue('smtp_enabled') === 'Y');
    // Audit without the DSN so any embedded password isn't logged.
    $meta = $emailConfig->getAll();
    unset($meta['dsn']);
    audit_log('email.config_updated', 'config', null, $meta);
    $flash = ['type' => 'success', 'text' => 'Email settings saved.'];
} elseif ($action === 'test_email' && $isAdmin) {
    // Tests the VALUES CURRENTLY IN THE FORM, not the saved config.
    // Operator can probe an in-progress DSN before committing it.
    $now = time();
    $lastTs = (int) ($_SESSION['email_test_last_ts'] ?? 0);
    $dsn = trim((string) getPostValue('smtp_dsn'));
    $fromAddr = trim((string) getPostValue('smtp_from_address'));
    $fromName = trim((string) getPostValue('smtp_from_name'));
    $to = trim((string) getPostValue('test_to'));

    if ($now - $lastTs < EMAIL_TEST_COOLDOWN_SECONDS) {
        $wait = EMAIL_TEST_COOLDOWN_SECONDS - ($now - $lastTs);
        $flash = [
            'type' => 'warning',
            'text' => "Please wait {$wait}s between tests.",
        ];
    } elseif ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        $flash = ['type' => 'danger', 'text' => 'Enter a valid recipient address first.'];
    } elseif ($dsn === '' || $fromAddr === '') {
        $flash = ['type' => 'danger', 'text' => 'DSN and From address must both be set to run a test.'];
    } else {
        // Flashes: do NOT pre-escape $flash['text']; the alert block
        // already calls htmlspecialchars() on render, and double-escaping
        // turns quotes into &amp;quot; in operator-facing messages.
        $_SESSION['email_test_last_ts'] = $now;
        try {
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);
            $email = (new Email())
                ->from(new Address($fromAddr, $fromName !== '' ? $fromName : 'HomeCare'))
                ->to($to)
                ->subject('[HomeCare] Test email')
                ->text(
                    "This is a test message from HomeCare's settings page.\n\n"
                    . "If you're reading it, outbound email is working.\n"
                    . 'Sent at ' . date('c') . ' by ' . $login . '.'
                );
            $mailer->send($email);
            audit_log('email.test_sent', 'config', null, [
                'to' => $to,
                'ok' => true,
            ]);
            $flash = [
                'type' => 'success',
                'text' => 'Test email sent to ' . $to . '.',
            ];
        } catch (TransportExceptionInterface $e) {
            audit_log('email.test_sent', 'config', null, [
                'to' => $to,
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
            $flash = [
                'type' => 'danger',
                'text' => 'Transport error: ' . $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            audit_log('email.test_sent', 'config', null, [
                'to' => $to,
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
            $flash = [
                'type' => 'danger',
                'text' => 'Test failed: ' . $e->getMessage(),
            ];
        }
    }
} elseif ($action === 'generate_shareable' && $isAdmin) {
    $type = getPostValue('type');
    $ttl = (int) getPostValue('ttl');
    if (!in_array($type, ['csv', 'fhir', 'ics'], true) || $ttl <= 0) {
        $flash = ['type' => 'danger', 'text' => 'Invalid parameters.'];
    } else {
        $signedUrl = \HomeCare\Auth\SignedUrl::instance();
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
            . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/';
        
        $params = [];
        $queryString = '';
        $endpoint = '';
        
        if ($type === 'ics') {
            $scheduleId = (int) getPostValue('schedule_id');
            if ($scheduleId <= 0) {
                $flash = ['type' => 'danger', 'text' => 'Invalid schedule ID.'];
                goto end;
            }
            $params = ['type' => 'ics', 'schedule_id' => $scheduleId];
            $queryString = 'schedule_id=' . $scheduleId;
            $endpoint = 'schedule_ics.php';
        } else {
            $patientId = (int) getPostValue('patient_id');
            $startDate = getPostValue('start_date') ?: date('Y-m-d', strtotime('-30 days'));
            $endDate = getPostValue('end_date') ?: date('Y-m-d');
            if ($patientId <= 0) {
                $flash = ['type' => 'danger', 'text' => 'Invalid patient ID.'];
                goto end;
            }
            $params = [
                'type' => $type,
                'patient_id' => $patientId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ];
            $queryString = 'patient_id=' . $patientId . '&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate);
            $endpoint = 'export_intake_' . $type . '.php';
        }
        
        $token = $signedUrl->sign($params, $ttl);
        $fullUrl = $baseUrl . $endpoint . '?' . $queryString . '&token=' . $token;
        
$days = $ttl / 86400;
$flash = [
    'type' => 'success', 
    'text' => htmlspecialchars("Shareable URL generated (expires in {$days} days).<br><pre class=\"mt-2 bg-light p-2 small\" style=\"word-break: break-all;\">" . htmlspecialchars($fullUrl) . '</pre>')
];
        
        audit_log('signedurl.generated', 'user', null, ['type' => $type, 'ttl' => $ttl, 'target_id' => $patientId ?? $scheduleId]);
    }
    end:
}
}

$user = $users->findByLogin($login);
$hasKey = $user !== null && $user['api_key_hash'] !== null && $user['api_key_hash'] !== '';
$totpEnabled = $user !== null && ($user['totp_enabled'] ?? 'N') === 'Y';

// An in-progress enrollment survives GET reloads via the session.
if ($enrollSecret === null) {
    $stashed = $_SESSION['totp_enroll_secret'] ?? null;
    if (is_string($stashed) && $stashed !== '') {
        $enrollSecret = $stashed;
    }
}

/**
 * Render an SVG QR for an otpauth:// URI at the given edge size (px).
 *
 * Pure-PHP via bacon/bacon-qr-code's SVG backend — no GD dependency,
 * and the output inlines cleanly into the settings page.
 */
$renderQrSvg = static function (string $uri, int $size = 220): string {
    $renderer = new ImageRenderer(
        new RendererStyle($size),
        new SvgImageBackEnd()
    );
    return (new Writer($renderer))->writeString($uri);
};

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
          data-confirm="<?= $hasKey ? 'Replacing the current key will invalidate any clients using it. Continue?' : 'Generate a new API key?' ?>">
    <?= $hasKey ? 'Regenerate API Key' : 'Generate API Key' ?>
  </button>
</form>

<?php if ($hasKey): ?>
  <form method="post" class="d-inline-block">
    <?php print_form_key(); ?>
    <input type="hidden" name="action" value="revoke">
    <button type="submit" class="btn btn-outline-danger"
            data-confirm="Revoke the current API key? Clients will start returning 401 immediately.">
      Revoke
    </button>
  </form>
<?php endif; ?>

<hr class="my-4">
<h4 id="password">Change password</h4>
<p class="text-muted">
  Enter your current password, then a new one. The new password must be
  at least <?= (int) PasswordPolicy::DEFAULT_MIN_LENGTH ?> characters
  and contain a non-alphanumeric character (or be 14+ characters long).
  It can't contain your login name, and can't be a common password.
</p>
<form method="post" class="form" style="max-width: 420px;">
  <?php print_form_key(); ?>
  <input type="hidden" name="action" value="change_password">
  <div class="form-group mb-3">
    <label for="current_password" class="form-label">Current password</label>
    <input type="password" class="form-control" id="current_password"
           name="current_password" autocomplete="current-password" required>
  </div>
  <div class="form-group mb-3">
    <label for="new_password" class="form-label">New password</label>
    <input type="password" class="form-control" id="new_password"
           name="new_password" autocomplete="new-password" required>
  </div>
  <div class="form-group mb-3">
    <label for="confirm_password" class="form-label">Confirm new password</label>
    <input type="password" class="form-control" id="confirm_password"
           name="confirm_password" autocomplete="new-password" required>
  </div>
  <button type="submit" class="btn btn-primary">Change password</button>
</form>

<hr class="my-4">
<h4 id="totp">Two-factor authentication (TOTP)</h4>
<p class="text-muted">
  A time-based one-time code from your authenticator app
  (Google Authenticator, 1Password, Authy, etc.) is required on
  every login in addition to your password. Recovery codes cover
  the lost-phone case.
</p>

<?php if ($freshRecoveryCodes !== null): ?>
  <div class="card border-warning mb-3">
    <div class="card-header bg-warning text-dark"><strong>Your recovery codes</strong></div>
    <div class="card-body">
      <p class="mb-2">
        Save these somewhere safe. Each code works exactly once and
        bypasses the authenticator app. This is the only time they
        will be shown.
      </p>
      <pre class="bg-light p-2 border mb-0" style="column-count:2; font-size: 1.05rem;"><?php
        foreach ($freshRecoveryCodes as $c) {
            echo htmlspecialchars($c) . "\n";
        }
      ?></pre>
    </div>
  </div>
<?php endif; ?>

<div class="mb-3">
  <strong>Status:</strong>
  <?php if ($totpEnabled): ?>
    <span class="badge badge-success">Enabled</span>
  <?php else: ?>
    <span class="badge badge-secondary">Disabled</span>
  <?php endif; ?>
</div>

<?php if ($totpEnabled): ?>
  <form method="post" class="form" style="max-width: 420px;">
    <?php print_form_key(); ?>
    <input type="hidden" name="action" value="disable_totp">
    <div class="form-group mb-3">
      <label for="disable_totp_code" class="form-label">
        Enter current 6-digit code to disable 2FA
      </label>
      <input type="text" class="form-control" id="disable_totp_code"
             name="totp_code" inputmode="numeric" pattern="[0-9]{6}"
             autocomplete="one-time-code" required>
      <small class="form-text text-muted">
        Required so a stolen session can't disable your second factor.
      </small>
    </div>
    <button type="submit" class="btn btn-outline-danger"
            data-confirm="Disable 2FA? You'll lose the second-factor protection on login.">
      Disable 2FA
    </button>
  </form>
<?php elseif ($enrollSecret !== null): ?>
  <?php
    $uri = $totpService->provisioningUri($enrollSecret, $login, 'HomeCare');
    $qr = $renderQrSvg($uri);
  ?>
  <div class="card mb-3" style="max-width: 720px;">
    <div class="card-body">
      <p><strong>1.</strong> Scan this QR in your authenticator app:</p>
      <div class="mb-2"><?= $qr ?></div>
      <p class="small text-muted">Or enter this secret manually:
        <code><?= htmlspecialchars(chunk_split($enrollSecret, 4, ' ')) ?></code>
      </p>
      <p><strong>2.</strong> Then enter the 6-digit code the app shows:</p>
      <form method="post" class="form-inline">
        <?php print_form_key(); ?>
        <input type="hidden" name="action" value="confirm_totp">
        <input type="text" class="form-control mr-2" name="totp_code"
               inputmode="numeric" pattern="[0-9]{6}" required autofocus
               autocomplete="one-time-code" placeholder="000000">
        <button type="submit" class="btn btn-primary">Verify &amp; Enable</button>
        <button type="submit" name="action" value="cancel_totp"
                class="btn btn-link text-muted ml-2">Cancel</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <form method="post" class="d-inline-block">
    <?php print_form_key(); ?>
    <input type="hidden" name="action" value="begin_totp">
    <button type="submit" class="btn btn-primary">Enable 2FA</button>
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

  <hr class="my-4">
  <?php $em = $emailConfig->getAll(); ?>
  <h4 id="email">Email (SMTP) <small class="text-muted">— admin only</small></h4>
  <p class="text-muted">
    Outbound email for reminders (per-user opt-in) and password-reset links
    (HC-091). Uses Symfony Mailer; any valid DSN works — e.g.
    <code>smtp://user:pass@mail.example.com:587</code>,
    <code>sendmail://default</code>, or
    <code>null://default</code> for a dry-run transport that accepts mail
    but delivers nothing.
  </p>
  <form method="post" class="form" id="emailForm">
    <?php print_form_key(); ?>
    <!-- action is written by whichever submit button fires (Save or Test) -->
    <input type="hidden" name="action" id="emailFormAction" value="save_email">
    <div class="form-group mb-3" style="max-width: 620px;">
      <label for="smtp_dsn" class="form-label">DSN</label>
      <input type="text" class="form-control" id="smtp_dsn" name="smtp_dsn"
             value="<?= htmlspecialchars($em['dsn']) ?>"
             data-original="<?= htmlspecialchars($em['dsn']) ?>"
             autocomplete="off"
             placeholder="smtp://user:pass@host:587">
      <small class="form-text text-muted">
        Stored in plain text in <code>hc_config</code>; keep the DB
        file readable only by the app user.
      </small>
    </div>
    <div class="form-group mb-3" style="max-width: 520px;">
      <label for="smtp_from_address" class="form-label">From address</label>
      <input type="email" class="form-control" id="smtp_from_address"
             name="smtp_from_address"
             value="<?= htmlspecialchars($em['from_address']) ?>"
             data-original="<?= htmlspecialchars($em['from_address']) ?>"
             placeholder="no-reply@homecare.local">
    </div>
    <div class="form-group mb-3" style="max-width: 520px;">
      <label for="smtp_from_name" class="form-label">From name</label>
      <input type="text" class="form-control" id="smtp_from_name"
             name="smtp_from_name"
             value="<?= htmlspecialchars($em['from_name']) ?>"
             data-original="<?= htmlspecialchars($em['from_name']) ?>"
             placeholder="HomeCare">
    </div>
    <div class="form-check mb-3">
      <input type="checkbox" class="form-check-input" id="smtp_enabled"
             name="smtp_enabled" value="Y" <?= $em['enabled'] ? 'checked' : '' ?>
             data-original="<?= $em['enabled'] ? 'Y' : '' ?>">
      <label class="form-check-label" for="smtp_enabled">
        Enable email delivery
      </label>
    </div>

    <div class="form-group mb-3" style="max-width: 520px;">
      <label for="test_to" class="form-label">Send test email to</label>
      <input type="email" class="form-control" id="test_to" name="test_to"
             autocomplete="email"
             placeholder="you@example.org">
      <small class="form-text text-muted">
        The test uses the values <em>currently in the form</em> —
        no need to save first.
      </small>
    </div>

    <div class="d-flex align-items-center" style="gap: .5rem;">
      <button type="submit" class="btn btn-primary" data-email-action="save_email">
        Save email settings
      </button>
      <span id="emailUnsavedChip" class="text-warning small" hidden>
        • unsaved changes
      </span>
      <button type="submit" class="btn btn-outline-secondary ml-auto"
              data-email-action="test_email">
        Send test email
      </button>
    </div>
  </form>

  <script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
  (function () {
    // Route whichever submit button fires to the right action without
    // duplicating the form. Matches option 4 behaviour: tests run
    // against form values; saves persist.
    var form = document.getElementById('emailForm');
    var actionField = document.getElementById('emailFormAction');
    if (form && actionField) {
      form.querySelectorAll('[data-email-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          actionField.value = btn.getAttribute('data-email-action');
        });
      });
    }

    // Unsaved-changes chip: compare each data-original to its live value.
    var chip = document.getElementById('emailUnsavedChip');
    if (!form || !chip) return;
    var fields = form.querySelectorAll('[data-original]');
    function refresh() {
      var dirty = false;
      fields.forEach(function (el) {
        var original = el.getAttribute('data-original');
        var live = el.type === 'checkbox' ? (el.checked ? 'Y' : '') : el.value;
        if (live !== original) dirty = true;
      });
      chip.hidden = !dirty;
    }
    fields.forEach(function (el) {
      el.addEventListener('input', refresh);
      el.addEventListener('change', refresh);
    });
    refresh();
  })();
  </script>

  <hr class="my-4">
  <?php $wh = $webhookConfig->getAll(); ?>
  <h4 id="webhook">Webhook <small class="text-muted">— admin only</small></h4>
  <p class="text-muted">
    POSTs a signed JSON envelope to an arbitrary URL every time a
    reminder or supply alert fires. Useful for Home Assistant, Slack,
    Discord, n8n, Zapier, or any HTTP consumer. Each request carries
    <code>X-HomeCare-Signature: sha256=...</code> (HMAC-SHA256 over
    the raw body using the same per-deploy secret as signed URLs)
    so receivers can verify the payload.
  </p>
  <form method="post" class="form">
    <?php print_form_key(); ?>
    <input type="hidden" name="action" value="save_webhook">
    <div class="form-group mb-3" style="max-width: 620px;">
      <label for="webhook_url" class="form-label">Webhook URL</label>
      <input type="url" class="form-control" id="webhook_url" name="webhook_url"
             value="<?= htmlspecialchars($wh['url']) ?>"
             placeholder="https://hooks.example.com/homecare">
    </div>
    <div class="form-group mb-3" style="max-width: 180px;">
      <label for="webhook_timeout_seconds" class="form-label">Timeout (sec)</label>
      <input type="number" class="form-control" id="webhook_timeout_seconds"
             name="webhook_timeout_seconds" min="1" max="60"
             value="<?= (int) $wh['timeout_seconds'] ?>">
      <small class="form-text text-muted">
        Per-request. The channel retries up to 3 times with 1/3/9s
        backoff.
      </small>
    </div>
    <div class="form-check mb-3">
      <input type="checkbox" class="form-check-input" id="webhook_enabled"
             name="webhook_enabled" value="Y" <?= $wh['enabled'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="webhook_enabled">
        Enable webhook dispatch
      </label>
    </div>
    <button type="submit" class="btn btn-primary">Save webhook settings</button>
  </form>

  <hr class="my-4">
  <h4>Shareable Exports <small class="text-muted">— admin only</small></h4>
  <p class="text-muted">
    Generate time-limited signed URLs for sharing intake exports or iCal schedules without sharing credentials.
    These URLs expire after the selected TTL and can be emailed to vets or imported into calendar apps.
  </p>
  <form method="post" class="form">
    <?php print_form_key(); ?>
    <input type="hidden" name="action" value="generate_shareable">
    <div class="row">
      <div class="col-md-2">
        <label for="share_type" class="form-label">Type</label>
        <select class="form-control" id="share_type" name="type" required>
          <option value="">Select</option>
          <option value="csv">CSV Export</option>
          <option value="fhir">FHIR JSON</option>
          <option value="ics">iCal Schedule</option>
        </select>
      </div>
      <div class="col-md-2">
        <label for="share_patient_id" class="form-label">Patient ID (exports)</label>
        <input type="number" class="form-control" id="share_patient_id" name="patient_id" min="1">
      </div>
      <div class="col-md-1 d-none" id="schedule_id_group">
        <label for="share_schedule_id" class="form-label">Schedule ID (iCal)</label>
        <input type="number" class="form-control" id="share_schedule_id" name="schedule_id" min="1">
      </div>
      <div class="col-md-2 d-none" id="start_date_group">
        <label for="share_start" class="form-label">Start Date</label>
        <input type="date" class="form-control" id="share_start" name="start_date" value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
      </div>
      <div class="col-md-2 d-none" id="end_date_group">
        <label for="share_end" class="form-label">End Date</label>
        <input type="date" class="form-control" id="share_end" name="end_date" value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-2">
        <label for="share_ttl" class="form-label">TTL</label>
        <select class="form-control" id="share_ttl" name="ttl" required>
          <option value="86400">1 Day</option>
          <option value="604800">7 Days</option>
          <option value="2592000">30 Days</option>
        </select>
      </div>
      <div class="col-md-1 align-self-end">
        <button type="submit" class="btn btn-primary">Generate</button>
      </div>
    </div>
  </form>
  
  <script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
  const typeSelect = document.getElementById('share_type');
  const patientGroup = document.getElementById('share_patient_id').parentElement;
  const scheduleGroup = document.getElementById('schedule_id_group');
  const dateGroups = document.querySelectorAll('#start_date_group, #end_date_group');
  
  typeSelect.addEventListener('change', function() {
    const val = this.value;
    const isExport = val === 'csv' || val === 'fhir';
    const isIcs = val === 'ics';
    
    patientGroup.style.display = isExport ? 'block' : 'none';
    scheduleGroup.classList.toggle('d-none', !isIcs);
    
    dateGroups.forEach(group => group.classList.toggle('d-none', !isExport));
    
    // Clear values
    if (!isExport) {
      document.querySelector('[name="patient_id"]').value = '';
    }
    if (!isIcs) {
      document.querySelector('[name="schedule_id"]').value = '';
    }
    if (isExport) {
      if (!document.querySelector('[name="start_date"]').value) {
        document.querySelector('[name="start_date"]').value = '<?= date('Y-m-d', strtotime('-30 days')) ?>';
      }
      if (!document.querySelector('[name="end_date"]').value) {
        document.querySelector('[name="end_date"]').value = '<?= date('Y-m-d') ?>';
      }
    }
  });
  </script>
<?php endif; ?>

<script nonce="<?= htmlspecialchars($GLOBALS['NONCE'] ?? '') ?>">
document.addEventListener('click', function(e) {
  var btn = e.target.closest('[data-confirm]');
  if (btn && !confirm(btn.getAttribute('data-confirm'))) {
    e.preventDefault();
  }
});
</script>
<?php echo print_trailer(); ?>
