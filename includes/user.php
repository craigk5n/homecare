<?php
/**
 * Authentication functions.
 *
 * This file contains all the functions for getting information about users.
 * So, if you want to use an authentication scheme other than the hc_user
 * table, you can just create a new version of each function found below.
 *
 * <b>Note:</b> this application assumes that usernames (logins) are unique.
 *
 * <b>Note #2:</b> If you are using HTTP-based authentication, then you still
 * need these functions and you will still need to add users to hc_user.
 *
 * @author Craig Knudsen <cknudsen@cknudsen.com>
 * @copyright Craig Knudsen, <cknudsen@cknudsen.com>, http://k5n.us/webcalendar
 * @license https://gnu.org/licenses/old-licenses/gpl-2.0.html GNU GPL
 * @package WebCalendar
 * @subpackage Authentication
 */
defined ( '_ISVALID' ) or die ( 'You cannot access this file directly!' );

require_once 'auth-settings.php';

// Set some global config variables about your system.
$admin_can_disable_user = true;

/**
 * Check to see if a given login/password is valid.
 *
 * If invalid, the error message will be placed in $error.
 *
 * @param string $login    User login
 * @param string $password User password
 * @param bool $#silent  if true do not return any $error
 *
 * @return bool True on success
 *
 * @global string Error message
 */
