<?php
global $ALLOW_VIEW_OTHER, $BodyX, 
$fullname, $HOME_LINK, $is_admin, $login_return_path,
$login, $MENU_DATE_TOP, $readonly,
$REPORTS_ENABLED, $show_printer, $single_user, $START_VIEW,
$use_http_auth, $user_fullname, $users;

/* -----------------------------------------------------------------------------
         First figure out what options are on and privileges we have
----------------------------------------------------------------------------- */
$can_add = (!empty($readonly) && $readonly != 'Y');

$help_url = 'help_index.php';
$home = (empty($STARTVIEW) ? 'list_medications.php' : $STARTVIEW);
$new_intake_url = $new_med_url = '';

if ($can_add) {
  // Add new medication intake
  $new_intake_url = 'edit_intake.php';
  $new_med_url = 'edit_medication.php';
}

// Logout/Login URL
if (!$use_http_auth && $single_user != 'Y') {
  $login_url = 'login.php';

  if (empty($login_return_path))
    $logout_url = $login_url . '?';
  else {
    $login_url .= '?return_path=' . $login_return_path;
    $logout_url = $login_url . '&';
  }
  $logout_url .= 'action=logout';
  if (empty($CSRF_PROTECTION) || $CSRF_PROTECTION != 'N') {
    $logout_url .= '&amp;csrf_form_key=' . getFormKey();
  }
}

$patients = getPatients();

?>
<nav class="navbar navbar-expand-md navbar-light bg-light">
  <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="navbar-collapse collapse w-50 order-1 order-md-0 dual-collapse2" id="navbarNavDropdown">
    <ul class="navbar-nav">
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          <?php etranslate('Medications'); ?>
        </a>
        <div id="nav-project-menu" class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
          <a class="dropdown-item" href="list_medications.php"><?php etranslate('Available Medications'); ?></a>
          <?php if (!empty($new_med_url)) { ?>
            <a class="dropdown-item" href="<?php echo $new_med_url; ?>"><?php etranslate('Add New Medication'); ?></a>
          <?php } ?>
          <a class="dropdown-item" href="edit_inventory.php"><?php etranslate('Update Inventory'); ?></a>
        </div>
      </li>

      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          <?php etranslate('Schedule'); ?>
        </a>
        <div id="nav-project-menu" class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
        <?php
        $first = true;
        foreach ($patients as $patient) {
          if ($first) {
            $first = false;
          } else {
            echo '<div class="dropdown-divider"></div>';
          }
        ?>
          <h6 class="dropdown-header"><?php echo htmlspecialchars($patient['name']); ?></h6>
        <?php
            print_menu_item(translate('List of Medication'), 'list_schedule.php?patient_id=' . $patient['id']);
            print_menu_item(translate('Today\'s Schedule'), 'schedule_daily.php?patient_id=' . $patient['id']);
        }
          ?>
        </div>
      </li>

      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          <?php etranslate('Intake'); ?>
        </a>
        <div id="nav-project-menu" class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
        <?php
        $first = true;
        foreach ($patients as $patient) {
          if ($first) {
            $first = false;
          } else {
            echo '<div class="dropdown-divider"></div>';
          }
        ?>
          <h6 class="dropdown-header"><?php echo htmlspecialchars($patient['name']); ?></h6>
          <?php
            print_menu_item(translate('Bulk Input'), 'bulk_intake.php?patient_id=' . $patient['id']);
          }
          ?>
        </div>
      </li>

      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          <?php etranslate('Reports'); ?>
        </a>
        <div id="nav-project-menu" class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
        <?php
        $first = true;
        foreach ($patients as $patient) {
          if ($first) {
            $first = false;
          } else {
            echo '<div class="dropdown-divider"></div>';
          }
        ?>
          <h6 class="dropdown-header"><?php echo htmlspecialchars($patient['name']); ?></h6>
          <?php
            print_menu_item('Intake History', 'report_intake.php?patient_id=' . $patient['id']);
            print_menu_item('Missed Medications', 'report_missed.php?patient_id=' . $patient['id']);
            print_menu_item('Medication Supply', 'report_medications.php?patient_id=' . $patient['id']);
          }
          ?>
        </div>
      </li>

      <?php if ($login != '__public__' && !$is_nonuser && $readonly != 'Y') { ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <?php etranslate('Settings'); ?>
          </a>
          <div id="nav-project-menu" class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
            <?php
            // Normal User Settings.
            echo '<h6 class="dropdown-header">' . translate('Your Settings') . '</h6>';
            //if (!$is_admin)
            //  print_menu_item(translate('My Profile'), 'user_mgmt.php');

            // print_menu_item(translate('Preferences'), 'pref.php');

            // Admin-only settings
            if ($is_admin) {
              echo '<div class="dropdown-divider"></div>';
              echo '<h6 class="dropdown-header">' . translate('Admin Settings') . '</h6>';
              //print_menu_item(translate('System Settings'), 'admin.php');
              //print_menu_item(translate('Users'), 'user_mgmt.php');
            }
            ?>
          </div>
        </li>
      <?php } ?>

      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          <?php etranslate('Help'); ?>
        </a>
        <div id="nav-project-menu" class="dropdown-menu" aria-labelledby="navbarDropdownMenuLink">
          <a class="dropdown-item" href="#" onclick="javascript:openHelp()"><?php etranslate('Help Contents'); ?></a>
          <a class="dropdown-item" href="#" onclick="javascript:openAbout()"><?php etranslate('About HomeCare'); ?></a>
        </div>
      </li>
    </ul>
  </div>

  <?php if (!$use_http_auth && $single_user != 'Y') { ?>
    <div class="navbar-collapse collapse w-20 order-3 dual-collapse2">
      <ul class="navbar-nav ml-auto">
        <li class="nav-item dropdown-menu-right">
          <a class="nav-link" href="<?php echo $logout_url; ?>">Logout</a>
        </li>
      </ul>
    </div>
  <?php } ?>

  </div>
</nav>

<?php
function print_menu_item($name, $url, $testCondition = true, $target = '')
{
  if ($testCondition) {
    echo '<a class="dropdown-item" href="' . $url . '"';
    if (!empty($target)) {
      echo ' target="' . $target . '"';
    }
    echo '>' . $name . '</a>' . "\n";
  }
}
?>
