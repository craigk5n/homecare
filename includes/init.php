<?php
/**
 * Does various initialization tasks and includes all needed files.
 *
 * This page is included by most HomeCare pages as the only include file.
 * This greatly simplifies the other PHP pages since they don't need to worry
 * about what files it includes.
 *
 * <b>Comments:</b>
 * The following scripts do not use this file:
 *   - TBD....
 *
 * How to use:
 *   1. call include_once 'includes/init.php' at the top of your script.
 *   2. call any other functions or includes not in this file that you need
 *   3. call the print_header function with proper arguments
 *
 * What gets called:
 *   - require_once "includes/$user_inc";
 *   - require_once 'includes/config.php';
 *   - require_once 'includes/dbi4php.php';
 *   - require_once 'includes/formvars.php';
 *   - require_once 'includes/functions.php';
 *   - require_once 'includes/translate.php';
 *   - require_once 'includes/validate.php';
 */

 if( empty( $_SERVER['PHP_SELF'] )
     || ( ! empty( $_SERVER['PHP_SELF'] )
       && preg_match( '/\/includes\//', $_SERVER['PHP_SELF'] ) ) ) {
  die( 'You cannot access this file directly!' );
 }

require_once 'includes/translate.php';

require_once 'includes/config.php';
require_once 'includes/dbi4php.php';
require_once 'includes/formvars.php';
require_once 'includes/functions.php';
require_once 'includes/homecare.php';
//require_once "includes/$user_inc";
require_once 'includes/validate.php';

do_config ();

date_default_timezone_set('America/New_York');

// Native HomeCare auth: resolves $login from the session or the
// remember-me cookie, or redirects to login.php. Public endpoints
// (CLI, login.php, logout.php, schedule_ics.php, css_cacher.php)
// short-circuit inside hc_validate().
hc_validate();

// Return the toplevel URL (no path) of the current URL.
function get_server_top_url () {
  if(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
     $url = "https://";
  else
     $url = "http://";
  // Append the host(domain name, ip) to the URL.
  $url.= $_SERVER['HTTP_HOST'];
  return $url;
}


/**
  * Send HTTP headers.  Would be nice to make some of these configurable
  * in the admin System Settings.
  */
function send_http_headers (string $nonce = '') {
  global $CSP, $PROGRAM_DATE, $PROGRAM_VERSION;

  $csp = empty($CSP) || in_array($CSP, ['none', 'same', 'any']) ? $CSP : 'none';

  // Prevent click-jacking
  Header('X-Frame-Options: DENY');
  Header('Content-Security-Policy: frame-ancestors \'none\'');

  // Other security headers
  Header('X-Content-Type-Options: nosniff');
  Header('Referrer-Policy: strict-origin-when-cross-origin');
  Header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

  // HSTS if HTTPS
  if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    Header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  }

  // Enhanced CSP with nonce for scripts
  $cspValue = "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self';";
  Header("Content-Security-Policy: $cspValue");

  // App metadata
  Header('HomeCare-Version: ' . ltrim($PROGRAM_VERSION, 'v'));
  Header('HomeCare-Date: ' . $PROGRAM_DATE);
}

/**
 * Prints the HTML header and opening HTML body tag.
 *
 * @param array  $includes     Array of additional files to include referenced
 *                             from the includes directory
 * @param string $HeadX        Data to be printed inside the head tag (meta,
 *                             script, etc)
 * @param string $BodyX        Data to be printed inside the Body tag (onload
 *                             for example)
 * @param bool   $disableCustom Do not include custom header? (useful for small
 *                             popup windows, such as color selection)
 * @param bool   $disableStyle Do not include the standard css?
 * @param bool   $disableRSS   Do not include the RSS link
 * @param bool   $IGNORED      Parameter not used (ignored)
 * @param bool   $disableUTIL  Do not include the util.js link
 */
