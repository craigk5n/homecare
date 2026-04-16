<?php
/**
 * Native HomeCare login page.
 *
 * Replaces the WebCalendar-derived auth flow. Authenticates against
 * hc_user via password_verify, starts a session, and optionally sets a
 * 365-day "remember me" cookie whose value is the raw token; the DB
 * stores only the SHA-256 hash.
 *
 * Keeps the legacy session-key names ($_SESSION['hc_login']) so the
 * rest of the app and the rewritten validate.php can consume them.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/dbi4php.php';
require_once __DIR__ . '/includes/translate.php';
require_once __DIR__ . '/includes/homecare.php';

use HomeCare\Auth\AuthService;
use HomeCare\Auth\PasswordHasher;
use HomeCare\Auth\TotpService;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\UserRepository;

do_config();

// Establish the mysqli connection (same pattern as includes/connect.php).
$c = @dbi_connect($db_host, $db_login, $db_password, $db_database);
if (!$c) {
    http_response_code(500);
    echo 'Database connection failed.';
    exit;
}

// config.php already calls session_start(); double-starting in PHP 8.2
// emits a notice, which flushes output and locks the response headers
// — that breaks every subsequent header('Location: ...') call.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// If already authenticated, bounce to the app.
if (!empty($_SESSION['hc_login'])) {
    header('Location: index.php');
    exit;
}

$totpService = new TotpService();
$auth = new AuthService(
    new UserRepository(new DbiAdapter()),
    new PasswordHasher(),
    totp: $totpService
);
$error = null;
$prefillLogin = '';

/**
 * Finalise a successful login. Shared by the password path (no 2FA)
 * and the TOTP-verified path.
 */
