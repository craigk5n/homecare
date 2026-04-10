<?php

/**
 * Extracts a user's name from a session id.
 *
 * This prevents users from begin able to edit their cookies.txt file and set
 * the username in plain text.
 *
 * @param string $instr  A hex-encoded string. "Hello" would be "678ea786a5".
 *
 * @return string  The decoded string.
 *
 * @global array Array of offsets
 *
 * @see encode_string
 */
function decode_string($instr)
{
  global $offsets;

  $cntOffsets = count($offsets);
  $orig = '';
  for ($i = 0, $cnt = strlen($instr); $i < $cnt; $i += 2) {
    $orig .= chr(
      (hextoint(substr($instr, $i, 1)) * 16 +
        hextoint(substr($instr, $i + 1, 1)) - $offsets[($i / 2) % $cntOffsets] + 256) % 256
    );
  }
  return $orig;
}

/**
 * Takes an input string and encode it into a slightly encoded hexval that we
 * can use as a session cookie.
 *
 * @param string $instr  Text to encode
 *
 * @return string  The encoded text.
 *
 * @global array Array of offsets
 *
 * @see decode_string
 */
function encode_string($instr)
{
  global $offsets;

  $cntOffsets = count($offsets);
  $ret = '';
  for ($i = 0, $cnt = strlen($instr); $i < $cnt; $i++) {
    $ret .= bin2hex(chr((ord(substr($instr, $i, 1)) + $offsets[$i %
      $cntOffsets]) % 256));
  }
  return $ret;
}

function sendCookie($name, $value, $expiration = 0, $path = '', $sensitive = true)
{
  $domain = '';
  $httpOnly = true; // don't allow JS access to cookies.
  // If sensitive and HTTPS is supported, set secure to true
  $secure = $sensitive && isSecure();
  SetCookie($name, $value, $expiration, $path, $domain, $secure, $httpOnly);
}

/**
 * Converts a hexadecimal digit to an integer.
 *
 * @param string $val Hexadecimal digit
 *
 * @return int Equivalent integer in base-10
 *
 * @ignore
 */
function hextoint($val)
{
  if (empty($val))
    return 0;

  switch (strtoupper($val)) {
    case '0':
      return 0;
    case '1':
      return 1;
    case '2':
      return 2;
    case '3':
      return 3;
    case '4':
      return 4;
    case '5':
      return 5;
    case '6':
      return 6;
    case '7':
      return 7;
    case '8':
      return 8;
    case '9':
      return 9;
    case 'A':
      return 10;
    case 'B':
      return 11;
    case 'C':
      return 12;
    case 'D':
      return 13;
    case 'E':
      return 14;
    case 'F':
      return 15;
  }
  return 0;
}

/**
 * Is the current connection using HTTPS rather than HTTP?
 */
function isSecure()
{
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || $_SERVER['SERVER_PORT'] == 443;
}


/**
 * Sends a redirect to the specified page.
 * The database connection is closed and execution terminates in this function.
 *
 * <b>Note:</b>  MS IIS/PWS has a bug that does not allow sending a cookie and a
 * redirect in the same HTTP header. When we detect that the web server is IIS,
 * we accomplish the redirect using meta-refresh.
 * [bad link]
 * See the following for more info on the IIS bug:
 * {@link http://faqts.com/knowledge_base/view.phtml/aid/9316/fid/4}
 *
 * @param string $url  The page to redirect to. In theory, this should be an
 *                     absolute URL, but all browsers accept relative URLs
 *                     (like "month.php").
 *
 * @global string    Type of webserver
 * @global array     Server variables
 * @global resource  Database connection
 */
