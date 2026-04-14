<?php
/**
 * Native HomeCare session validation.
 *
 * Replaces the WebCalendar-derived auth flow that used to live here. The
 * previous file defined `initValidate()`/`initConnect()`/`initializeDb
 * Connection()` helpers that were never actually invoked by init.php --
 * so the site ran without enforced auth. This version does the
 * following at the end of include, via `hc_validate()`:
 *
 *   1. Ensure the mysqli connection ($c) is open.
 *   2. Short-circuit for CLI, login.php, logout.php, and schedule_ics.php
 *      (public calendar feed).
 *   3. Start the session and resolve $login from:
 *        a. $_SESSION['hc_login'], if set; else
 *        b. the `hc_remember` cookie via AuthService.
 *   4. Enforce the HC-012 idle timeout from hc_config.session_timeout.
 *   5. Populate the legacy globals ($login, $is_admin, $fullname,
 *      $firstname, $lastname, $user_email) that existing pages read.
 *   6. Redirect to login.php when nothing validates.
 *
 * Callers should include this file from init.php AFTER do_config() has
 * populated the $db_* globals, then invoke `hc_validate()` once.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HomeCare\Auth\AuthService;
use HomeCare\Auth\PasswordHasher;
use HomeCare\Auth\SessionState;
use HomeCare\Auth\SessionTimeout;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\UserRepository;

/**
 * Legacy sanity check kept so the DB-table-missing error path survives.
 */
function doDbSanityCheck(): void
{
    global $db_database, $db_host, $db_login;
    $dieMsg = 'Error finding tables in database "' . $db_database
        . '" using db login "' . $db_login . '" on db server "' . $db_host . '".<br><br>'
        . 'Have you created the database tables as specified in the install guide';

    $res = @dbi_execute('SELECT COUNT(value) FROM hc_config', [], false, false);
    if (!$res) {
        die_miserable_death($dieMsg);
    }
    if (!dbi_fetch_row($res)) {
        dbi_free_result($res);
        die_miserable_death($dieMsg);
    }
    dbi_free_result($res);
}

/**
 * Clear the remember-me cookie on the client.
 */
function hc_clear_remember_cookie(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    setcookie('hc_remember', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Run authentication for a standard authenticated request.
 *
 * Pages that must be public (CLI scripts, login.php, public feeds) are
 * exempt; everything else either establishes a session here or gets
 * redirected to login.php.
 */
function hc_validate(): void
{
    global $c, $db_host, $db_login, $db_password, $db_database,
           $login, $is_admin, $fullname, $firstname, $lastname, $user_email;

    $script = basename((string) ($_SERVER['PHP_SELF'] ?? ''));

    // Public / exempt entrypoints.
    if (PHP_SAPI === 'cli' || in_array(
        $script,
        ['login.php', 'logout.php', 'schedule_ics.php', 'css_cacher.php'],
        true
    )) {
        return;
    }

    // Ensure DB is ready for session lookups.
    if (empty($c)) {
        $c = @dbi_connect($db_host, $db_login, $db_password, $db_database);
        if (!$c) {
            die_miserable_death('Error connecting to database:<blockquote>'
                . dbi_error() . '</blockquote>');
        }
        doDbSanityCheck();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $login = !empty($_SESSION['hc_login']) ? (string) $_SESSION['hc_login'] : '';

    // Try remember-me cookie if the session is empty.
    if ($login === '' && !empty($_COOKIE['hc_remember'])) {
        $authService = new AuthService(
            new UserRepository(new DbiAdapter()),
            new PasswordHasher()
        );
        $result = $authService->loginWithRememberToken((string) $_COOKIE['hc_remember']);
        if ($result->success && $result->user !== null) {
            session_regenerate_id(true);
            $_SESSION['hc_login'] = $result->user['login'];
            $_SESSION['last_activity'] = time();
            $login = $result->user['login'];
        } else {
            hc_clear_remember_cookie();
        }
    }

    if ($login === '') {
        hc_redirect_to_login($script);
    }

    // HC-012: idle-timeout enforcement.
    $timeoutMins = SessionTimeout::DEFAULT_TIMEOUT_MINUTES;
    $cfg = dbi_get_cached_rows(
        "SELECT value FROM hc_config WHERE setting = 'session_timeout'"
    );
    if (!empty($cfg[0][0]) && (int) $cfg[0][0] > 0) {
        $timeoutMins = (int) $cfg[0][0];
    }
    $timeout = new SessionTimeout($timeoutMins);
    $lastActivity = isset($_SESSION['last_activity'])
        ? (int) $_SESSION['last_activity']
        : null;

    if ($timeout->evaluate($lastActivity, time()) === SessionState::Expired) {
        $_SESSION = [];
        @session_destroy();
        hc_clear_remember_cookie();
        header('Location: login.php?expired=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Populate the legacy globals every page reads.
    $rows = dbi_get_cached_rows(
        'SELECT login, firstname, lastname, email, is_admin, enabled
         FROM hc_user WHERE login = ?',
        [$login]
    );
    if (empty($rows[0])) {
        // Session references a deleted / renamed user; force re-auth.
        $_SESSION = [];
        @session_destroy();
        hc_clear_remember_cookie();
        header('Location: login.php');
        exit;
    }

    $row = $rows[0];
    if (($row[5] ?? '') !== 'Y') {
        $_SESSION = [];
        @session_destroy();
        hc_clear_remember_cookie();
        die_miserable_death('This account has been disabled.');
    }

    $firstname = (string) ($row[1] ?? '');
    $lastname = (string) ($row[2] ?? '');
    $fullname = trim($firstname . ' ' . $lastname);
    if ($fullname === '') {
        $fullname = $login;
    }
    $user_email = (string) ($row[3] ?? '');
    $is_admin = (($row[4] ?? '') === 'Y');
}

function hc_redirect_to_login(string $currentScript): void
{
    $target = 'login.php';
    if ($currentScript !== '' && $currentScript !== 'index.php') {
        $target .= '?return_path=' . urlencode($currentScript);
    }
    header('Location: ' . $target);
    exit;
}
