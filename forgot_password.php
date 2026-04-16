<?php
/**
 * HC-091: forgot-password entry page.
 *
 * Accepts a login or email; always renders the same "check your
 * email" confirmation regardless of whether the account existed
 * or the mail actually fired, so account existence never leaks
 * through the response.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/dbi4php.php';
require_once __DIR__ . '/includes/translate.php';
require_once __DIR__ . '/includes/homecare.php';

use HomeCare\Auth\PasswordHasher;
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

$showConfirm = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginOrEmail = is_string($_POST['login_or_email'] ?? null)
        ? trim((string) $_POST['login_or_email'])
        : '';

    if ($loginOrEmail !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

        $service->initiate($loginOrEmail, $baseUrl);
    }
    // Render the confirmation screen regardless — no user enumeration.
    $showConfirm = true;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HomeCare — Forgot password</title>
<link rel="stylesheet" href="pub/bootstrap.min.css">
<style>
  body { max-width: 460px; margin: 4rem auto; padding: 0 1.5rem; font-family: system-ui, sans-serif; }
  .card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 2rem; box-shadow: 0 8px 16px -8px rgba(0,0,0,.08); }
  h1 { font-size: 1.35rem; margin: 0 0 .5rem; }
  p.muted { color: #4a5568; font-size: .95rem; }
  label { font-weight: 600; font-size: .9rem; margin-top: .75rem; display: block; }
  input[type=text] { width: 100%; padding: .6rem .75rem; border: 1.5px solid #e2e8f0; border-radius: 8px; margin-top: .25rem; }
  .btn { display: inline-block; width: 100%; padding: .7rem; margin-top: 1rem; background: #2c7a7b; color: #fff; border: 0; border-radius: 8px; cursor: pointer; font-weight: 600; }
  .btn:hover { filter: brightness(1.05); }
  .back { display: block; margin-top: 1rem; text-align: center; font-size: .9rem; color: #4a5568; }
</style>
</head>
<body>
<div class="card">
  <h1>Reset your password</h1>
  <?php if ($showConfirm): ?>
    <p class="muted">
      If an account matches what you entered, a password-reset link is
      on its way. The link is good for
      <?= (int) HomeCare\Auth\PasswordResetService::TTL_MINUTES ?> minutes.
    </p>
    <p class="muted">Check your spam folder if you don't see it soon.</p>
  <?php else: ?>
    <p class="muted">
      Enter your login or the email address on your account. We'll
      email a single-use reset link.
    </p>
    <form method="post" autocomplete="off" novalidate>
      <label for="login_or_email">Login or email</label>
      <input type="text" id="login_or_email" name="login_or_email"
             autocomplete="username" autocapitalize="none"
             autocorrect="off" spellcheck="false" required autofocus>
      <button type="submit" class="btn">Send reset link</button>
    </form>
  <?php endif; ?>
  <a class="back" href="login.php">Back to sign in</a>
</div>
</body>
</html>
