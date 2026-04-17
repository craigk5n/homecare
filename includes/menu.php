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
  $new_intake_url = 'edit_intake.php';
  $new_med_url = 'edit_medication.php';
}

if (!$use_http_auth && $single_user != 'Y') {
  $login_url = 'login.php';
  if (!empty($login_return_path)) {
    $login_url .= '?return_path=' . $login_return_path;
  }
  $logout_url = 'logout.php';
}

$patients = getPatients();
$activePatientId = getActivePatientId();
$activePatient = null;
foreach ($patients as $p) {
  if ((int) $p['id'] === $activePatientId) {
    $activePatient = $p;
    break;
  }
}
// activePatient can still be null if the active id points at a
// disabled patient (getPatients() hides those). Fall back to the
// raw lookup so the chip labels correctly.
if ($activePatient === null && $activePatientId > 0) {
  try {
    $activePatient = getPatient($activePatientId);
  } catch (Throwable) {
    $activePatient = null;
  }
}

$activeIdQs = $activePatientId > 0 ? '?patient_id=' . $activePatientId : '';
$hasPatient = $activePatientId > 0 && $activePatient !== null;

?>
<nav class="navbar navbar-expand-md navbar-light bg-light">
  <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
    <span class="navbar-toggler-icon"></span>
  </button>
  <div class="navbar-collapse collapse w-50 order-1 order-md-0 dual-collapse2" id="navbarNavDropdown">
    <ul class="navbar-nav">
      <!-- Dashboard (cross-patient overview) -->
      <li class="nav-item">
        <a class="nav-link" href="dashboard.php"><?php etranslate('Dashboard'); ?></a>
      </li>

      <!-- Medications catalog (global; not patient-scoped) -->
      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="medsDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          <?php etranslate('Medications'); ?>
        </a>
        <div class="dropdown-menu" aria-labelledby="medsDropdown">
          <a class="dropdown-item" href="list_medications.php"><?php etranslate('Available Medications'); ?></a>
          <?php if (!empty($new_med_url)) { ?>
            <a class="dropdown-item" href="<?php echo $new_med_url; ?>"><?php etranslate('Add New Medication'); ?></a>
          <?php } ?>
          <a class="dropdown-item" href="inventory_dashboard.php"><?php etranslate('Inventory Dashboard'); ?></a>
          <?php if (!empty($is_admin) && $is_admin) { ?>
            <a class="dropdown-item" href="merge_medicines.php"><?php etranslate('Merge Medicines'); ?></a>
          <?php } ?>
        </div>
      </li>

      <?php if ($hasPatient) { ?>
        <!-- Doses (active patient) -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="dosesDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <?php etranslate('Doses'); ?>
          </a>
          <div class="dropdown-menu" aria-labelledby="dosesDropdown">
            <a class="dropdown-item" href="list_schedule.php<?php echo $activeIdQs; ?>"><?php etranslate('Dose Tracker'); ?></a>
            <a class="dropdown-item" href="schedule_daily.php<?php echo $activeIdQs; ?>"><?php etranslate("Today's Schedule"); ?></a>
            <div class="dropdown-divider"></div>
            <?php if ($can_add) { ?>
              <a class="dropdown-item" href="bulk_intake.php<?php echo $activeIdQs; ?>"><?php etranslate('Bulk Intake (catch up)'); ?></a>
            <?php } ?>
          </div>
        </li>

        <!-- Notes (active patient) -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="notesDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <?php etranslate('Notes'); ?>
          </a>
          <div class="dropdown-menu" aria-labelledby="notesDropdown">
            <a class="dropdown-item" href="list_caregiver_notes.php<?php echo $activeIdQs; ?>"><?php etranslate('View Notes'); ?></a>
            <?php if ($can_add) { ?>
              <a class="dropdown-item" href="note_caregiver.php<?php echo $activeIdQs; ?>"><?php etranslate('Add Note'); ?></a>
              <div class="dropdown-divider"></div>
              <a class="dropdown-item" href="import_notes_journal.php<?php echo $activeIdQs; ?>"><?php etranslate('Paste Journal'); ?></a>
            <?php } ?>
            <?php if (!empty($is_admin) && $is_admin) { ?>
              <a class="dropdown-item" href="import_caregiver_notes.php"><?php etranslate('Import Notes from File'); ?></a>
            <?php } ?>
          </div>
        </li>

        <!-- Reports (active patient) -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <?php etranslate('Reports'); ?>
          </a>
          <div class="dropdown-menu" aria-labelledby="reportsDropdown">
            <a class="dropdown-item" href="report_intake.php<?php echo $activeIdQs; ?>"><?php etranslate('Intake History'); ?></a>
            <a class="dropdown-item" href="report_missed.php<?php echo $activeIdQs; ?>"><?php etranslate('Missed Medications'); ?></a>
            <a class="dropdown-item" href="report_medications.php<?php echo $activeIdQs; ?>"><?php etranslate('Medication Supply'); ?></a>
            <a class="dropdown-item" href="report_adherence.php<?php echo $activeIdQs; ?>"><?php etranslate('Adherence'); ?></a>
          </div>
        </li>
      <?php } ?>

      <?php if ($login != '__public__' && !$is_nonuser && $readonly != 'Y') { ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="settingsDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <?php etranslate('Settings'); ?>
          </a>
          <div class="dropdown-menu" aria-labelledby="settingsDropdown">
            <?php
              echo '<h6 class="dropdown-header">' . translate('Your Settings') . '</h6>';
              print_menu_item(translate('Account & API key'), 'settings.php');
              if ($is_admin) {
                echo '<div class="dropdown-divider"></div>';
                echo '<h6 class="dropdown-header">' . translate('Admin Settings') . '</h6>';
                print_menu_item(translate('Notifications (ntfy)'), 'settings.php#notifications');
                print_menu_item(translate('Email (SMTP)'), 'settings.php#email');
                print_menu_item(translate('Audit Log'), 'audit_log.php');
              }
            ?>
          </div>
        </li>
      <?php } ?>

      <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" id="helpDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          <?php etranslate('Help'); ?>
        </a>
        <div class="dropdown-menu" aria-labelledby="helpDropdown">
          <a class="dropdown-item" href="#" data-action="openHelp"><?php etranslate('Help Contents'); ?></a>
          <a class="dropdown-item" href="#" data-action="openAbout"><?php etranslate('About HomeCare'); ?></a>
        </div>
      </li>
    </ul>
  </div>

  <!-- Right side: patient context chip + logout -->
  <div class="navbar-collapse collapse w-50 order-3 dual-collapse2">
    <ul class="navbar-nav ml-auto align-items-md-center">
      <?php if ($hasPatient && count($patients) > 0) { ?>
        <li class="nav-item dropdown hc-patient-chip">
          <a class="nav-link dropdown-toggle" href="#" id="patientChip" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="<?php etranslate('Switch patient'); ?>">
            <span class="hc-chip-label"><?php echo htmlspecialchars((string) $activePatient['name']); ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right" aria-labelledby="patientChip">
            <h6 class="dropdown-header"><?php etranslate('Viewing patient'); ?></h6>
            <?php foreach ($patients as $p) {
              $isCurrent = ((int) $p['id'] === $activePatientId);
              $classes = 'dropdown-item' . ($isCurrent ? ' active' : '');
              echo '<a class="' . $classes . '" href="set_patient.php?id=' . (int) $p['id'] . '">'
                . htmlspecialchars($p['name'])
                . ($isCurrent ? ' <span class="sr-only">(current)</span>' : '')
                . '</a>';
            } ?>
          </div>
        </li>
      <?php } ?>
      <?php if (!$use_http_auth && $single_user != 'Y') { ?>
        <li class="nav-item">
          <a class="nav-link" href="<?php echo $logout_url; ?>">Logout</a>
        </li>
      <?php } ?>
    </ul>
  </div>
</nav>
<script nonce="<?php echo htmlspecialchars($GLOBALS['NONCE'] ?? ''); ?>">
document.addEventListener('click', function(e) {
  var el = e.target.closest('[data-action]');
  if (!el) return;
  e.preventDefault();
  var action = el.getAttribute('data-action');
  if (action === 'openHelp' && typeof openHelp === 'function') openHelp();
  if (action === 'openAbout' && typeof openAbout === 'function') openAbout();
});
</script>

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
