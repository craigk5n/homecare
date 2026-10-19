<?php
require_once 'includes/init.php';
require_role('caregiver');

$schedule_id = getPostValue('schedule_id');
$patient_id = getPostValue('patient_id');
$taken_time = getPostValue('taken_time');
$edit_id = getPostValue('id');
$note = getPostValue('note');
$delete = getPostValue('delete');
// HC-238: one-tap buttons on the dashboard return there; everything
// else keeps going back to the patient's schedule.
$return_to = getPostValue('return_to');
$returnUrl = $return_to === 'dashboard'
  ? 'dashboard.php'
  : 'list_schedule.php?patient_id=' . urlencode((string) $patient_id);

if (!empty($delete) && $delete === 'yes') {
  // Delete the intake record
  $deleteSql = "DELETE FROM hc_medicine_intake WHERE id = ?";
  if (!dbi_execute($deleteSql, [$edit_id])) {
      echo "Error deleting intake: " . dbi_error();
  } else {
      audit_log('intake.deleted', 'intake', (int) $edit_id, [
          'schedule_id' => (int) $schedule_id,
          'patient_id' => (int) $patient_id,
      ]);
  }
} elseif (empty($edit_id)) {
  // HC-238: the one-tap "Took a dose now" button posts no time.
  if (empty($taken_time)) {
      $taken_time = date('Y-m-d H:i:s');
  }

  // HC-238: advisory PRN limits. Past a limit we ask for confirmation
  // instead of blocking -- the caregiver may have orders for an extra
  // dose. A confirmed over-limit dose is flagged in the audit row.
  $prnStatus = homecare_prn_limit_service()->statusFor((int) $schedule_id, (string) $taken_time);
  $overLimit = $prnStatus !== null && $prnStatus->isWarning();
  if ($overLimit && getPostValue('confirm_over_limit') !== '1') {
      $medRows = dbi_get_cached_rows(
          'SELECT m.name FROM hc_medicine_schedules ms JOIN hc_medicines m ON ms.medicine_id = m.id WHERE ms.id = ?',
          [$schedule_id]
      );
      $medName = (string) ($medRows[0][0] ?? '');
      $patient = getPatient($patient_id);

      print_header();
      echo "<h2>" . translate('Confirm dose') . "</h2>\n";
      echo "<div class='container mt-3'>\n";
      echo "<div class='card border-warning mb-4'>\n";
      echo "<div class='card-header bg-warning text-dark'>";
      echo "<img src='images/bootstrap-icons/exclamation-triangle-fill.svg' alt='Warning' class='mr-2'>";
      echo "<strong>" . translate('This dose is over the as-needed limit') . "</strong></div>\n";
      echo "<div class='card-body'>\n";
      echo "<p><strong>" . htmlspecialchars($medName) . "</strong> for <strong>"
        . htmlspecialchars((string) ($patient['name'] ?? '')) . "</strong> at "
        . htmlspecialchars(formatDateNicely((string) $taken_time)) . "</p>\n";
      echo "<p>" . htmlspecialchars((string) $prnStatus->warningMessage()) . "</p>\n";
      echo "<p class='mb-0 text-muted'>" . translate('Only record it if a doctor or vet has said an extra dose is OK. It will be marked as over the limit in the audit log.') . "</p>\n";
      echo "</div></div>\n";

      echo "<form action='record_intake_handler.php' method='POST'>\n";
      print_form_key();
      echo "<input type='hidden' name='schedule_id' value='" . htmlspecialchars((string) $schedule_id) . "'>\n";
      echo "<input type='hidden' name='patient_id' value='" . htmlspecialchars((string) $patient_id) . "'>\n";
      echo "<input type='hidden' name='taken_time' value='" . htmlspecialchars((string) $taken_time) . "'>\n";
      echo "<input type='hidden' name='return_to' value='" . htmlspecialchars((string) $return_to) . "'>\n";
      echo "<input type='hidden' name='confirm_over_limit' value='1'>\n";
      echo "<div class='form-group'>\n";
      echo "<label for='note'>" . translate('Notes') . ":</label>\n";
      echo "<textarea name='note' id='note' class='form-control' placeholder='"
        . htmlspecialchars(translate('e.g. extra dose approved by Dr. Smith')) . "'>"
        . htmlspecialchars((string) $note) . "</textarea>\n";
      echo "</div>\n";
      echo "<button type='submit' class='btn btn-warning'>" . translate('Record dose anyway') . "</button>\n";
      echo "<a href='" . htmlspecialchars($returnUrl) . "' class='btn btn-secondary ml-2'>" . translate('Cancel') . "</a>\n";
      echo "</form>\n";
      echo "</div>\n";
      echo print_trailer();
      exit;
  }

  // Insert intake record into database
  $insertSql = "INSERT INTO hc_medicine_intake (schedule_id, taken_time, note) VALUES (?, ?, ?)";
  $params = [$schedule_id, $taken_time, $note];
  if (!dbi_execute($insertSql, $params)) {
      echo "Error recording intake: " . dbi_error();
  } else {
      $newId = (int) mysqli_insert_id($GLOBALS['c']);
      $details = [
          'schedule_id' => (int) $schedule_id,
          'patient_id' => (int) $patient_id,
          'taken_time' => $taken_time,
          'note' => $note,
      ];
      if ($overLimit) {
          $details['prn_over_limit'] = true;
          $details['prn_warning'] = $prnStatus->warningMessage();
      }
      audit_log('intake.recorded', 'intake', $newId ?: null, $details);
  }
} else {
  // Edit existing entry
  $insertSql = "UPDATE hc_medicine_intake SET taken_time = ?, note = ? WHERE id = ?";
  $params = [$taken_time, $note, $edit_id];
  //echo "SQL: $insertSql <br>Params: "; print_r($params); echo "<br>"; exit;
  if (!dbi_execute($insertSql, $params)) {
      echo "Error recording intake: " . dbi_error();
  } else {
      audit_log('intake.updated', 'intake', (int) $edit_id, [
          'taken_time' => $taken_time,
          'note' => $note,
      ]);
  }
}
do_redirect($returnUrl);
?>