function do_redirect($url)
{
  global $_SERVER, $c, $SERVER_SOFTWARE, $SERVER_URL;

  // Replace any '&amp;' with '&' since we don't want that in the HTTP header.
  $url = str_replace('&amp;', '&', $url);

  if (empty($SERVER_SOFTWARE))
    $SERVER_SOFTWARE = $_SERVER['SERVER_SOFTWARE'];

  // As of RFC 7231, Location redirects can be relative URLs.
  // See: https://tools.ietf.org/html/rfc7231#section-7.1.2

  $meta = '';
  if ((substr($SERVER_SOFTWARE, 0, 5) == 'Micro') ||
    (substr($SERVER_SOFTWARE, 0, 3) == 'WN/')
  )
    $meta = '
    <meta http-equiv="refresh" content="0; url=' . $url . '">';
  else
    header('Location: ' . $url);

  echo send_doctype('Redirect') . $meta . '
  </head>
  <body>
    Redirecting to.. <a href="' . $url . '">here</a>.
  </body>
</html>';
  dbi_close($c);
  exit;
}


/**
 * Generates a cookie that saves the last calendar view.
 *
 * Cookie is based on the current <var>$REQUEST_URI</var>.
 *
 * We save this cookie so we can return to this same page after a user
 * edits/deletes/etc an event.
 *
 * @param bool $view  Determine if we are using a view_x.php file
 *
 * @global string  Request string
 */
function remember_this_view($view = false)
{
  global $REQUEST_URI;
  if (empty($REQUEST_URI))
    $REQUEST_URI = $_SERVER['REQUEST_URI'];

  // If called from init, only process script named "view_x.php.
  if ($view == true && !strstr($REQUEST_URI, 'view_'))
    return;

  // Do not use anything with "friendly" in the URI.
  if (strstr($REQUEST_URI, 'friendly='))
    return;

  sendCookie('webcalendar_last_view', $REQUEST_URI);
}

/**
 * This just sends the DOCTYPE used in a lot of places in the code.
 *
 * @param string  lang
 */