function print_header( $includes = '', $HeadX = '', $BodyX = '',
  $disableCustom = false, $disableStyle = false, $disableRSS = false,
  $IGNORED = false, $disableUTIL = false ) {
  global $BGCOLOR, $browser, $charset, $CSP, $CUSTOM_HEADER, $CUSTOM_SCRIPT,
  $DISABLE_POPUPS, $DISPLAY_TASKS, $DISPLAY_WEEKENDS, $FONTS, $friendly,
  $is_admin, $LANGUAGE, $login, $MENU_ENABLED, $MENU_THEME, $OTHERMONTHBG,
  $POPUP_FG, $PUBLIC_ACCESS, $PUBLIC_ACCESS_FULLNAME, $REQUEST_URI, $SCRIPT,
  $self, $TABLECELLFG, $TEXTCOLOR, $THBG, $THFG, $TODAYCELLBG, $WEEKENDBG,
  $ASSETS;

  ob_start ();

  if ( is_dir ( 'includes' ) ) {
    $incdir = 'includes';
  } elseif ( is_dir ( '../includes' ) ) {
    $incdir = '../includes';
  }

  $cs_ret = $lang = '';

  // Remember this view if the file is a view_x.php script.
  if( ! strstr( $REQUEST_URI, 'view_entry' ) )
    remember_this_view( true );

  // Menu control.
  if( ! empty( $friendly ) || $disableCustom )
    $MENU_ENABLED = 'N';

  $appStr = generate_application_name( true );
  // Include includes/css/print_styles.css as a media="print" stylesheet.
  // When the user clicks on the "Printer Friendly" link, $friendly will be
  // non-empty, including this as a normal stylesheet so they can see how it
  // will look when printed. This maintains backwards-compatibility for browsers
  // that don't support media="print" stylesheets.
  $cs_ar = ['css/styles.css', 'css/print_styles.css'];
  $js_ar = [];

  $ret = send_doctype( $appStr );
// Use "punctuation.css" to start getting punctuation out of the code to where the translators can get at it.
  //  <link href="' . $incdir . '/css/punctuation.css" rel="stylesheet">';

  // HC-079: fresh per-request CSP nonce, shared with send_http_headers()
  // and stamped on every inline <script> below.
  $nonce = bin2hex(random_bytes(16));
  $GLOBALS['NONCE'] = $nonce;
  send_http_headers ($nonce);

    if (empty($CSP) || $CSP == 'none') {
      $ret .= "\n<style id=\"antiClickjack\">body{display:none !important;}</style>\n" .
        "<script nonce=\"" . $nonce . "\">\n" .
        "  if (self.location.hostname === top.location.hostname) {\n" .
        "      var antiClickjack = document.getElementById(\"antiClickjack\");\n" .
        "      antiClickjack.parentNode.removeChild(antiClickjack);\n" .
        "  } else {\n" .
        "      top.location = self.location;\n" .
        "  }\n" .
        "</script>\n";
      }


// Remove duplicate code

  $ret .= $ASSETS;

  if( ! $disableUTIL )
    $js_ar[] = 'js/util.js';

  if( ! empty( $js_ar ) )
    foreach( $js_ar as $j ) {
      $i = 'includes/' . $j;
      $ret .= '
    <script nonce="' . $nonce . '" src="' . $i . '"></script>';
    }

  // Any other includes?
  if( is_array( $includes ) ) {
    foreach( $includes as $inc ) {
      //$cs_ret .= '<!-- inc "' . $inc . '" INCLUDED -->' . "\n";
      if ( $inc == 'JQUERY' ) {
        // Ignore since we handled it above
        //$cs_ret .= '<!-- JQUERY INCLUDED -->' . "\n";
      } if( stristr( $inc, '.css' ) ) {
        $i = 'includes/' . $inc;
        // Not added to $cs_ar because I think we want these,
        // even if $disableStyle.
        $cs_ret .= '
    <link href="' . $i . '" rel="stylesheet">';
      } elseif( substr( $inc, 0, 12 ) == 'js/popups.js'
          && ! empty( $DISABLE_POPUPS ) && $DISABLE_POPUPS == 'Y' ) {
        // Don't load popups.js if DISABLE_POPUPS.
      } else {
        $arinc = explode( '/', $inc );
    $ret .= '
    <script nonce="' . $nonce . '" src="' ;

        if( stristr( $inc, '/true' ) ) {
          $i = 'includes';
          foreach( $arinc as $a ) {
            if( $a == 'true' )
              break;

            $i .= '/' . $a;
          }
          $ret .= $i . '?' . filemtime( $i );
        } else {
          $ret .= 'js_cacher.php?inc=' . $inc;
        }
        $ret .= '"></script>';
      }
    }
  }

  if( ! $disableStyle ) {
    // Check the CSS version for cache clearing if needed.
    if( isset( $_COOKIE['webcalendar_csscache'] ) )
      $webcalendar_csscache = $_COOKIE['webcalendar_csscache'];
    else {
      $webcalendar_csscache = 1;
      sendCookie( 'webcalendar_csscache', $webcalendar_csscache );
    }
    $ret .= '
    <link href="css_cacher.php?login='
     . ( empty( $_SESSION['hc_tmp_login'] )
       ? $login : $_SESSION['hc_tmp_login'] )
     . '&amp;css_cache=' . $webcalendar_csscache . '" rel="stylesheet">';
    foreach( $cs_ar as $c ) {
      $i = 'includes/' . $c;
      $ret .= '
    <link href="' . $i . '" rel="stylesheet"'
       . ( $c == 'css/print_styles.css' && empty( $friendly )
         ? ' media="print"' : '' ) . '>' . "\n";
    }
  }
  echo $ret . $cs_ret
  // Add custom script/stylesheet if enabled.
   . ( $CUSTOM_SCRIPT == 'Y' && ! $disableCustom
     ? load_template( $login, 'S' ) : '' )
  // $HeadX moved here because linked CSS may override standard styles.
   . ( $HeadX ? '
     ' . $HeadX : '' ) . '
    <link type="image/x-icon" href="favicon.ico?'
   . filemtime( 'favicon.ico' ) . '" rel="shortcut icon">
    <!-- HC-060: PWA. manifest + theme color + apple-touch-icon so iOS
         "Add to Home Screen" picks up our icon, and a tiny inline
         registration of /sw.js. The SW caches static assets only; PHP
         pages and API JSON are network-only. -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0d6efd">
    <link rel="apple-touch-icon" href="pub/icons/icon-192.png">
    <script nonce="' . $nonce . '">
      if ("serviceWorker" in navigator) {
        window.addEventListener("load", function () {
          navigator.serviceWorker.register("sw.js").catch(function (e) {
            console.warn("HomeCare SW registration failed:", e);
          });
        });
      }
    </script>
  </head>
  <body'
  // Determine the page direction (left-to-right or right-to-left).
  . ( translate( 'direction' ) == 'rtl' ? ' dir="rtl"' : '' )
  /* Add <body> id. */ . ' id="' . preg_replace( '/(_|.php)/', '',
    substr( $self, strrpos( $self, '/' ) + 1 ) ) . '"'
  // Add any extra parts to the <body> tag.
  . ( empty( $BodyX ) ? '' : " $BodyX" ) . '>' . "\n"
  // Add custom header if enabled.
  . ( $CUSTOM_HEADER == 'Y' && ! $disableCustom
    ? load_template( $login, 'H' ) : '' );
  // HTML includes needed for the top menu.
  if( empty($MENU_ENABLED) || $MENU_ENABLED == 'Y' ) {
    require_once 'menu.php';
  }
  // TODO convert this to return value.
  echo '<div class="container-fluid">';
}

/**
 * Returns the common trailer.  (DOES NOT print it, unlike print_header.)
 *
 * @param bool $include_nav_links Should the standard navigation links be
 *                                included in the trailer?
 * @param bool $closeDb           Close the database connection when finished?
 * @param bool $disableCustom     Disable the custom trailer the administrator
 *                                has setup? (This is useful for small popup
 *                                windows and pages being used in an iframe.)
 */
function print_trailer( $include_nav_links = true, $closeDb = true,
  $disableCustom = false ) {
  global $ALLOW_VIEW_OTHER, $c, $cat_id, $CATEGORIES_ENABLED, $CUSTOM_TRAILER,
  $DATE_FORMAT_MD, $DATE_FORMAT_MY, $DEMO_MODE, $DISPLAY_TASKS, $friendly,
  $DISPLAY_TASKS_IN_GRID, $fullname, $GROUPS_ENABLED, $has_boss, $is_admin,
  $is_nonuser, $is_nonuser_admin, $LAYER_STATUS, $login, $login_return_path,
  $MENU_DATE_TOP, $MENU_ENABLED, $NONUSER_ENABLED, $PUBLIC_ACCESS,
  $PUBLIC_ACCESS_CAN_ADD, $PUBLIC_ACCESS_FULLNAME, $PUBLIC_ACCESS_OTHERS,
  $readonly, $REPORTS_ENABLED, $REQUIRE_APPROVALS, $single_user, $STARTVIEW,
  $SQLLOG, $thisday, $thismonth, $thisyear, $use_http_auth, $user, $views, $WEEK_START;

  $ret = '';

  // If menu enabled, include JS for Bootstrap v4 submenu.
  // TODO: Get the submenu working to allow for more dates in the menu.
  if ($MENU_ENABLED != 'N') {
    $ret .= '<script src="./includes/js/menu.js"></script>' . "\n";
  }
  if( $include_nav_links && ! $friendly ) {
    if( $MENU_ENABLED == 'N' )
      require_once 'includes/trailer.php';
  }

  $ret .= ( empty( $tret ) ? '' : $tret ) // Data from trailer.
  // Add custom trailer if enabled.
  . ( $CUSTOM_TRAILER == 'Y' && ! $disableCustom && isset( $c )
    ? load_template( $login, 'T' ) : '' );

  if( $closeDb ) {
    if( isset( $c ) )
      dbi_close( $c );

    unset( $c );
  }

  // Only enable CKEditor on the following pages.  Some pages are expecting plain
  // text and HTML will cause issues.
  $pagesWithFullEditor = [ ]; // TODO: add later?
  $includeCkeditor = ( ! empty ( $GLOBALS['ALLOW_HTML_DESCRIPTION'] ) ) &&
    $GLOBALS['ALLOW_HTML_DESCRIPTION'] == 'Y' &&
    in_array ( $GLOBALS['SCRIPT'], $pagesWithFullEditor );

  $debug = '';
  if (dbi_get_debug()) {
    $debug = '<blockquote style="border:1px solid #ccc; background:#eee;">'
      . '<b>Executed queries:' . dbi_num_queries()
      . '&nbsp;&nbsp; <b>Cached queries:</b>' . dbi_num_cached_queries()
      . "<br><ol>\n";
    $log = $GLOBALS['SQLLOG'];
    $logcnt = count ( $log );
    for ( $i = 0; $i < $logcnt; $i++ ) {
      $debug .= '<li>' . $log[$i] . '</li>';
    }
    $debug .= "</ol>\n</blockquote>\n";
  }
  return $ret .
    '<!-- ' . $GLOBALS['PROGRAM_NAME'] . '     ' . $GLOBALS['PROGRAM_URL'] . ' -->' .
    // CKEditor block removed in HC-079; keep the conditional reachable
    // in case $includeCkeditor is ever wired back up.
    ( $includeCkeditor ? '' : '' ) .
    // Adds an easy link to validate the pages.
    ( $DEMO_MODE == 'Y' ? '
    <p><a href="http://validator.w3.org/check?uri=referer">'
     . '<img src="http://w3.org/Icons/valid-xhtml10" alt="Valid XHTML 1.0!" '
     . 'class="valid"></a></p>' : '' )/* Close HTML page properly. */ . '
    </div>' . $debug .
    '</body>
  </html>';
}

?>
