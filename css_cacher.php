<?php
define( '_ISVALID', true );

require_once 'includes/translate.php';
require_once 'includes/config.php';
require_once 'includes/dbi4php.php';
require_once 'includes/formvars.php';
require_once 'includes/functions.php';

do_config();
// HC-073 removed $user_inc / includes/user.php; auth is native. That
// removal also lost the DB-connect side-effect the old user.php
// pulled in, so we open the connection explicitly before loading
// global settings (validate.php short-circuits for css_cacher.php).
require_once 'includes/validate.php';

global $c, $db_host, $db_login, $db_password, $db_database;
if (empty($c)) {
  $c = @dbi_connect($db_host, $db_login, $db_password, $db_database);
  if (!$c) {
    http_response_code(500);
    exit;
  }
}

load_global_settings();
@session_start();
$empTmp = ( ! empty( $_SESSION['webcal_tmp_login'] ) );

// If calling script uses 'guest', we must also.
$GLOBALS['user'] = ! empty( $_GET['login'] )
  ? $_GET['login']
  : ( ! empty( $_REQUEST['login'] )
    ? $_REQUEST['login']
    : ( $empTmp
      ? $_SESSION['webcal_tmp_login']
      : ( empty( $_SESSION['webcal_login'] )
        ? '__public__'
        : $_SESSION['webcal_login'] ) ) );
// TODO: load_user_preferences( $GLOBALS['user'] );

unset( $_SESSION['webcal_tmp_login'] );

// If we are calling from admin or pref, expire CSS yesterday.
// Otherwise, expire tomorrow.
$expTime = time() + 86400;

if( $empTmp )
  $expTime = time() - 86400;

header( 'Content-type: text/css' );
header( 'Cache-Control: Public' );
header( 'Pragma: Public' );

send_no_cache_header();

require_once 'includes/css/styles.php';

?>
