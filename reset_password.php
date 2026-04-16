<?php
/**
 * HC-091: password-reset completion page.
 *
 * GET ?token=...   — validates the token, renders the new-password form
 *                    (or an error if the token is already invalid).
 * POST             — applies PasswordPolicy to the submitted password,
 *                    calls PasswordResetService::complete() which
 *                    consumes the token and rotates the hash.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/dbi4php.php';
require_once __DIR__ . '/includes/translate.php';
require_once __DIR__ . '/includes/homecare.php';

use HomeCare\Auth\PasswordHasher;
use HomeCare\Auth\PasswordPolicy;
use HomeCare\Auth\PasswordResetService;
use HomeCare\Config\EmailConfig;
use HomeCare\Database\DbiAdapter;
use HomeCare\Notification\EmailChannel;
use HomeCare\Repository\UserRepository;

do_config();

$c = @dbi_connect($db_host, $db_login, $db_password, $db_database);
if (!$c) {
    http_response_code(500);
    echo 'Database connection failed.';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$db = new DbiAdapter();
$service = new PasswordResetService(
    db:           $db,
    users:        new UserRepository($db),
    hasher:       new PasswordHasher(),
    emailChannel: new EmailChannel(new EmailConfig($db)),
    audit:        static function (string $action, string $details = ''): void {
        audit_log($action, 'user', null, ['details' => $details]);
    },
);
$policy = new PasswordPolicy($db);

$token = is_string($_GET['token'] ?? null) ? (string) $_GET['token'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && is_string($_POST['token'] ?? null)
) {
    $token = (string) $_POST['token'];
}

$login = $service->validate($token);
$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $login !== null) {
    $newPw = is_string($_POST['new_password'] ?? null) ? (string) $_POST['new_password'] : '';
    $confirmPw = is_string($_POST['confirm_password'] ?? null) ? (string) $_POST['confirm_password'] : '';

    if ($newPw !== $confirmPw) {
        $error = 'Passwords do not match.';
    } else {
        $violations = $policy->validate($newPw, ['login' => $login]);
        if ($violations !== []) {
            $error = implode(' ', $violations);
        } elseif ($service->complete($token, $newPw)) {
            $success = true;
        } else {
            $error = 'Reset failed. Please request a new link.';
        }
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HomeCare — Choose a new password</title>
<link rel="stylesheet" href="pub/bootstrap.min.css">
<style>
  body { max-width: 460px; margin: 4rem auto; padding: 0 1.5rem; font-family: system-ui, sans-serif; }
  .card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 2rem; box-shadow: 0 8px 16px -8px rgba(0,0,0,.08); }
  h1 { font-size: 1.35rem; margin: 0 0 .75rem; }
  .muted { color: #4a5568; font-size: .95rem; }
  .err { background: #fff5f5; color: #822727; border: 1px solid #feb2b2; padding: .6rem .8rem; border-radius: 8px; margin-bottom: 1rem; font-size: .9rem; }
  .ok { background: #f0fff4; color: #22543d; border: 1px solid #9ae6b4; padding: .6rem .8rem; border-radius: 8px; margin-bottom: 1rem; font-size: .95rem; }
  label { font-weight: 600; font-size: .9rem; margin-top: .75rem; display: block; }
  input[type=password] { width: 100%; padding: .6rem .75rem; border: 1.5px solid #e2e8f0; border-radius: 8px; margin-top: .25rem; }
  .btn { display: inline-block; width: 100%; padding: .7rem; margin-top: 1rem; background: #2c7a7b; color: #fff; border: 0; border-radius: 8px; cursor: pointer; font-weight: 600; }
  .btn:hover { filter: brightness(1.05); }
  a.back { display: block; margin-top: 1rem; text-align: center; font-size: .9rem; color: #4a5568; }
</style>
</head>
<body>
<div class="card">
  <h1>Choose a new password</h1>

  <?php if ($success): ?>
    <div class="ok">
      <strong>Password updated.</strong> You can now sign in with your new password.
    </div>
    <a class="back" href="login.php">Go to sign in</a>

  <?php elseif ($login === null): ?>
    <div class="err">
      This reset link is no longer valid. It may have expired or
      already been used.
    </div>
    <p class="muted">Request a new one from the forgot-password page.</p>
    <a class="back" href="forgot_password.php">Forgot password</a>

  <?php else: ?>
    <?php if ($error !== null): ?>
      <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <p class="muted">
      Resetting the password will also sign out any "remember me"
      sessions for this account.
    </p>
    <form method="post" autocomplete="off" novalidate>
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <label for="new_password">New password</label>
      <input type="password" id="new_password" name="new_password"
             autocomplete="new-password" required autofocus>
      <label for="confirm_password">Confirm new password</label>
      <input type="password" id="confirm_password" name="confirm_password"
             autocomplete="new-password" required>
      <button type="submit" class="btn">Set new password</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
