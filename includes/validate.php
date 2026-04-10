<?php
/**
 * Do a sanity check. Make sure we can access hc_config table. We call this
 * right after the first call to dbi_connect()
 * (from either the WebCalendar class or here in validate.php).
 */
function doDbSanityCheck() {
  global $db_database, $db_host, $db_login;
  $dieMsgStr = 'Error finding tables in database "' . $db_database
   . '" using db login "' . $db_login . '" on db server "' . $db_host
   . '".<br><br>
Have you created the database tables as specified in the install guide';
  $res = @dbi_execute ( 'SELECT COUNT( value ) FROM hc_config',
    [], false, false );
  if ( $res ) {
    if ( $row = dbi_fetch_row ( $res ) )
      // Found database. All is peachy.
      dbi_free_result ( $res );
    else {
      // Error accessing table.
      // User has wrong db name or has not created tables.
      // Note: can't translate this since translate.php is not included yet.
      dbi_free_result ( $res );
      die_miserable_death ( $dieMsgStr );
    }
  } else
    die_miserable_death ( $dieMsgStr );
}

function initializeDbConnection () {
    global $c, $cryptpw, $db_database, $db_host, $db_login, $db_password,
    $encoded_login, $HTTP_ENV_VARS, $HTTP_SERVER_VARS, $ignore_user_case,
    $is_nonuser, $login, $login_return_path, $PHP_AUTH_USER, $REMOTE_USER,
    $session_not_found, $settings, $single_user, $single_user_login,
    $user_inc, $use_http_auth, $validate_redirect, $hc_session;

    $is_nonuser = $session_not_found = $validate_redirect = false;

    // Catch-all for getting the username when using HTTP-authentication.
    if ( $use_http_auth ) {
      if ( empty ( $PHP_AUTH_USER ) ) {
        if ( ! empty ( $_SERVER ) && isset ( $_SERVER['PHP_AUTH_USER'] ) )
          $PHP_AUTH_USER = $_SERVER['PHP_AUTH_USER'];
        else
        if ( ! empty ( $HTTP_SERVER_VARS ) &&
            isset ( $HTTP_SERVER_VARS['PHP_AUTH_USER'] ) )
          $PHP_AUTH_USER = $HTTP_SERVER_VARS['PHP_AUTH_USER'];
        else
        if ( isset ( $REMOTE_USER ) )
          $PHP_AUTH_USER = $REMOTE_USER;
        else
        if ( ! empty ( $_ENV ) && isset ( $_ENV['REMOTE_USER'] ) )
          $PHP_AUTH_USER = $_ENV['REMOTE_USER'];
        else
        if ( ! empty ( $HTTP_ENV_VARS ) && isset ( $HTTP_ENV_VARS['REMOTE_USER'] ) )
          $PHP_AUTH_USER = $HTTP_ENV_VARS['REMOTE_USER'];
        else
        if ( @getenv ( 'REMOTE_USER' ) )
          $PHP_AUTH_USER = getenv ( 'REMOTE_USER' );
        else
        if ( isset ( $AUTH_USER ) )
          $PHP_AUTH_USER = $AUTH_USER;
        else
        if ( ! empty ( $_ENV ) && isset ( $_ENV['AUTH_USER'] ) )
          $PHP_AUTH_USER = $_ENV['AUTH_USER'];
        else
        if ( ! empty ( $HTTP_ENV_VARS ) && isset ( $HTTP_ENV_VARS['AUTH_USER'] ) )
          $PHP_AUTH_USER = $HTTP_ENV_VARS['AUTH_USER'];
        else
        if ( @getenv ( 'AUTH_USER' ) )
          $PHP_AUTH_USER = getenv ( 'AUTH_USER' );
      }
    }

    if ( $single_user == 'Y' )
      $login = $single_user_login;
    else {
      if ( $use_http_auth ) {
        // HTTP server did validation for us....
        if ( empty ( $PHP_AUTH_USER ) )
          $session_not_found = true;
        else
          $login = $PHP_AUTH_USER;
      } else
      if ( substr ( $user_inc, 0, 9 ) == 'user-app-' ) {
        // Make sure we are connected to the database for session check.
        $c = @dbi_connect ( $db_host, $db_login, $db_password, $db_database );
        if ( ! $c )
          die_miserable_death ( 'Error connecting to database:<blockquote>'
             . dbi_error() . '</blockquote>' );
      } else {
        @session_start();
        if ( ! empty ( $_SESSION['hc_login'] ) )
          $login = $_SESSION['hc_login'];

        if ( ! empty ( $_SESSION['hc_session'] ) )
          $hc_session = $_SESSION['hc_session'];

        if ( empty ( $login ) && empty ( $hc_session ) )
          $session_not_found = true;
        else
        if ( empty ( $_SESSION['hc_login'] ) &&
            // Check for cookie...
            ! empty ( $hc_session ) ) {
          $encoded_login = $hc_session;
          if ( empty ( $encoded_login ) ) {
            // Invalid session cookie.
            $session_not_found = true;
          } else {
            $cooie_check = explode('|', decode_string($encoded_login));
            // First time after switching to PHP8 you may have
            // incompatible cookies here.
            if ( empty($cooie_check[0]) || empty($cooie_check[1]))
              $session_not_found = true;
          }
          if (! $session_not_found) {
            $login_pw = explode('|', decode_string($encoded_login));
            $login = $login_pw[0];
            $cryptpw = $login_pw[1];

            // Security fix. Don't allow certain types of characters in
            // the login. HC does not escape the login name in
            // SQL requests. So, if the user were able to set the login
            // name to be "x';drop table u;",
            // they may be able to affect the database.
            if ( ! empty ( $login ) && $login != addslashes ( $login ) ) {
              // The following deletes the bad cookie.
              // So, the user just needs to reload.
              sendCookie ( 'hc_session', '', 0 );
              die_miserable_death ( 'Illegal characters in login <tt>'
                 . htmlentities ( $login )
                 . '</tt>. Press browser reload to clear bad cookie.' );
            }

            // Make sure we are connected to the database for password check.
            $c = @dbi_connect ( $db_host, $db_login, $db_password, $db_database );
            if ( ! $c )
              die_miserable_death ( 'Error connecting to database:<blockquote>'
                 . dbi_error() . '</blockquote>' );

            doDbSanityCheck();
            if ( ! user_valid_crypt ( $login, $cryptpw ) )
              do_redirect ( 'login.php' . ( empty ( $login_return_path )
                  ? '' : '?return_path=' . $login_return_path ) );

            @session_start();
            $_SESSION['hc_login'] = $login;
            $_SESSION['hc_session'] = $hc_session;
          }
        }
      }
    }
  }
  /**
   * Initializations from includes/connect.php.
   *
   * @access private
   */
  function initConnect() {
    global $c, $db_database, $db_host, $db_login, $db_password, $firstname,
    $fullname, $is_admin, $LANGUAGE, $lastname, $login,
    $login_email, $login_firstname, $login_fullname, $login_is_admin,
    $login_lastname, $login_login, $login_url, $not_auth, $PHP_AUTH_USER,
    $PHP_SELF, $PROGRAM_VERSION, $pub_acc_enabled,
    $readonly, $session_not_found, $single_user,
    $single_user_login, $use_http_auth, $user_email, $user_inc;

    // db settings are in config.php.

    // Establish a database connection.
    // This may have happened in validate.php, depending on settings.
    // If not, do it now.
    if ( empty ( $c ) ) {
      $c = dbi_connect ( $db_host, $db_login, $db_password, $db_database );
      if ( ! $c )
        die_miserable_death ( 'Error connecting to database:<blockquote>'
           . dbi_error() . '</blockquote>' );

      // Do a sanity check on the database,
      // making sure we can at least access the hc_config table.
      if ( function_exists ( 'doDbSanityCheck' ) )
        doDbSanityCheck();

      // Check the current installation version.
      // Redirect user to install page if it is different from stored value.
      // This will prevent running WebCalendar until UPGRADING.html has been
      // read and required upgrade actions completed.
      $rows = dbi_get_cached_rows ( 'SELECT value FROM hc_config
         WHERE setting = \'HC_PROGRAM_VERSION\'' );
      if ( $rows ) {
              $row = $rows[0];
        if ( $row[0] != $PROGRAM_VERSION ) {
          // &amp; does not work here...leave it as &
          header ( 'Location: install/index.php?action=mismatch&version='
                      . $row[0] );
        exit;}

      }
    }

    // If we are in single user mode,
    // make sure that the login selected is a valid login.
    if ( $single_user == 'Y' ) {
      if ( empty ( $single_user_login ) )
        die_miserable_death ( 'You have not defined <tt>single_user_login</tt> '
           . 'in <tt>includes/settings.php</tt>.' );

      $res = dbi_execute ( 'SELECT COUNT( * ) FROM hc_user
  WHERE login = ?', [$single_user_login] );
      if ( ! $res ) {
        echo 'Database error: ' . dbi_error();
        exit;
      }
      $row = dbi_fetch_row ( $res );
      if ( $row[0] == 0 ) {
        // User specified as single_user_login does not exist.
        if ( ! dbi_execute ( 'INSERT INTO hc_user ( login, passwd,
          is_admin ) VALUES ( ?, ?, ? )',
          [$single_user_login, md5 ( $single_user_login ), 'Y'] ) )
          die_miserable_death ( 'User <tt>' . $single_user_login
             . '</tt> does not exist in <tt>hc_user</tt> table and we were '
             . 'not able to add it for you:<br><blockquote>' . dbi_error()
             . '</blockquote>' );

        // User was added... should we tell them?
      }
      dbi_free_result ( $res );
    }

    if ( empty ( $PHP_SELF ) )
      $PHP_SELF = $_SERVER['PHP_SELF'];

    if ( empty ( $login_url ) )
      $login_url = 'login.php';

    $login_url .= ( strstr ( $login_url, '?' ) ? '&amp;' : '?' )
     . ( empty ( $login_return_path ) ? '' : 'return_path='
         . $login_return_path );

    // If sent here from an email and not logged in,
        //save URI and redirect to login.
    $em = getGetValue ( 'em' );
        $view_via_email = false;
    if ( ! empty ( $em ) && empty ( $login ) ) {
      remember_this_view();
      $view_via_email = true;
    }

    if ( empty ( $session_not_found ) )
      $session_not_found = false;

    if ( ! $view_via_email && $pub_acc_enabled && ! empty ( $session_not_found ) ) {
      $firstname = $lastname = $user_email = '';
      $fullname = 'Public Access'; // Will be translated after translation is loaded.
      $is_admin = false;
      $login = '__public__';
    } else
    if ( $view_via_email || ( ! $pub_acc_enabled && $session_not_found
          && ! $use_http_auth ) ) {
      do_redirect ( $login_url );
    }

    if ( empty ( $login ) && $use_http_auth ) {
      if ( strstr ( $PHP_SELF, "login.php" ) ) {
        // Ignore since login.php will redirect to index.php.
      } else
        send_http_login();
    } else
    if ( ! empty ( $login ) ) {
      // They are already logged in ($login is set in validate.php).
      if ( strstr ( $PHP_SELF, 'login.php' ) ) {
        // Ignore since login.php will redirect to index.php.
      } else
      if ( $login == '__public__' ) {
        $firstname = $lastname = $user_email = '';
        $fullname = 'Public Access';
        $is_admin = false;
      } else {
        user_load_variables ( $login, 'login_' );
        if ( ! empty ( $login_login ) ) {
          $firstname = $login_firstname;
          $lastname = $login_lastname;
          $fullname = $login_fullname;
          $is_admin = ( $login_is_admin == 'Y' );
          $user_email = $login_email;
        } else {
          // Invalid login.
          if ( $use_http_auth ) {
            if ( $pub_acc_enabled ) {
              $login = '__public__';
              $firstname = $lastname = $user_email = '';
              $fullname = 'Public Access';
              $is_admin = false;
            } else
              send_http_login();
          } else
            // This shouldn't happen since login should be validated in validate.php.
            // If it does happen, it means we received an invalid login cookie.
            do_redirect ( $login_url . '&amp;error=Invalid+session+found.' );
        }
      }
    }

    if ( empty ( $is_admin ) || ! $is_admin ) {
      //if ( strstr ( $PHP_SELF, 'activity_log.php' ) ||
      if ( strstr ( $PHP_SELF, 'admin.php' ) ||
        strstr ( $PHP_SELF, 'users.php' ) ) {
        $not_auth = true;
      }
    }

    // Restrict access if is read-only.
    if ( $readonly == 'Y' ) {
      if ( strstr ( $PHP_SELF, 'activity_log.php' ) ||
        strstr ( $PHP_SELF, 'users.php' ) ) {
        // TODO... add these
        $not_auth = true;
    }

    // An attempt will be made to translate
    if ( $not_auth ) {
      //load_user_preferences();
      $error = ( function_exists ( 'translate' )
        ? translate ( 'You are not authorized.' ) : 'You are not authorized.' );
      die_miserable_death ( $error );
    }
  }
}

?>