$completeLogin = static function (
    \HomeCare\Auth\AuthResult $result,
    bool $remember
): void {
    if ($result->user === null) {
        return;
    }

    session_regenerate_id(true);
    $_SESSION['hc_login'] = $result->user['login'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['pending_login'], $_SESSION['pending_remember']);

    $GLOBALS['login'] = $result->user['login'];
    audit_log('user.login', 'user', null, [
        'remember' => $remember,
        'used_recovery_code' => $result->usedRecoveryCode,
    ]);
    if ($result->usedRecoveryCode) {
        audit_log('totp.verified_recovery_code_used', 'user', null, []);
    }

    if ($remember && $result->rememberToken !== null && $result->rememberExpires !== null) {
        setcookie('hc_remember', $result->rememberToken, [
            'expires' => $result->rememberExpires,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $returnPath = is_string($_GET['return_path'] ?? null)
        ? (string) $_GET['return_path']
        : 'index.php';
    if (!preg_match('#^[a-z_]+\.php(\?.*)?$#i', $returnPath)) {
        $returnPath = 'index.php';
    }
    header('Location: ' . $returnPath);
    exit;
};

$pendingLogin = is_string($_SESSION['pending_login'] ?? null)
    ? (string) $_SESSION['pending_login']
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_code']) && $pendingLogin !== null) {
    // TOTP step.
    $code = is_string($_POST['totp_code']) ? (string) $_POST['totp_code'] : '';
    $remember = !empty($_SESSION['pending_remember']);

    $result = $auth->verifyTotp($pendingLogin, $code, $remember);
    if ($result->success) {
        $completeLogin($result, $remember);
    }

    audit_log('totp.verification_failed', 'user', null, [
        'attempted_login' => $pendingLogin,
        'reason' => $result->reason,
    ]);

    $error = match ($result->reason) {
        'account_disabled' => 'This account has been disabled.',
        'account_locked' => 'Account temporarily locked. Please try again in a few minutes.',
        default => 'Invalid code. Try again or use a recovery code.',
    };
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Password step.
    $login = is_string($_POST['login'] ?? null) ? trim((string) $_POST['login']) : '';
    $password = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';
    $remember = !empty($_POST['remember']);
    $prefillLogin = $login;

    $result = $auth->attemptLogin($login, $password, $remember);

    if ($result->success) {
        $completeLogin($result, $remember);
    }

    if ($result->isTotpRequired() && $result->user !== null) {
        // Stash pending login; session is NOT marked authenticated.
        $_SESSION['pending_login'] = $result->user['login'];
        $_SESSION['pending_remember'] = $remember;
        $pendingLogin = $result->user['login'];
        // Fall through to render the code prompt.
    } else {
        audit_log('user.login_failed', 'user', null, [
            'attempted_login' => $login,
            'reason' => $result->reason,
        ]);

        $error = match ($result->reason) {
            'account_disabled' => 'This account has been disabled.',
            'account_locked' => 'Account temporarily locked. Please try again in a few minutes.',
            default => 'Invalid login or password.',
        };
    }
}

// GET requests with a lingering pending_login (e.g. page reload after
// password step) should still show the TOTP form.
$showTotpPrompt = $pendingLogin !== null && !isset($result);
if (isset($result) && $result->isTotpRequired()) {
    $showTotpPrompt = true;
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#2c7a7b">
<title>HomeCare — Sign in</title>
<link rel="icon" href="favicon.ico">
<link rel="stylesheet" href="pub/bootstrap.min.css">
<style>
  :root {
    --hc-primary:      #2c7a7b;
    --hc-primary-dark: #1f5758;
    --hc-accent:       #4fd1c5;
    --hc-ink:          #1a202c;
    --hc-muted:        #4a5568;
    --hc-border:       #e2e8f0;
    --hc-bg-start:     #e6fffa;
    --hc-bg-end:       #ebf4ff;
    --hc-danger-bg:    #fff5f5;
    --hc-danger-fg:    #822727;
    --hc-danger-bd:    #feb2b2;
  }

  *, *::before, *::after { box-sizing: border-box; }

  html, body { height: 100%; }

  body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                 "Helvetica Neue", Arial, sans-serif;
    color: var(--hc-ink);
    background: linear-gradient(135deg, var(--hc-bg-start) 0%, var(--hc-bg-end) 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
  }

  .login-shell {
    width: 100%;
    max-width: 420px;
  }

  .login-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 20px 40px -12px rgba(44, 122, 123, 0.25),
                0 8px 16px -8px rgba(0, 0, 0, 0.08);
    padding: 2.5rem 2rem;
    border: 1px solid rgba(255, 255, 255, 0.6);
  }

  @media (min-width: 480px) {
    .login-card { padding: 2.5rem; }
  }

  .brand {
    text-align: center;
    margin-bottom: 1.75rem;
  }

  .brand-mark {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--hc-primary) 0%, var(--hc-accent) 100%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: .75rem;
    box-shadow: 0 8px 16px -6px rgba(44, 122, 123, 0.5);
  }

  .brand-mark svg {
    width: 30px;
    height: 30px;
    fill: #fff;
  }

  .brand-title {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    letter-spacing: -0.01em;
  }

  .brand-subtitle {
    font-size: .9rem;
    color: var(--hc-muted);
    margin: .25rem 0 0;
  }

  .form-group { margin-bottom: 1.1rem; }

  .form-label {
    display: block;
    font-size: .85rem;
    font-weight: 600;
    color: var(--hc-ink);
    margin-bottom: .4rem;
  }

  .form-control {
    display: block;
    width: 100%;
    padding: .7rem .85rem;
    font-size: 1rem;
    line-height: 1.4;
    color: var(--hc-ink);
    background: #fff;
    border: 1.5px solid var(--hc-border);
    border-radius: 10px;
    transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
    -webkit-appearance: none;
            appearance: none;
  }

  .form-control:hover { border-color: #cbd5e0; }

  .form-control:focus {
    outline: none;
    border-color: var(--hc-primary);
    box-shadow: 0 0 0 4px rgba(44, 122, 123, 0.15);
  }

  .form-control:-webkit-autofill {
    -webkit-box-shadow: 0 0 0 1000px #fff inset;
  }

  .remember {
    display: flex;
    align-items: center;
    gap: .5rem;
    font-size: .9rem;
    color: var(--hc-muted);
    margin: 0 0 1.25rem;
    cursor: pointer;
    user-select: none;
  }

  .remember input {
    width: 1.05rem;
    height: 1.05rem;
    accent-color: var(--hc-primary);
    cursor: pointer;
    margin: 0;
  }

  .btn-signin {
    display: block;
    width: 100%;
    padding: .8rem 1rem;
    font-size: 1rem;
    font-weight: 600;
    color: #fff;
    background: linear-gradient(135deg, var(--hc-primary) 0%, var(--hc-primary-dark) 100%);
    border: 0;
    border-radius: 10px;
    cursor: pointer;
    transition: transform .05s ease, box-shadow .15s ease, filter .15s ease;
    box-shadow: 0 6px 14px -6px rgba(44, 122, 123, 0.55);
  }

  .btn-signin:hover { filter: brightness(1.05); box-shadow: 0 10px 20px -8px rgba(44, 122, 123, 0.6); }
  .btn-signin:active { transform: translateY(1px); }
  .btn-signin:focus-visible {
    outline: none;
    box-shadow: 0 0 0 4px rgba(44, 122, 123, 0.25),
                0 6px 14px -6px rgba(44, 122, 123, 0.55);
  }

  .alert-error {
    display: flex;
    gap: .6rem;
    align-items: flex-start;
    background: var(--hc-danger-bg);
    color: var(--hc-danger-fg);
    border: 1px solid var(--hc-danger-bd);
    padding: .65rem .85rem;
    border-radius: 10px;
    font-size: .9rem;
    margin-bottom: 1.1rem;
  }
  .alert-error svg { flex: 0 0 auto; width: 18px; height: 18px; margin-top: 1px; }

  .footnote {
    margin-top: 1.25rem;
    text-align: center;
    font-size: .8rem;
    color: var(--hc-muted);
  }

  .sr-only {
    position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
    overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
  }

  @media (prefers-reduced-motion: reduce) {
    .form-control, .btn-signin { transition: none; }
  }
</style>
</head>
<body>
<main class="login-shell">
  <div class="login-card" role="region" aria-labelledby="login-title">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true">
        <!-- Heart + pulse mark -->
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">
          <path d="M12 21s-7.5-4.6-9.6-9.2C.7 8.1 2.6 4 6.4 4c2 0 3.6 1.1 4.6 2.7h2C14 5.1 15.6 4 17.6 4c3.8 0 5.7 4.1 4 7.8C19.5 16.4 12 21 12 21z" opacity=".95"/>
          <path d="M5 13h3l1.5-3 2.5 6 1.5-3H19" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </span>
      <h1 id="login-title" class="brand-title">HomeCare</h1>
      <p class="brand-subtitle">Sign in to continue</p>
    </div>

    <?php if ($error !== null): ?>
      <div class="alert-error" role="alert" aria-live="assertive">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path fill="currentColor" d="M12 2 1 21h22L12 2zm0 6 7.5 13h-15L12 8zm-1 4v4h2v-4h-2zm0 5v2h2v-2h-2z"/>
        </svg>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($showTotpPrompt): ?>
      <form method="post" autocomplete="off" novalidate aria-describedby="login-title">
        <p class="brand-subtitle" style="margin-bottom:1rem;">
          Enter the 6-digit code from your authenticator app, or use one of your recovery codes.
        </p>

        <div class="form-group">
          <label for="totp_code" class="form-label">Authentication code</label>
          <input type="text"
                 class="form-control"
                 id="totp_code"
                 name="totp_code"
                 inputmode="numeric"
                 autocomplete="one-time-code"
                 pattern="[0-9a-zA-Z\-]{6,16}"
                 required
                 autofocus>
        </div>

        <button type="submit" class="btn-signin">Verify</button>
      </form>
    <?php else: ?>
      <form method="post" autocomplete="on" novalidate aria-describedby="login-title">
        <div class="form-group">
          <label for="login" class="form-label">Username</label>
          <input type="text"
                 class="form-control"
                 id="login"
                 name="login"
                 value="<?= htmlspecialchars($prefillLogin) ?>"
                 autocomplete="username"
                 autocapitalize="none"
                 autocorrect="off"
                 spellcheck="false"
                 required
                 autofocus>
        </div>

        <div class="form-group">
          <label for="password" class="form-label">Password</label>
          <input type="password"
                 class="form-control"
                 id="password"
                 name="password"
                 autocomplete="current-password"
                 required>
        </div>

        <label class="remember" for="remember">
          <input type="checkbox" id="remember" name="remember" value="1">
          <span>Remember me for 365 days</span>
        </label>

        <button type="submit" class="btn-signin">Sign in</button>
      </form>
      <p class="footnote"><a href="forgot_password.php">Forgot your password?</a></p>
    <?php endif; ?>
  </div>

  <p class="footnote">HomeCare &middot; Secure medication tracking</p>
</main>
</body>
</html>