function send_doctype($doc_title = '')
{
  global $charset, $lang, $LANGUAGE;

  $lang = (empty($LANGUAGE) ? '' : languageToAbbrev($LANGUAGE));
  if (empty($lang))
    $lang = 'en';

  $charset = (empty($LANGUAGE) ? 'iso-8859-1' : translate('charset'));

  return "<!DOCTYPE html>
  <html lang=\"$lang\">
    <head>
      <meta charset=\"$charset\">"
    . (empty($doc_title) ? '' : "
      <title>$doc_title</title>");
}

/**
 * Sends an HTTP login request to the browser and stops execution.
 *
 * @global string  name of language file
 * @global string  Application Name
 *
 */
function send_http_login()
{
  global $lang_file;

  if (strlen($lang_file)) {
    $not_authorized = print_not_auth();
    $title = translate('Title');
    $unauthorized = translate('Unauthorized');
  } else {
    $not_authorized = 'You are not authorized';
    $title = 'WebCalendar';
    $unauthorized = 'Unauthorized';
  }
  header('WWW-Authenticate: Basic realm="' . "$title\"");
  header('HTTP/1.0 401 Unauthorized');
  echo send_doctype($unauthorized) . '
  </head>
  <body>
    <h2>' . $title . '</h2>
    ' . $not_authorized . '
  </body>
</html>';
  exit;
}

/**
 * Sends HTTP headers that tell the browser not to cache this page.
 *
 * Different browsers use different mechanisms for this,
 * so a series of HTTP header directives are sent.
 *
 * <b>Note:</b>  This function needs to be called before any HTML output is sent
 *               to the browser.
 */
function send_no_cache_header()
{
  header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
  header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Cache-Control: post-check=0, pre-check=0', false);
  header('Pragma: no-cache');
}

/**
 * Generate standardized Not Authorized message
 *
 * @param bool $full  Include ERROR title
 *
 * @return string  HTML to display notice.
 *
 * @uses print_error_header
 */
function print_not_auth($full = false)
{
  $ret = ($full ? print_error_header() : '')
    . '!!!' . translate('You are not authorized.') . "\n";
  return $ret;
}


/**
 * Generate standardized error message
 *
 * @param string $error  Message to display
 * @param bool   $full   Include extra text in display
 *
 * @return string  HTML to display error.
 *
 * @uses print_error_header
 */
function print_error ( $error, $full = false ) {
  return print_error_header()
   . ( $full ? translate ( 'The following error occurred' ) . ':' : '' ) . '
    <blockquote>' . $error . '</blockquote>';
}

/**
 * An h2 header error message.
 */
function print_error_header() {
  return '
    <h2>' . translate ( 'Error' ) . '</h2>';
}

/**
 * Generate Application Name
 *
 * @param bool $custom  Allow user name to be displayed
 */
function generate_application_name ( $custom = true ) {
  global $APPLICATION_NAME, $fullname;

  if ( empty ( $APPLICATION_NAME ) )
    $APPLICATION_NAME = 'Title';

  return ( $custom && ! empty ( $fullname ) && $APPLICATION_NAME == 'myname'
    ? $fullname
    : ( $APPLICATION_NAME == 'Title' || $APPLICATION_NAME == 'myname'
      ? ( function_exists ( 'translate' ) ? translate ( 'Title' ) : 'Title' )
      : htmlspecialchars ( $APPLICATION_NAME ) ) );
}

/**
 * Loads default system settings (which can be updated via admin.php).
 *
 * System settings are stored in the webcal_config table.
 *
 * <b>Note:</b> If the setting for <var>server_url</var> is not set,
 * the value will be calculated and stored in the database.
 *
 * @global string  User's login name
 * @global bool    Readonly
 * @global string  HTTP hostname
 * @global int     Server's port number
 * @global string  Request string
 * @global array   Server variables
 */
function load_global_settings() {
  global $_SERVER, $APPLICATION_NAME, $FONTS, $HTTP_HOST,
  $LANGUAGE, $REQUEST_URI, $SERVER_PORT, $SERVER_URL;

  // Note:  When running from the command line (send_reminders.php),
  // these variables are (obviously) not set.
  // TODO:  This type of checking should be moved to a central location
  // like init.php.
  if ( isset ( $_SERVER ) && is_array ( $_SERVER ) ) {
    if ( empty ( $HTTP_HOST ) && isset ( $_SERVER['HTTP_HOST'] ) )
      $HTTP_HOST = $_SERVER['HTTP_HOST'];

    if ( empty ( $SERVER_PORT ) && isset ( $_SERVER['SERVER_PORT'] ) )
      $SERVER_PORT = $_SERVER['SERVER_PORT'];

    if ( ! isset ( $_SERVER['REQUEST_URI'] ) ) {
      $arr = explode ( '/', $_SERVER['PHP_SELF'] );
      $_SERVER['REQUEST_URI'] = '/' . $arr[count ( $arr )-1];
      if ( isset ( $_SERVER['argv'][0] ) && $_SERVER['argv'][0] != '' )
        $_SERVER['REQUEST_URI'] .= '?' . $_SERVER['argv'][0];
    }
    if ( empty ( $REQUEST_URI ) && isset ( $_SERVER['REQUEST_URI'] ) )
      $REQUEST_URI = $_SERVER['REQUEST_URI'];

    // Hack to fix up IIS.
    if ( isset ( $_SERVER['SERVER_SOFTWARE'] ) &&
        strstr ( $_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS' ) &&
        isset ( $_SERVER['SCRIPT_NAME'] ) )
      $REQUEST_URI = $_SERVER['SCRIPT_NAME'];
  }

  $rows = dbi_get_cached_rows ( 'SELECT setting, value
    FROM hc_config' );
  for ( $i = 0, $cnt = count ( $rows ); $i < $cnt; $i++ ) {
    $row = $rows[$i];
    $setting = $row[0];
    $GLOBALS[$setting] = $value = $row[1];
  }

  // Set SERVER TIMEZONE.
  if ( empty ( $GLOBALS['TIMEZONE'] ) )
    $GLOBALS['TIMEZONE'] = $GLOBALS['SERVER_TIMEZONE'];
  if (empty($GLOBALS['TIMEZONE'])) {
    $GLOBALS['TIMEZONE'] = 'America/New_York'; // fallback
  }

  set_env ( 'TZ', $GLOBALS['TIMEZONE'] );
  if ( empty ( $tzInitSet ) ) {
    if ( function_exists ( "date_default_timezone_set" ) )
      date_default_timezone_set ( $GLOBALS['TIMEZONE'] );
  }

  // If app name not set.... default to "Title". This gets translated later
  // since this function is typically called before translate.php is included.
  // Note:  We usually use translate ( $APPLICATION_NAME ) instead of
  // translate ( 'Title' ).
  if ( empty ( $APPLICATION_NAME ) )
    $APPLICATION_NAME = 'HomeCare';

  if ( empty ( $SERVER_URL ) &&
      ( ! empty ( $HTTP_HOST ) && ! empty ( $REQUEST_URI ) ) ) {
    $ptr = strrpos ( $REQUEST_URI, '/' );
    if ( $ptr > 0 ) {
      $SERVER_URL = 'http://' . $HTTP_HOST
       . ( ! empty ( $SERVER_PORT ) && $SERVER_PORT != 80
        ? ':' . $SERVER_PORT : '' )
       . substr ( $REQUEST_URI, 0, $ptr + 1 );

      dbi_execute ( 'INSERT INTO hc_config ( setting, value )
        VALUES ( ?, ? )', ['SERVER_URL', $SERVER_URL] );
    }
  }

  // If no font settings, then set default.
  if ( empty ( $FONTS ) )
    $FONTS = ( $LANGUAGE == 'Japanese' ? 'Osaka, ' : '' )
     . 'Arial, Helvetica, sans-serif';
}

/**
 * Set an environment variable if system allows it.
 *
 * @param string $val      name of environment variable
 * @param string $setting  value to assign
 *
 * @return bool  true = success false = not allowed.
 */
function set_env ( $val, $setting ) {
  global $tzInitSet, $tzOffset;

  // Set SERVER TIMEZONE.
  if ( ! $tzInitSet ) {
    if ( empty ( $GLOBALS['TIMEZONE'] ) )
      $GLOBALS['TIMEZONE'] = $GLOBALS['SERVER_TIMEZONE'];
    if ( function_exists ( "date_default_timezone_set" ) )
      date_default_timezone_set ( $GLOBALS['TIMEZONE'] );
  }

  $can_setTZ = ( substr ( $setting, 0, 11 ) == 'WebCalendar' ? false : true );
  $ret = false;
  // Test if safe_mode is enabled.
  // If so, we then check safe_mode_allowed_env_vars for $val.
  if ( ini_get ( 'safe_mode' ) ) {
    $allowed_vars = explode ( ',', ini_get ( 'safe_mode_allowed_env_vars' ) );
    if ( in_array ( $val, $allowed_vars ) )
      $ret = true;
  } else
    $ret = true;

  // We can't set TZ env on php 4.0 windows,
  // so the setting should already contain 'WebCalendar/xx'.
  if ( $ret == true && $can_setTZ )
    putenv ( $val . '=' . $setting );

  if ( $val == 'TZ' ) {
    $tzOffset = ( ! $can_setTZ ? substr ( $setting, 12 ) * 3600 : 0 );
    // Some say this is required to properly init timezone changes.
    mktime ( 0, 0, 0, 1, 1, 1970 );
  }

  return $ret;
}


$MIN_COLORS = 4;
$MAX_COLORS = 256;
$MAX_HEIGHT = $MAX_WIDTH = 600;
$DEFAULTS = [
  'color1' => 'ccc',
  'color2' => 'eee',
  'colors' => 32,
  'direction' => 90,
  'height' => 50,
  'percent' => 15,
  'width' => 50];

if ( empty ( $PHP_SELF ) && ! empty ( $_SERVER ) && !
    empty ( $_SERVER['PHP_SELF'] ) )
  $PHP_SELF = $_SERVER['PHP_SELF'];
// are we calling this file directly with GET parameters
if ( ! empty ( $_GET ) && ! empty ( $PHP_SELF ) &&
    preg_match ( "/\/includes\/gradient.php/", $PHP_SELF ) ) {
  if ( function_exists ( 'getGetValue' ) ) {
    $base      = getGetValue ( 'base' );
    $color1    = getGetValue ( 'color1' );
    $color2    = getGetValue ( 'color2' );
    $direction = getGetValue ( 'direction' );
    $height    = getGetValue ( 'height' );
    $numcolors = getGetValue ( 'colors' );
    $percent   = getGetValue ( 'percent' );
    $width     = getGetValue ( 'width' );
  } else {
    $base      = ( ! empty ( $_GET['base'] ) ? $_GET['base'] : '' );
    $color1    = ( ! empty ( $_GET['color1'] ) ? $_GET['color1'] : '' );
    $color2    = ( ! empty ( $_GET['color2'] ) ? $_GET['color2'] : '' );
    $direction = ( ! empty ( $_GET['direction'] ) ? $_GET['direction'] : '' );
    $height    = ( ! empty ( $_GET['height'] ) ? $_GET['height'] : '' );
    $numcolors = ( ! empty ( $_GET['colors'] ) ? $_GET['colors'] : '' );
    $percent   = ( ! empty ( $_GET['percent'] ) ? $_GET['percent'] : '' );
    $width     = ( ! empty ( $_GET['width'] ) ? $_GET['width'] : '' );
  }

  create_image ( '', $base, $height, $percent, $width,
    $direction, $numcolors, $color1, $color2 );
}

/**
 * Turn an HTML color (like 'AABBCC') into an array of decimal RGB values.
 *
 * Parameters:
 *  $color - HTML color specification in 'RRGGBB' or 'RGB' format
 *
 * Return value:
 *   ['red' => $red_val, 'green' => $green_val, 'blue' => $blue_val]
 */
function colorToRGB ( $color ) {
  if ( strlen ( $color ) == 6 ) {
    $red = hexdec ( substr ( $color, 0, 2 ) );
    $green = hexdec ( substr ( $color, 2, 2 ) );
    $blue = hexdec ( substr ( $color, 4, 2 ) );
  } elseif ( strlen ( $color ) == 3 ) {
    $red_hex = substr ( $color, 0, 1 );
    $red = hexdec ( $red_hex . $red_hex );

    $green_hex = substr ( $color, 1, 1 );
    $green = hexdec ( $green_hex . $green_hex );

    $blue_hex = substr ( $color, 2, 1 );
    $blue = hexdec ( $blue_hex . $blue_hex );
  } else
    // Invalid color specification
    return false;

  return ['red' => $red, 'green' => $green, 'blue' => $blue];
}
/**
 * can_write_to_dir (needs description)
 */
function can_write_to_dir ($path)
{
  if ($path[strlen($path) - 1] == '/') //Start function again with tmp file...
    return can_write_to_dir ( $path.uniqid ( mt_rand() ) . '.tmp');
  else if ( preg_match( '/\.tmp$/', $path ) ) { //Check tmp file for read/write capabilities
    if ( ! ( $f = @fopen ( $path, 'w+' ) ) )
      return false;

    fclose ( $f );
    unlink ( $path );
    return true;
  }
  else //We have a path error.
   return 0; // Or return error - invalid path...
}
/**
 * background_css (needs description)
 */
function background_css ( $base, $height = '', $percent = '' ) {
  global $ENABLE_GRADIENTS;

  $ret = $type = '';

  if ( function_exists ( 'imagepng' ) )
    $type = '.png';
  elseif ( function_exists ( 'imagegif' ) )
    $type = '.gif';

  $ret = 'background';
  if ( $type != '' && $ENABLE_GRADIENTS == 'Y' ) {
    $ret .= ': ' . $base . ' url( ';
    if ( ! file_exists ( 'images/cache' ) || ! can_write_to_dir ( 'images/cache/' ) )
      $ret .= 'includes/gradient.php?base=' . substr ( $base, 1 )
       . ( $height != '' ? '&height=' . $height : '' )
       . ( $percent != '' ? '&percent=' . $percent : '' );
    else {
      $file_name = 'images/cache/' . substr ( $base, 1, 6 )
       . ( $height != '' ? '-' . $height : '' )
       . ( $percent != ''? '-' . $percent : '' ) . $type;
      if ( ! file_exists ( $file_name ) )
        $tmp = create_image ( $file_name, $base, $height, $percent );

      $ret .= $file_name;
    }
    $ret .= ' ) repeat-x';
  } else
    $ret .= '-color: ' . $base;

  return $ret . ';';
}
/**
 * create_image (needs description)
 */
function create_image ( $file_name, $base = '', $height = '', $percent = '',
  $width = '', $direction = '', $numcolors = '', $color1 = '', $color2 = '' ) {
  global $DEFAULTS, $MAX_COLORS, $MAX_HEIGHT, $MAX_WIDTH, $MIN_COLORS;

  if ( $base != '' )
    $color1 = $color2 = $base;

  $color1 = ( $color1 == ''
    ? colorToRGB ( $DEFAULTS['color1'] )
    : ( preg_match ( "/^#?([0-9a-fA-F]{3,6})/", $color1, $matches )
      ? colorToRGB ( $matches[1] )
      : colorToRGB ( $DEFAULTS['color1'] ) ) );

  $color2 = ( $color2 == ''
    ? colorToRGB ( $DEFAULTS['color2'] )
    : ( preg_match ( "/^#?([0-9a-fA-F]{3,6})/", $color2, $matches )
      ? colorToRGB ( $matches[1] )
      : colorToRGB ( $DEFAULTS['color2'] ) ) );

  if ( empty ( $height ) )
    $height = $DEFAULTS['height'];

  if ( $height > $MAX_HEIGHT )
    $height = $MAX_HEIGHT;

  if ( $direction == '' || ( $direction % 90 ) != 0 )
    $direction = $DEFAULTS['direction'];
  else {
    while ( $direction > 360 ) {
      $direction -= 360;
    }
  }

  if ( $direction == 90 || $direction == 270 ) {
    // Vertical gradient
    if ( empty ( $height ) )
      $height = $DEFAULTS['height'];

    if ( $height > $MAX_HEIGHT )
      $height = $MAX_HEIGHT;

    $width = 1;
  } else {
    // Horizontal gradient
    if ( empty ( $width ) )
      $width = $DEFAULTS['width'];

    if ( $width > $MAX_WIDTH )
      $width = $MAX_WIDTH;

    $height = 1;
  }

  if ( empty ( $numcolors ) )
    $numcolors = $DEFAULTS['colors'];
  else {
    if ( preg_match ( '/^\d+$/', $numcolors ) ) {
      if ( $numcolors < $MIN_COLORS )
        $numcolors = $MIN_COLORS;
      else
      if ( $numcolors > $MAX_COLORS )
        $numcolors = $MAX_COLORS;
    } else
      $numcolors = $DEFAULTS['colors'];
  }

  if ( $percent == '' || $percent < 0 || $percent > 100 )
    $percent = $DEFAULTS['percent'];

  $percent *= 2.55;

  $color2['red'] = min ( $color2['red'] + $percent, 255 );
  $color2['green'] = min ( $color2['green'] + $percent, 255 );
  $color2['blue'] = min ( $color2['blue'] + $percent, 255 );

  $image = imagecreate ( $width, $height );
  // Allocate array of colors
  $colors = [];

  $deltared = $color2['red'] - $color1['red'];
  $deltagreen = $color2['green'] - $color1['green'];
  $deltablue = $color2['blue'] - $color1['blue'];

  $tmp_c = $numcolors - 1;

  for ( $i = 0; $i < $numcolors; $i++ ) {
    $thisred =
    floor ( min ( $color1['red'] + ( $deltared * $i / $tmp_c ), 255 ) );

    $thisgreen =
    floor ( min ( $color1['green'] + ( $deltagreen * $i / $tmp_c ), 255 ) );

    $thisblue =
    floor ( min ( $color1['blue'] + ( $deltablue * $i / $tmp_c ), 255 ) );

    $colors[$i] = imagecolorallocate ( $image, $thisred, $thisgreen, $thisblue );
  }

  $dim = $width;

  $dx = $dy = $i = $x1 = $y1 = 0;

  $x2 = $width - 1;
  $y2 = $height - 1;

  switch ( $direction ) {
    case 0:
      $dx = 1;

      $x2 = 0;
      break;
    case 90:
      $dim = $height;

      $dy = -1;

      $y1 = $height - 1;
      break;
    case 180:
      $dx = -1;

      $x1 = $width - 1;
      break;
    case 270:
      $dim = $height;

      $dy = 1;

      $y2 = 0;
      break;
  } while ( $x1 >= 0 && $x1 < $width
         && $x2 >= 0 && $x2 < $width
         && $y1 >= 0 && $y1 < $height
         && $y2 >= 0 && $y2 < $height ) {
    // Which color for this line?
    $ind = floor ( $numcolors * $i / $dim );
    if ( $ind >= $numcolors )
      $ind = $numcolors;

    imageline ( $image, $x1, $y1, $x2, $y2, $colors[$ind] );

    $x1 += $dx;
    $y1 += $dy;

    $x2 += $dx;
    $y2 += $dy;

    $i++;
  }

  if ( function_exists ( 'imagepng' ) ) {
    if ( $file_name == '' ) {
      header ( 'Content-type: image/png' );
      imagepng ( $image );
    } else
      imagepng ( $image, $file_name );
  } elseif ( function_exists ( 'imagegif' ) ) {
    if ( $file_name == '' ) {
      header ( 'Content-type: image/gif' );
      imagegif ( $image );
    } else
      imagegif ( $image, $file_name );
  } else
    echo 'No image formats supported!<br>' . "\n";

  imagedestroy ( $image );
  return;
}

/**
 * General purpose functions to convert RGB to HSL and HSL to RBG
 */
function  rgb2hsl ( $rgb ) {
  if ( substr ($rgb, 0,1 ) == '#' )
     $rgb = substr ( $rgb,1,6);

  $R = ( hexdec (substr ( $rgb,0,2) ) / 255 );
  $G = ( hexdec (substr ( $rgb,2,2) ) / 255 );
  $B = ( hexdec (substr ( $rgb,4,2) ) / 255 );

  $Min = min ( $R, $G, $B );    //Min. value of RGB
  $Max = max( $R, $G, $B );    //Max. value of RGB
  $deltaMax = $Max - $Min;     //Delta RGB value
  $L = ( $Max + $Min ) / 2;

  if ( $deltaMax == 0 )      //This is a gray, no chroma...
  {
     $H = 0;                  //HSL results = 0 ≈ 1
     $S = 0;
  }
  else                        //Chromatic data...
  {
     if ( $L < 0.5 )
       $S = $deltaMax / ( $Max + $Min );
     else
       $S = $deltaMax / ( 2 - $Max - $Min );

     $deltaR = ( ( ( $Max - $R ) / 6 ) + ( $deltaMax / 2 ) ) / $deltaMax;
     $deltaG = ( ( ( $Max - $G ) / 6 ) + ( $deltaMax / 2 ) ) / $deltaMax;
     $deltaB = ( ( ( $Max - $B ) / 6 ) + ( $deltaMax / 2 ) ) / $deltaMax;

     if ( $R == $Max )
       $H = $deltaB - $deltaG;
     else if ( $G == $Max )
       $H = ( 1 / 3 ) + $deltaR - $deltaB;
     else if ( $B == $Max )
      $H = ( 2 / 3 ) + $deltaG - $deltaR;

     if ( $H < 0 ) $H += 1;
     if ( $H > 1 ) $H -= 1;
  }
  return [$H, $S, $L];
}

function hsl2rgb( $hsl ) {
  if ( $hsl[1] == 0 )
  {
     $R = $hsl[2] * 255;
     $G = $hsl[2] * 255;
     $B = $hsl[2] * 255;
  }
  else
  {
     if ( $hsl[2] < 0.5 )
       $var_2 = $hsl[2] * ( 1 + $hsl[1] );
     else
       $var_2 = ( $hsl[2] + $hsl[1] ) - ( $hsl[1] * $hsl[2] );

     $var_1 = 2 * $hsl[2]- $var_2;

     $R = 255 * Hue_2_RGB( $var_1, $var_2, $hsl[0] + ( 1 / 3 ) );
     $G = 255 * Hue_2_RGB( $var_1, $var_2, $hsl[0] );
     $B = 255 * Hue_2_RGB( $var_1, $var_2, $hsl[0] - ( 1 / 3 ) );
  }
  $R = sprintf ( "%02X",round ( $R));
  $G = sprintf ( "%02X",round ( $G));
  $B = sprintf ( "%02X",round ( $B));

  $rgb = '#' . $R . $G . $B;

  return $rgb;
}

function Hue_2_RGB( $v1, $v2, $vH ) {
   if ( $vH < 0 ) $vH += 1;
   if ( $vH > 1 ) $vH -= 1;
   if ( ( 6 * $vH ) < 1 ) return ( $v1 + ( $v2 - $v1 ) * 6 * $vH );
   if ( ( 2 * $vH ) < 1 ) return ( $v2 );
   if ( ( 3 * $vH ) < 2 ) return ( $v1 + ( $v2 - $v1 ) * ( ( 2 / 3 ) - $vH ) * 6 );
   return ( $v1 );
}

/**
 * Given an RGB value, return it's luminance adjusted by scale
 * scale range = 0 to 9
 */
function rgb_luminance ( $rgb, $scale=5) {
  $luminance = [.44, .50, .56, .62, .68, .74, .80, .86, .92, .98];
  if ( $scale < 0 ) $scale = 0;
  if ( $scale > 9 ) $scale = 9;
  $new = rgb2hsl ( $rgb );
  $new[2] = $luminance[ round ( $scale )];
  $newColor = hsl2rgb( $new );
  return $newColor;
}

function formatDateNicely($datetime) {
  // Set the default timezone if needed (adjust accordingly to your location)
  date_default_timezone_set('America/New_York');
  
  // Convert the datetime string to a Unix timestamp
  $timestamp = strtotime($datetime);

  // Get today's date, tomorrow's date, and yesterday's date
  $today = strtotime(date('Y-m-d 00:00:00'));  // Today at 00:00:00
  $tomorrow = strtotime('+1 day', $today);
  $yesterday = strtotime('-1 day', $today);

  // Format the time part with AM/PM notation
  $timePart = date('g:i A', $timestamp);

  if ($timestamp >= $today && $timestamp < $tomorrow) {
      // Date is today
      return "Today at $timePart";
  } elseif ($timestamp >= $tomorrow && $timestamp < strtotime('+2 days', $today)) {
      // Date is tomorrow
      return "Tomorrow at $timePart";
  } elseif ($timestamp >= $yesterday && $timestamp < $today) {
      // Date is yesterday
      return "Yesterday at $timePart";
  } else {
      // Any other date
      return date('F j', $timestamp) . " at $timePart";
  }
}

function secondsUntilMidnight() {
  $currentTimestamp = time();
  $midnightTimestamp = strtotime('tomorrow');
  $secondsUntilMidnight = $midnightTimestamp - $currentTimestamp;
  return $secondsUntilMidnight;
}

?>