function user_valid_login ( $login, $password, $silent=false ) {
  global $error;
  $ret = $enabled = false;

  $sql = 'SELECT login, enabled, passwd FROM hc_user WHERE login = ?';
  $res = dbi_execute ( $sql, [$login] );
  if ( $res ) {
    $row = dbi_fetch_row ( $res );
    if ( $row && $row[0] != '' ) {
      // Check the password
      $expected_hash = $row[2];
      if ( strlen ( $expected_hash ) == 32 && ctype_xdigit ( $expected_hash ) ) {
        // Old-Style MD5 password
        $supplied_hash = md5 ( $password );
        $okay = hash_equals ( $supplied_hash, $expected_hash );
        $rehash = true;
      } else {
        // New-Style Secure Password
        $okay = password_verify ( $password, $expected_hash );
        $rehash = password_needs_rehash ( $expected_hash, PASSWORD_DEFAULT );
      }
      // Upgrade insecurely stored passwords
      if ( $okay && $rehash ){
        $new_hash = password_hash ( $password, PASSWORD_DEFAULT );
        $sql = 'UPDATE hc_user SET passwd = ? WHERE login = ?';
        dbi_execute ( $sql, [$new_hash, $login] );
      }
      $enabled = ( $row[1] == 'Y' ? true : false );
      // MySQL seems to do case insensitive matching, so double-check the login.
      if ( $okay && $row[0] == $login )
        $ret = true; // found login/password
      else if ( ! $silent )
        $error = translate ( 'Invalid login', true ) . ': ' .
          translate ( 'incorrect password', true );
    } else if ( ! $silent ) {
      $error = translate ( 'Invalid login', true );
      // Could be no such user or bad password
      // Check if user exists, so we can tell.
      $res2 = dbi_execute ( 'SELECT login FROM hc_user
        WHERE login = ?', [$login] );
      if ( $res2 ) {
        $row = dbi_fetch_row ( $res2 );
        if ( $row && ! empty ( $row[0] ) ) {
          // got a valid username, but wrong password
          $error = translate ( 'Invalid login', true ) . ': ' .
            translate ( 'incorrect password', true );
        } else {
          // No such user.
          $error = translate ( 'Invalid login', true) . ': ' .
            translate ( 'no such user', true );
        }
        dbi_free_result ( $res2 );
      }
    }
    dbi_free_result ( $res );
    if ( ! $enabled && $error == '' ) {
      $ret = false;
      $error = ( ! $silent ? translate('Account disabled', true) : '' );
    }
  } else if ( ! $silent ) {
    $error = db_error();
  }

  return $ret;
}

/**
 * Check to see if a given login/crypted password is valid.
 *
 * If invalid, the error message will be placed in $error.
 *
 * @param string $login          User login
 * @param string $crypt_password Encrypted user password
 *
 * @return bool True on success
 *
 * @global string Error message
 */
function user_valid_crypt ( $login, $crypt_password ) {
  global $error;
  $ret = false;

  $sql = 'SELECT login, passwd FROM hc_user WHERE login = ?';
  $res = dbi_execute ( $sql, [$login] );
  if ( $res ) {
    $row = dbi_fetch_row ( $res );
    if ( $row && $row[0] != '' ) {
      // MySQL seems to do case insensitive matching, so double-check
      // the login.
      // also check if password matches
      if ( ($row[0] == $login) && ( (crypt($row[1], $crypt_password) == $crypt_password) ) )
        $ret = true; // found login/password
      else
        $error = 'Invalid login';
    } else {
      $error = 'Invalid login';
    }
    dbi_free_result ( $res );
  } else {
    $error = 'Database error: ' . dbi_error();
  }

  return $ret;
}

/**
 * Load info about a user (first name, last name, admin) and set globally.
 *
 * @param string $user User login
 * @param string $prefix Variable prefix to use
 *
 * @return bool True on success
 */
function user_load_variables ( $login, $prefix ) {
  global $cached_user_var, $NONUSER_PREFIX, $PUBLIC_ACCESS_FULLNAME, $SCRIPT;

  $ret = false;

  if ( ! empty ( $cached_user_var[$login][$prefix] ) )
    return  $cached_user_var[$login][$prefix];
  $cached_user_var = [];

  //help prevent spoofed username attempts from disclosing fullpath
  $GLOBALS[$prefix . 'fullname'] = '';
  $sql =
    'SELECT firstname, lastname, is_admin, email, passwd, ' .
    'enabled FROM hc_user WHERE login = ?';
  $rows = dbi_get_cached_rows ( $sql, [$login] );
  if ( $rows ) {
    $row = $rows[0];
    $GLOBALS[$prefix . 'login'] = $login;
    $GLOBALS[$prefix . 'firstname'] = $row[0];
    $GLOBALS[$prefix . 'lastname'] = $row[1];
    $GLOBALS[$prefix . 'is_admin'] = $row[2];
    $GLOBALS[$prefix . 'email'] = empty ( $row[3] ) ? '' : $row[3];
    if ( strlen ( $row[0] ) && strlen ( $row[1] ) )
      $GLOBALS[$prefix . 'fullname'] = "$row[0] $row[1]";
    else
      $GLOBALS[$prefix . 'fullname'] = $login;
    $GLOBALS[$prefix . 'password'] = $row[4];
    $GLOBALS[$prefix . 'enabled'] = $row[5];
    $ret = true;
  } else {
    return false;
  }
  //save these results
  $cached_user_var[$login][$prefix] = $ret;
  return $ret;
}

/**
 * Add a new user.
 *
 * @param string $user      User login
 * @param string $password  User password
 * @param string $firstname User first name
 * @param string $lastname  User last name
 * @param string $email     User email address
 * @param string $admin     Is the user an administrator? ('Y' or 'N')
 * @param string $enabled   Is the user account enabled? ('Y' or 'N')
 *
 * @return bool True on success
 *
 * @global string Error message
 */
function user_add_user ( $user, $password, $firstname,
  $lastname, $email, $admin, $enabled='Y' ) {
  global $error;

  if ( $user == '__public__' ) {
    $error = translate ( 'Invalid user login', true);
    return false;
  }

  if ( strlen ( $email ) )
    $uemail = $email;
  else
    $uemail = NULL;
  if ( strlen ( $firstname ) )
    $ufirstname = $firstname;
  else
    $ufirstname = NULL;
  if ( strlen ( $lastname ) )
    $ulastname = $lastname;
  else
    $ulastname = NULL;
  if ( strlen ( $password ) )
    $upassword = password_hash ( $password, PASSWORD_DEFAULT );
  else
    $upassword = NULL;
  if ( $admin != 'Y' )
    $admin = 'N';
  $sql = 'INSERT INTO hc_user ' .
    '( login, lastname, firstname, ' .
    'is_admin, passwd, email, enabled ) ' .
    'VALUES ( ?, ?, ?, ?, ?, ?, ? )';
  if ( ! dbi_execute ( $sql, [$user, $ulastname,
    $ufirstname, $admin, $upassword, $uemail, $enabled] ) ) {
    $error = db_error();
    return false;
  }
  return true;
}

/**
 * Update a user.
 *
 * @param string $user      User login
 * @param string $firstname User first name
 * @param string $lastname  User last name
 * @param string $mail      User email address
 * @param string $admin     Is the user an administrator? ('Y' or 'N')
 * @param string $enabled   Is the user account enabled? ('Y' or 'N')
 *
 * @return bool True on success
 *
 * @global string Error message
 */
function user_update_user ( $user, $firstname, $lastname, $email, $admin, $enabled='Y' ) {
  global $error;

  if ( $user == '__public__' ) {
    $error = translate ( 'Invalid user login' );
    return false;
  }
  if ( strlen ( $email ) )
    $uemail = $email;
  else
    $uemail = NULL;
  if ( strlen ( $firstname ) )
    $ufirstname = $firstname;
  else
    $ufirstname = NULL;
  if ( strlen ( $lastname ) )
    $ulastname = $lastname;
  else
    $ulastname = NULL;
  if ( $admin != 'Y' )
    $admin = 'N';

  if ( $enabled != 'Y' )
    $enabled = 'N';

  $sql = 'UPDATE hc_user SET lastname = ?, ' .
    'firstname = ?, email = ?,' .
    'is_admin = ?, enabled = ? WHERE login = ?';
  if ( ! dbi_execute ( $sql,
    [$ulastname, $ufirstname, $uemail, $admin, $enabled, $user] ) ) {
    $error = db_error();
    return false;
  }
  return true;
}

/**
 * Update user password.
 *
 * @param string $user     User login
 * @param string $password User password
 *
 * @return bool True on success
 *
 * @global string Error message
 */
function user_update_user_password ( $user, $password ) {
  global $error;

  $sql = 'UPDATE hc_user SET passwd = ? WHERE login = ?';
  if ( ! dbi_execute ( $sql, [password_hash ( $password , PASSWORD_DEFAULT ), $user] ) ) {
    $error = db_error();
    return false;
  }
  return true;
}

/**
 * Delete a user from the system.
 *
 * This will also delete any of the user's events in the system that have
 * no other participants. Any layers that point to this user
 * will be deleted. Any views that include this user will be updated.
 *
 * @param string $user User to delete
 */
function user_delete_user ( $user ) {
  // Delete user
  dbi_execute ( 'DELETE FROM hc_user WHERE cal_login = ?',
    [$user] );
}

/**
 * Get a list of users and return info in an array.
 *
 * @param bool  $publicOnly  return only public data
 * @return array Array of user info
 */
function user_get_users ( $publicOnly=false ) {
  global $USER_SORT_ORDER;

  $count = 0;
  $ret = [];

  $order1 = empty ( $USER_SORT_ORDER ) ?
    'lastname, firstname,' : "$USER_SORT_ORDER,";
  $res = dbi_execute ( 'SELECT login, lastname, firstname, ' .
    'is_admin, email, passwd FROM hc_user ' .
    "ORDER BY $order1 login" );
  if ( $res ) {
    while ( $row = dbi_fetch_row ( $res ) ) {
      if ( strlen ( $row[1] ) && strlen ( $row[2] ) )
        $fullname = "$row[2] $row[1]";
      else
        $fullname = $row[0];
      $ret[$count++] = [
        'login' => $row[0],
        'lastname' => $row[1],
        'firstname' => $row[2],
        'is_admin' => $row[3],
        'email' => empty ( $row[4] ) ? '' : $row[4],
        'password' => $row[5],
        'fullname' => $fullname];
    }
    dbi_free_result ( $res );
  }
  // No need to call sort_users() as the SQL can sort for us.
  return $ret;
}

?>
