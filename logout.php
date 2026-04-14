<?php
/**
 * Log out: clear the session + the remember-me cookie + the stored
 * remember-me token hash. Redirects to the login page.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/dbi4php.php';
require_once __DIR__ . '/includes/homecare.php';

use HomeCare\Auth\AuthService;
use HomeCare\Auth\PasswordHasher;
use HomeCare\Database\DbiAdapter;
use HomeCare\Repository\UserRepository;

do_config();

$c = @dbi_connect($db_host, $db_login, $db_password, $db_database);

// Same guard as login.php: avoid double-start notice.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$login = is_string($_SESSION['hc_login'] ?? null) ? (string) $_SESSION['hc_login'] : '';

if ($login !== '' && $c) {
    // $login global set so audit_log picks up the user context.
    $GLOBALS['login'] = $login;
    audit_log('user.logout', 'user');

    (new AuthService(
        new UserRepository(new DbiAdapter()),
        new PasswordHasher()
    ))->logout($login);
}

// Clear session.
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}
session_destroy();

// Clear the remember-me cookie.
setcookie('hc_remember', '', [
    'expires' => time() - 42000,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);

header('Location: login.php');
exit;
